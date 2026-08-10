<?php

namespace App\Http\Controllers\Api\V2\Integration;

use App\Http\Controllers\Controller;
use App\Models\V2\IntegrationConnection;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use App\Services\Notifications\MobileMessagePublisher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EduAdminWebhookController extends Controller
{
    public function mobileMessagePublished(
        Request $request,
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync,
        MobileMessagePublisher $publisher
    ): JsonResponse {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'max:80'],
            'complex_id' => ['required', 'integer'],
            'message_id' => ['required', 'integer', 'min:1'],
            'school_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:40'],
            'sent_at' => ['nullable', 'date'],
            'updated_at' => ['nullable', 'date'],
        ]);

        /** @var IntegrationConnection|null $connection */
        $connection = $request->attributes->get('edu_admin_integration_connection');

        if (! $connection instanceof IntegrationConnection) {
            return response()->json([
                'status' => 'error',
                'message' => 'Edu-admin webhook connection was not resolved.',
            ], 404);
        }

        $sourceMessageId = (int) $validated['message_id'];
        $updatedAfter = $this->updatedAfterWindow($validated);
        $run = $sync->runIncrementalSync($connection, $connectors->make($connection), [
            'updated_after' => $updatedAfter,
            'resources' => ['mobile_messages'],
            'metadata' => [
                'source' => 'edu_admin_webhook',
                'event_type' => $validated['event_type'],
                'source_message_id' => $sourceMessageId,
                'updated_after_window' => $updatedAfter,
            ],
        ]);

        $published = $publisher->publishDue(
            max(1, (int) config('integrations.webhooks.edu_admin.mobile_message_publish_limit', 25))
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'connection_id' => $connection->id,
                'source_message_id' => $sourceMessageId,
                'sync_run' => [
                    'id' => $run->id,
                    'status' => $run->status,
                    'records_read' => $run->records_read,
                    'records_created' => $run->records_created,
                    'records_updated' => $run->records_updated,
                    'records_failed' => $run->records_failed,
                ],
                'published' => $published,
            ],
        ]);
    }

    private function updatedAfterWindow(array $payload): string
    {
        $timestamp = $payload['updated_at'] ?? $payload['sent_at'] ?? null;

        if (! $timestamp) {
            return '1970-01-01T00:00:00Z';
        }

        return Carbon::parse($timestamp)
            ->subMinute()
            ->toIso8601String();
    }
}
