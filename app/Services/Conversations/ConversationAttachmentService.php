<?php

namespace App\Services\Conversations;

use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationMessageAttachment;
use App\Models\V2\IntegrationConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

class ConversationAttachmentService
{
    public const MAX_SIZE_KILOBYTES = 15 * 1024;

    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif',
        'mp4', 'mov', 'm4v', 'webm', '3gp',
        'm4a', 'aac', 'mp3', 'wav', 'ogg', 'opus',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt',
    ];

    public function storeUploaded(UploadedFile $file, ConversationMessage $message): ConversationMessageAttachment
    {
        if ($file->getSize() > self::MAX_SIZE_KILOBYTES * 1024) {
            throw new RuntimeException('A conversation attachment cannot exceed 15 MB.');
        }

        return $this->persist(
            $message,
            $file->getClientOriginalName(),
            $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream',
            file_get_contents($file->getRealPath()) ?: '',
            []
        );
    }

    public function ingestRemote(array $source, ConversationMessage $message): ?ConversationMessageAttachment
    {
        $url = trim((string) ($source['transfer_url'] ?? ''));
        if ($url === '') {
            return null;
        }

        $this->assertTrustedTransferUrl($url, $message);

        $sourceId = (string) ($source['id'] ?? hash('sha256', $url));
        $existing = ConversationMessageAttachment::query()
            ->where('source_system', 'edu_admin')
            ->where('source_id', $sourceId)
            ->first();
        if ($existing) {
            return $existing;
        }

        $response = Http::accept('*/*')->timeout(30)->get($url);
        if ($response->failed()) {
            throw new RuntimeException('A conversation attachment could not be transferred from Edu-admin.');
        }

        $contents = $response->body();
        if (strlen($contents) > self::MAX_SIZE_KILOBYTES * 1024) {
            throw new RuntimeException('A transferred conversation attachment exceeds 15 MB.');
        }

        return $this->persist(
            $message,
            (string) ($source['name'] ?? 'attachment'),
            (string) ($source['mime_type'] ?? $response->header('Content-Type') ?? 'application/octet-stream'),
            $contents,
            [
                'source_system' => 'edu_admin',
                'source_id' => $sourceId,
                'kind' => $source['kind'] ?? null,
                'duration_ms' => $source['duration_ms'] ?? null,
            ]
        );
    }

    public function payload(ConversationMessageAttachment $attachment, bool $includeTransferUrl = false): array
    {
        $path = '/mobile/v2/conversation-attachments/'.$attachment->id;
        $payload = [
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'duration_ms' => $attachment->duration_ms,
            'url' => $path,
            'download_url' => $path.'?download=1',
        ];

        if ($includeTransferUrl) {
            $payload['transfer_url'] = URL::temporarySignedRoute(
                'conversation-attachments.transfer',
                now()->addMinutes(15),
                ['attachment' => $attachment->id]
            );
        }

        return $payload;
    }

    public function messageFallback(ConversationMessage $message): string
    {
        return match ($message->attachments->first()?->kind) {
            'image' => 'Photo',
            'video' => 'Video',
            'audio' => 'Voice message',
            'document' => 'Document',
            default => 'Message',
        };
    }

    private function persist(
        ConversationMessage $message,
        string $originalName,
        string $mimeType,
        string $contents,
        array $source
    ): ConversationMessageAttachment {
        if ($contents === '') {
            throw new RuntimeException('The conversation attachment is empty.');
        }

        $safeName = Str::limit(basename($originalName), 255, '');
        $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION) ?: $this->extensionForMime($mimeType));
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('This conversation attachment type is not allowed.');
        }

        $path = sprintf(
            'conversation-attachments/%d/%s/%s.%s',
            $message->thread_id,
            now()->format('Y/m'),
            Str::uuid(),
            $extension
        );
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('The conversation attachment could not be stored.');
        }

        try {
            return ConversationMessageAttachment::query()->create([
                'message_id' => $message->id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $safeName,
                'mime_type' => Str::before($mimeType, ';'),
                'kind' => $source['kind'] ?? $this->kindForMime($mimeType),
                'size_bytes' => strlen($contents),
                'duration_ms' => isset($source['duration_ms']) ? (int) $source['duration_ms'] : null,
                'source_system' => $source['source_system'] ?? 'local',
                'source_id' => $source['source_id'] ?? null,
                'sha256' => hash('sha256', $contents),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function kindForMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    private function extensionForMime(string $mime): string
    {
        return match (Str::before(strtolower($mime), ';')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/mpeg' => 'mp3',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function assertTrustedTransferUrl(string $url, ConversationMessage $message): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || ($scheme !== 'https' && ! app()->environment(['local', 'testing']))) {
            throw new RuntimeException('The conversation attachment transfer URL is not trusted.');
        }

        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $tenantId = $message->thread()->value('tenant_id');
        $allowedHosts = IntegrationConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'edu_admin')
            ->where('status', 'active')
            ->pluck('base_url')
            ->map(fn ($baseUrl) => strtolower((string) parse_url((string) $baseUrl, PHP_URL_HOST)))
            ->filter()
            ->unique();

        if (! $allowedHosts->contains($host)) {
            throw new RuntimeException('The conversation attachment transfer host is not trusted.');
        }
    }
}
