<?php

namespace App\Services\Notifications;

use App\Models\V2\AttendanceEvent;
use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationPreference;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;

class AttendanceNotificationService
{
    public function notify(AttendanceEvent $event): array
    {
        $stats = [
            'notifications_created' => 0,
            'skipped' => 0,
        ];

        $event->loadMissing(['student', 'device']);

        if (!$event->student || !$event->student->isAvailableInMobile()) {
            $stats['skipped']++;
            return $stats;
        }

        ParentStudentLink::query()
            ->with('parentAccount')
            ->where('student_id', $event->student_id)
            ->where('status', 'active')
            ->whereNotNull('parent_account_id')
            ->get()
            ->each(function (ParentStudentLink $link) use ($event, &$stats): void {
                $parent = $link->parentAccount;

                if (!$parent || $parent->status !== 'active') {
                    $stats['skipped']++;
                    return;
                }

                $preferences = $this->preferences($parent, 'attendance');

                if (!$preferences['in_app_enabled'] && !$preferences['push_enabled']) {
                    $stats['skipped']++;
                    return;
                }

                $payload = $this->payload($event, $parent, $preferences);

                MobileNotification::query()->create($payload);
                $stats['notifications_created']++;
            });

        return $stats;
    }

    private function payload(AttendanceEvent $event, ParentAccount $parent, array $preferences): array
    {
        $student = $event->student;
        $schoolId = $event->school_id ?: $student?->school_id;
        $isDiscreet = config('educonnect.notifications.privacy_mode', 'discreet') === 'discreet';
        $eventLabel = $this->eventLabel($event->event_type);

        return [
            'parent_account_id' => $parent->id,
            'tenant_id' => $event->tenant_id,
            'school_id' => $schoolId,
            'type' => 'attendance',
            'title' => $isDiscreet ? 'Attendance update' : "{$student->full_name} {$eventLabel}",
            'body' => $isDiscreet
                ? 'A student attendance event was recorded.'
                : "{$student->full_name} {$eventLabel} at " . $event->event_time?->format('H:i') . '.',
            'data' => [
                'attendance_event_id' => $event->id,
                'event_key' => $event->event_key,
                'event_type' => $event->event_type,
                'event_time' => $event->event_time?->toIso8601String(),
                'student_id' => $event->student_id,
                'school_id' => $schoolId,
                'device_id' => $event->device_id,
                'device_name' => $event->device?->name,
            ],
            'priority' => $event->event_type === 'check_in' ? 'normal' : 'normal',
            'channel' => $this->channel($preferences),
            'delivery_status' => 'queued',
        ];
    }

    private function preferences(ParentAccount $parent, string $category): array
    {
        $preference = NotificationPreference::query()
            ->where('parent_account_id', $parent->id)
            ->where('category', $category)
            ->first();

        return [
            'in_app_enabled' => $preference?->in_app_enabled ?? true,
            'push_enabled' => $preference?->push_enabled ?? true,
        ];
    }

    private function channel(array $preferences): string
    {
        if ($preferences['in_app_enabled'] && $preferences['push_enabled']) {
            return 'in_app_push';
        }

        if ($preferences['push_enabled']) {
            return 'push';
        }

        return 'in_app';
    }

    private function eventLabel(string $eventType): string
    {
        return match ($eventType) {
            'check_in' => 'arrived at school',
            'check_out' => 'left school',
            default => str_replace('_', ' ', $eventType),
        };
    }
}
