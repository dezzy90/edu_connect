<?php

namespace App\Services\Integration\Connectors;

use App\Contracts\EduAdminConnector;
use App\Models\V2\IntegrationConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class HttpEduAdminConnector implements EduAdminConnector
{
    public function __construct(private readonly IntegrationConnection $connection)
    {
    }

    public function bootstrap(): array
    {
        return $this->get('/api/v1/integrations/edu-connect/bootstrap');
    }

    public function resource(string $resource, ?string $cursor = null, array $filters = []): array
    {
        return $this->get("/api/v1/integrations/edu-connect/resources/{$resource}", array_merge($filters, [
            'cursor' => $cursor,
            'limit' => config('integrations.sync.default_batch_size', 250),
        ]));
    }

    public function pushAttendanceEvents(array $events): array
    {
        return $this->post('/api/v1/integrations/edu-connect/attendance-events', [
            'events' => $events,
        ], [
            'Idempotency-Key' => $this->idempotencyKey($events),
        ]);
    }

    public function pushConversationMessage(array $message): array
    {
        return $this->post('/api/v1/integrations/edu-connect/conversation-messages', $message, [
            'Idempotency-Key' => 'conversation-message:' . hash('sha256', (string) ($message['event_key'] ?? json_encode($message))),
        ]);
    }

    private function get(string $path, array $query = []): array
    {
        $response = Http::acceptJson()
            ->withToken($this->accessToken())
            ->timeout((int) config('integrations.providers.edu_admin.timeout_seconds', 15))
            ->retry((int) config('integrations.providers.edu_admin.retry_attempts', 3), 250, null, false)
            ->get($this->url($path), array_filter($query, fn ($value) => $value !== null));

        return $this->decode($response);
    }

    private function post(string $path, array $payload, array $headers = []): array
    {
        $body = $this->jsonBody($payload);
        $headers = array_merge($headers, $this->signatureHeaders($body));

        $response = Http::acceptJson()
            ->withHeaders($headers)
            ->withToken($this->accessToken())
            ->withBody($body, 'application/json')
            ->timeout((int) config('integrations.providers.edu_admin.timeout_seconds', 15))
            ->retry((int) config('integrations.providers.edu_admin.retry_attempts', 3), 250, null, false)
            ->post($this->url($path));

        return $this->decode($response);
    }

    private function signatureHeaders(string $body): array
    {
        $timestamp = (string) now()->timestamp;

        return [
            'X-Edu-Connect-Timestamp' => $timestamp,
            'X-Edu-Connect-Signature' => 'sha256=' . hash_hmac(
                'sha256',
                $timestamp . '.' . $body,
                $this->webhookSecret()
            ),
        ];
    }

    private function jsonBody(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Edu-admin connector payload could not be encoded as JSON.', previous: $exception);
        }
    }

    private function idempotencyKey(array $events): string
    {
        $eventKeys = collect($events)
            ->pluck('event_key')
            ->filter()
            ->sort()
            ->values()
            ->implode('|');

        return 'attendance:' . hash('sha256', $eventKeys);
    }

    private function accessToken(): string
    {
        if (blank($this->connection->encrypted_access_token)) {
            throw new RuntimeException('Edu-admin connector access token is missing.');
        }

        try {
            return Crypt::decryptString($this->connection->encrypted_access_token);
        } catch (DecryptException $exception) {
            throw new RuntimeException('Edu-admin connector access token could not be decrypted.', previous: $exception);
        }
    }

    private function webhookSecret(): string
    {
        if (blank($this->connection->webhook_secret)) {
            throw new RuntimeException('Edu-admin connector webhook secret is missing.');
        }

        try {
            return Crypt::decryptString($this->connection->webhook_secret);
        } catch (DecryptException $exception) {
            throw new RuntimeException('Edu-admin connector webhook secret could not be decrypted.', previous: $exception);
        }
    }

    private function url(string $path): string
    {
        if (blank($this->connection->base_url)) {
            throw new RuntimeException('Edu-admin connector base URL is missing.');
        }

        return rtrim($this->connection->base_url, '/') . '/' . ltrim($path, '/');
    }

    private function decode(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Edu-admin connector request failed with status %d: %s',
                $response->status(),
                Str::limit($response->body(), 500)
            ));
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            throw new RuntimeException('Edu-admin connector returned an invalid JSON payload.');
        }

        return $payload;
    }
}
