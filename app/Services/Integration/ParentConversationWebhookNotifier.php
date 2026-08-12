<?php

namespace App\Services\Integration;

use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\ParentAccount;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ParentConversationWebhookNotifier
{
    public function __construct(
        private readonly EduAdminConnectorFactory $connectors,
        private readonly IntegrationAuditLogger $audit,
    ) {
    }

    public function notify(ConversationMessage $message, ParentAccount $parent): void
    {
        $message->loadMissing(['thread.school', 'thread.class', 'thread.student']);

        $thread = $message->thread;

        if (!$thread instanceof ConversationThread) {
            return;
        }

        $payload = $this->payload($message, $thread, $parent);

        if (!$payload) {
            return;
        }

        IntegrationConnection::query()
            ->where('tenant_id', $thread->tenant_id)
            ->where('provider', 'edu_admin')
            ->where('mode', 'connected')
            ->where('status', 'active')
            ->get()
            ->each(function (IntegrationConnection $connection) use ($payload, $message): void {
                try {
                    $response = $this->connectors->make($connection, ['driver' => 'http'])->pushConversationMessage($payload);

                    $connection->forceFill([
                        'last_successful_sync_at' => now(),
                        'last_error' => null,
                    ])->save();

                    $this->audit->record(
                        $connection,
                        'messages',
                        'messages.parent_conversation.pushed',
                        'Parent conversation message pushed to Edu-admin.',
                        [
                            'conversation_message_id' => $message->id,
                            'conversation_thread_id' => $message->thread_id,
                            'edu_admin_response' => $response,
                        ],
                        related: $message,
                    );
                } catch (Throwable $exception) {
                    report($exception);

                    $connection->forceFill([
                        'last_failed_sync_at' => now(),
                        'last_error' => $exception->getMessage(),
                    ])->save();

                    $this->audit->record(
                        $connection,
                        'messages',
                        'messages.parent_conversation.push_failed',
                        'Parent conversation message could not be pushed to Edu-admin: ' . $exception->getMessage(),
                        [
                            'conversation_message_id' => $message->id,
                            'conversation_thread_id' => $message->thread_id,
                            'error_message' => $exception->getMessage(),
                        ],
                        'warning',
                        'failed',
                        related: $message,
                    );
                }
            });
    }

    private function payload(ConversationMessage $message, ConversationThread $thread, ParentAccount $parent): ?array
    {
        $schoolId = $this->externalId($thread->school);

        if (!$schoolId) {
            return null;
        }

        return array_filter([
            'event_key' => "educonnect:conversation_message:{$message->id}",
            'message_id' => (string) $message->id,
            'thread_id' => (string) $thread->id,
            'thread_type' => $thread->type,
            'school_id' => $schoolId,
            'class_id' => $this->externalId($thread->class),
            'student_id' => $this->externalId($thread->student),
            'parent_id' => (string) $parent->id,
            'parent_name' => $parent->full_name ?: $parent->phone,
            'parent_phone' => $parent->phone,
            'body' => $message->body,
            'sent_at' => $message->sent_at?->toIso8601String() ?: now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function externalId(?Model $model): ?int
    {
        if (!$model || blank($model->source_id ?? null)) {
            return null;
        }

        $sourceId = (string) $model->source_id;

        return ctype_digit($sourceId) ? (int) $sourceId : null;
    }
}
