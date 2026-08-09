<?php

namespace App\Services\Integration;

use App\Contracts\EduAdminConnector;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationOutboxEvent;
use Illuminate\Support\Carbon;
use Throwable;

class AttendanceOutboxDispatcher
{
    private const OUTBOX_EVENT_TYPE = 'attendance.event.created';

    public function __construct(
        private readonly MappingService $mappings,
        private readonly IntegrationAuditLogger $audit,
    )
    {
    }

    public function enqueuePending(IntegrationConnection $connection, int $limit = 100): array
    {
        $result = [
            'queued' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $events = AttendanceEvent::query()
            ->with(['student.class', 'device'])
            ->where('tenant_id', $connection->tenant_id)
            ->where('edu_admin_sync_status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            $payload = $this->payloadFor($connection, $event);

            if (!$payload) {
                $result['failed']++;
                continue;
            }

            $outbox = IntegrationOutboxEvent::query()->firstOrNew([
                'event_key' => $event->event_key,
            ]);

            if ($outbox->exists && $outbox->status === 'sent') {
                $this->markAttendanceSynced($event, $outbox->sent_at ?? now());
                $result['skipped']++;
                continue;
            }

            $outbox->forceFill([
                'connection_id' => $connection->id,
                'event_type' => self::OUTBOX_EVENT_TYPE,
                'payload' => $payload,
                'status' => 'pending',
                'available_at' => $outbox->available_at ?? now(),
                'last_error' => null,
            ])->save();

            $event->forceFill([
                'edu_admin_sync_status' => 'queued',
                'edu_admin_error' => null,
            ])->save();

            $result['queued']++;
        }

        if (array_sum($result) > 0) {
            $this->audit->attendanceOutbox(
                $connection,
                'attendance.outbox.enqueued',
                $result,
                sprintf(
                    'Attendance outbox enqueue completed: queued=%d skipped=%d failed=%d.',
                    $result['queued'],
                    $result['skipped'],
                    $result['failed'],
                ),
                $result['failed'] > 0 ? 'warning' : 'info',
                $result['failed'] > 0 ? 'partial' : 'completed',
            );
        }

        return $result;
    }

    public function dispatchPending(
        IntegrationConnection $connection,
        EduAdminConnector $connector,
        int $limit = 50
    ): array {
        $result = [
            'sent' => 0,
            'duplicates' => 0,
            'failed' => 0,
        ];

        $outboxEvents = IntegrationOutboxEvent::query()
            ->where('connection_id', $connection->id)
            ->where('event_type', self::OUTBOX_EVENT_TYPE)
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', $this->maxAttempts())
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($outboxEvents->isEmpty()) {
            return $result;
        }

        try {
            $response = $connector->pushAttendanceEvents(
                $outboxEvents
                    ->pluck('payload')
                    ->values()
                    ->all()
            );
        } catch (Throwable $exception) {
            foreach ($outboxEvents as $outbox) {
                $this->markOutboxFailed($outbox, $exception->getMessage());
                $result['failed']++;
            }

            $connection->forceFill([
                'last_failed_sync_at' => now(),
                'last_error' => $exception->getMessage(),
            ])->save();

            $this->audit->attendanceOutbox(
                $connection,
                'attendance.outbox.failed',
                array_merge($result, [
                    'error_message' => $exception->getMessage(),
                ]),
                'Attendance outbox dispatch failed: ' . $exception->getMessage(),
                'error',
                'failed',
                $outboxEvents->first(),
            );

            throw $exception;
        }

        $accepted = collect($response['accepted'] ?? [])->map(fn ($key) => (string) $key)->all();
        $duplicates = collect($response['duplicates'] ?? [])->map(fn ($key) => (string) $key)->all();
        $rejected = collect($response['rejected'] ?? [])
            ->filter(fn ($item) => is_array($item) && isset($item['event_key']))
            ->keyBy(fn ($item) => (string) $item['event_key']);

        foreach ($outboxEvents as $outbox) {
            $eventKey = (string) $outbox->event_key;

            if (in_array($eventKey, $accepted, true)) {
                $this->markOutboxSent($outbox);
                $result['sent']++;
                continue;
            }

            if (in_array($eventKey, $duplicates, true)) {
                $this->markOutboxSent($outbox);
                $result['duplicates']++;
                continue;
            }

            $rejection = $rejected->get($eventKey);
            $message = $rejection
                ? ($rejection['message'] ?? $rejection['reason'] ?? 'Rejected by Edu-admin.')
                : 'Edu-admin did not acknowledge this attendance event.';

            $this->markOutboxFailed($outbox, $message);
            $result['failed']++;
        }

        if ($result['failed'] > 0) {
            $connection->forceFill([
                'last_failed_sync_at' => now(),
                'last_error' => "{$result['failed']} attendance event(s) failed to push.",
            ])->save();
        } else {
            $connection->forceFill([
                'last_successful_sync_at' => now(),
                'last_error' => null,
            ])->save();
        }

        $this->audit->attendanceOutbox(
            $connection,
            'attendance.outbox.dispatched',
            $result,
            sprintf(
                'Attendance outbox dispatch completed: sent=%d duplicates=%d failed=%d.',
                $result['sent'],
                $result['duplicates'],
                $result['failed'],
            ),
            $result['failed'] > 0 ? 'warning' : 'info',
            $result['failed'] > 0 ? 'partial' : 'completed',
            $outboxEvents->first(),
        );

        return $result;
    }

    private function payloadFor(IntegrationConnection $connection, AttendanceEvent $event): ?array
    {
        if (!$event->student_id || !$event->student) {
            $this->markAttendanceFailed($event, 'Attendance event has no linked student.');

            return null;
        }

        if (!$event->device) {
            $this->markAttendanceFailed($event, 'Attendance event has no linked biometric device.');

            return null;
        }

        $schoolId = $this->mappings->findExternalId($connection, 'school', (int) $event->school_id);
        $studentId = $this->mappings->findExternalId($connection, 'student', (int) $event->student_id);
        $classId = $event->student->class_id
            ? $this->mappings->findExternalId($connection, 'class', (int) $event->student->class_id)
            : null;

        if (!$schoolId) {
            $this->markAttendanceFailed($event, 'Missing Edu-admin school mapping.');

            return null;
        }

        if (!$studentId) {
            $this->markAttendanceFailed($event, 'Missing Edu-admin student mapping.');

            return null;
        }

        return [
            'local_event_id' => $event->id,
            'event_key' => $event->event_key,
            'school_id' => (int) $schoolId,
            'student_id' => (int) $studentId,
            'class_id' => $classId ? (int) $classId : null,
            'device_uid' => $event->device->device_uid,
            'event_type' => $event->event_type,
            'event_time' => $event->event_time?->toIso8601String(),
            'confidence_score' => $event->confidence_score !== null ? (float) $event->confidence_score : null,
            'raw_payload' => $event->raw_payload,
        ];
    }

    private function markOutboxSent(IntegrationOutboxEvent $outbox): void
    {
        $sentAt = now();

        $outbox->forceFill([
            'status' => 'sent',
            'attempts' => $outbox->attempts + 1,
            'sent_at' => $sentAt,
            'last_error' => null,
        ])->save();

        $localEventId = $outbox->payload['local_event_id'] ?? null;

        if ($localEventId) {
            AttendanceEvent::query()
                ->whereKey($localEventId)
                ->each(fn (AttendanceEvent $event) => $this->markAttendanceSynced($event, $sentAt));
        }
    }

    private function markOutboxFailed(IntegrationOutboxEvent $outbox, string $message): void
    {
        $outbox->forceFill([
            'status' => 'failed',
            'attempts' => $outbox->attempts + 1,
            'available_at' => now()->addMinutes($this->retryMinutes()),
            'last_error' => $message,
        ])->save();

        $localEventId = $outbox->payload['local_event_id'] ?? null;

        if ($localEventId) {
            AttendanceEvent::query()
                ->whereKey($localEventId)
                ->update([
                    'edu_admin_sync_status' => 'failed',
                    'edu_admin_error' => $message,
                    'updated_at' => now(),
                ]);
        }
    }

    private function markAttendanceSynced(AttendanceEvent $event, Carbon $syncedAt): void
    {
        $event->forceFill([
            'edu_admin_sync_status' => 'synced',
            'edu_admin_synced_at' => $syncedAt,
            'edu_admin_error' => null,
        ])->save();
    }

    private function markAttendanceFailed(AttendanceEvent $event, string $message): void
    {
        $event->forceFill([
            'edu_admin_sync_status' => 'failed',
            'edu_admin_error' => $message,
        ])->save();
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('integrations.sync.outbox_max_attempts', 5));
    }

    private function retryMinutes(): int
    {
        return max(1, (int) config('integrations.sync.outbox_retry_minutes', 5));
    }
}
