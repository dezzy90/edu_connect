<?php

namespace App\Services\Integration;

use App\Models\V2\IntegrationAuditEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationOutboxEvent;
use App\Models\V2\IntegrationSyncRun;
use Illuminate\Database\Eloquent\Model;

class IntegrationAuditLogger
{
    public function record(
        ?IntegrationConnection $connection,
        string $category,
        string $eventType,
        string $summary,
        array $metadata = [],
        string $severity = 'info',
        ?string $status = null,
        ?Model $actor = null,
        ?Model $related = null,
    ): IntegrationAuditEvent {
        return IntegrationAuditEvent::query()->create([
            'tenant_id' => $connection?->tenant_id,
            'connection_id' => $connection?->id,
            'category' => $category,
            'event_type' => $eventType,
            'severity' => $severity,
            'status' => $status,
            'summary' => $summary,
            'metadata' => $this->redact($metadata),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'occurred_at' => now(),
        ]);
    }

    public function syncCompleted(IntegrationSyncRun $run): void
    {
        $run->loadMissing('connection');
        $connection = $run->connection;

        $this->record(
            $connection,
            'sync',
            "sync.{$run->sync_type}.completed",
            sprintf(
                '%s sync completed: read=%d created=%d updated=%d failed=%d.',
                ucfirst($run->sync_type),
                $run->records_read,
                $run->records_created,
                $run->records_updated,
                $run->records_failed,
            ),
            [
                'sync_run_id' => $run->id,
                'sync_type' => $run->sync_type,
                'direction' => $run->direction,
                'records_read' => $run->records_read,
                'records_created' => $run->records_created,
                'records_updated' => $run->records_updated,
                'records_deleted' => $run->records_deleted,
                'records_failed' => $run->records_failed,
                'resource_summary' => $this->resourceSummary($run),
                'cursor_after' => $run->cursor_after,
            ],
            $run->records_failed > 0 ? 'warning' : 'info',
            $run->status,
            related: $run,
        );

        $messageSummary = $this->resourceSummary($run)['mobile_message'] ?? null;

        if ($messageSummary) {
            $total = array_sum($messageSummary);

            $this->record(
                $connection,
                'messages',
                'messages.ingested',
                sprintf('Official mobile message sync processed %d record(s).', $total),
                [
                    'sync_run_id' => $run->id,
                    'resource' => 'mobile_messages',
                    'summary' => $messageSummary,
                ],
                ($messageSummary['failed'] ?? 0) > 0 ? 'warning' : 'info',
                $run->status,
                related: $run,
            );
        }
    }

    public function syncFailed(IntegrationSyncRun $run, string $message): void
    {
        $run->loadMissing('connection');

        $this->record(
            $run->connection,
            'sync',
            "sync.{$run->sync_type}.failed",
            ucfirst($run->sync_type) . ' sync failed: ' . $message,
            [
                'sync_run_id' => $run->id,
                'sync_type' => $run->sync_type,
                'direction' => $run->direction,
                'records_read' => $run->records_read,
                'records_created' => $run->records_created,
                'records_updated' => $run->records_updated,
                'records_failed' => $run->records_failed,
                'error_message' => $message,
            ],
            'error',
            'failed',
            related: $run,
        );
    }

    public function attendanceOutbox(
        IntegrationConnection $connection,
        string $eventType,
        array $stats,
        string $summary,
        string $severity = 'info',
        ?string $status = null,
        ?IntegrationOutboxEvent $related = null,
    ): void {
        $this->record(
            $connection,
            'outbox',
            $eventType,
            $summary,
            $stats,
            $severity,
            $status,
            related: $related,
        );
    }

    private function resourceSummary(IntegrationSyncRun $run): array
    {
        return $run->items()
            ->selectRaw('external_type, action, status, count(*) as total')
            ->groupBy('external_type', 'action', 'status')
            ->get()
            ->reduce(function (array $summary, $row): array {
                $resource = (string) ($row->external_type ?: 'unknown');
                $key = $row->status === 'failed' ? 'failed' : (string) $row->action;

                $summary[$resource][$key] = ($summary[$resource][$key] ?? 0) + (int) $row->total;

                return $summary;
            }, []);
    }

    private function redact(array $metadata): array
    {
        $blockedKeys = [
            'access_token',
            'refresh_token',
            'webhook_secret',
            'token',
            'secret',
            'password',
        ];

        return collect($metadata)
            ->map(function ($value, string $key) use ($blockedKeys) {
                if (in_array(strtolower($key), $blockedKeys, true)) {
                    return '[redacted]';
                }

                if (is_array($value)) {
                    return $this->redact($value);
                }

                return $value;
            })
            ->all();
    }
}
