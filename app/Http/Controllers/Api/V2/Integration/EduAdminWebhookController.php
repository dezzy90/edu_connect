<?php

namespace App\Http\Controllers\Api\V2\Integration;

use App\Http\Controllers\Controller;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationInboxEvent;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use App\Services\Notifications\MobileMessagePublisher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class EduAdminWebhookController extends Controller
{
    private const RESOURCES = [
        'schools',
        'academic_years',
        'sections',
        'education_options',
        'streams',
        'classes',
        'students',
        'parent_links',
        'mobile_messages',
        'student_mobile_profiles',
    ];

    public function resourceChanged(
        Request $request,
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync,
        MobileMessagePublisher $publisher,
    ): JsonResponse {
        $validated = $request->validate([
            'event_key' => ['required', 'string', 'max:100'],
            'event_type' => ['required', 'in:edu_admin.resource_changed'],
            'complex_id' => ['required', 'integer', 'min:1'],
            'resource' => ['required', 'in:'.implode(',', self::RESOURCES)],
            'action' => ['required', 'in:created,updated,deleted,restored'],
            'resource_ids' => ['required', 'array', 'min:1', 'max:100'],
            'resource_ids.*' => ['integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '' || ! hash_equals($validated['event_key'], $idempotencyKey)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid idempotency key.'], 422);
        }

        /** @var IntegrationConnection|null $connection */
        $connection = $request->attributes->get('edu_admin_integration_connection');

        if (! $connection instanceof IntegrationConnection) {
            return response()->json(['status' => 'error', 'message' => 'Edu-admin webhook connection was not resolved.'], 404);
        }

        [$event, $claimed] = DB::transaction(function () use ($connection, $validated): array {
            $event = IntegrationInboxEvent::query()->firstOrCreate(
                ['event_key' => $validated['event_key']],
                [
                    'connection_id' => $connection->id,
                    'event_type' => $validated['event_type'],
                    'payload' => $validated,
                    'status' => 'pending',
                ]
            );

            $event = IntegrationInboxEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($event->status === 'processed') {
                return [$event, false];
            }

            if ($event->status === 'processing' && $event->updated_at?->isAfter(now()->subMinutes(5))) {
                return [$event, false];
            }

            $event->forceFill(['status' => 'processing', 'error_message' => null])->save();

            return [$event, true];
        });

        if ($event->processed_at) {
            return response()->json([
                'status' => 'success',
                'data' => ['event_key' => $event->event_key, 'duplicate' => true],
            ]);
        }

        if (! $claimed) {
            return response()->json([
                'status' => 'processing',
                'data' => ['event_key' => $event->event_key, 'duplicate' => true],
            ], 202);
        }

        try {
            $stats = $validated['action'] === 'deleted'
                ? $sync->applyResourceDeletion($connection, $validated['resource'], $validated['resource_ids'])
                : $this->pullChangedRecords($connection, $validated, $connectors, $sync);

            if ($validated['resource'] === 'mobile_messages' && $validated['action'] !== 'deleted') {
                $stats['published'] = $publisher->publishDue(
                    max(1, (int) config('integrations.webhooks.edu_admin.mobile_message_publish_limit', 25))
                );
            }

            $event->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'error_message' => null,
                'payload' => array_merge($validated, ['result' => $stats]),
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'error_message' => str($exception->getMessage())->limit(2000)->toString(),
            ])->save();

            throw $exception;
        }

        return response()->json([
            'status' => 'success',
            'data' => ['event_key' => $event->event_key, 'duplicate' => false, 'result' => $stats],
        ]);
    }

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

    private function pullChangedRecords(
        IntegrationConnection $connection,
        array $payload,
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync,
    ): array {
        $connector = $connectors->make($connection);
        $cursor = null;
        $stats = ['read' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0];
        $seenIds = [];

        do {
            $page = $connector->resource($payload['resource'], $cursor, ['ids' => $payload['resource_ids']]);
            $records = $page['data'] ?? [];
            $seenIds = array_merge($seenIds, collect($records)->pluck('id')->map(fn ($id) => (int) $id)->all());
            $pageStats = $sync->importResourceRecords($connection, $payload['resource'], $records);

            foreach (array_keys($stats) as $key) {
                $stats[$key] += (int) ($pageStats[$key] ?? 0);
            }

            $cursor = $page['next_cursor'] ?? null;
        } while (($page['has_more'] ?? false) && $cursor);

        $missingIds = array_values(array_diff($payload['resource_ids'], $seenIds));

        if ($missingIds !== []) {
            $stats['missing_records'] = $sync->applyResourceDeletion($connection, $payload['resource'], $missingIds);
        }

        if ($stats['failed'] > 0) {
            throw new RuntimeException(sprintf(
                'Targeted EduAdmin sync for %s was incomplete: requested=%d read=%d failed=%d.',
                $payload['resource'],
                count($payload['resource_ids']),
                $stats['read'],
                $stats['failed'],
            ));
        }

        return $stats;
    }
}
