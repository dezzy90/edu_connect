<?php

namespace App\Services\Realtime;

use App\Events\V2\MobileRealtimeEvent;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Models\V2\MobileMessage;
use App\Models\V2\MobileMessageRecipient;
use App\Models\V2\MobileNotification;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MobileRealtimeBroadcaster
{
    public function notificationCreated(MobileNotification $notification): void
    {
        $channels = $this->parentChannels(
            (int) $notification->parent_account_id,
            $notification->school_id ? (int) $notification->school_id : null,
            isset($notification->data['student_id']) ? (int) $notification->data['student_id'] : null,
        );

        $this->broadcast('mobile.notification.created', $channels, [
            'notification_id' => $notification->id,
            'parent_account_id' => $notification->parent_account_id,
            'tenant_id' => $notification->tenant_id,
            'school_id' => $notification->school_id,
            'type' => $notification->type,
            'priority' => $notification->priority,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'data' => $notification->data ?? [],
        ]);
    }

    public function notificationsChanged(ParentAccount $parent, ?int $schoolId = null): void
    {
        $this->broadcast('mobile.notifications.changed', $this->parentChannels((int) $parent->id, $schoolId), [
            'parent_account_id' => $parent->id,
            'school_id' => $schoolId,
        ]);
    }

    public function officialMessageRecipientCreated(MobileMessage $message, MobileMessageRecipient $recipient): void
    {
        if (! $recipient->parent_account_id) {
            return;
        }

        $channels = $this->parentChannels(
            (int) $recipient->parent_account_id,
            (int) $message->school_id,
            $recipient->student_id ? (int) $recipient->student_id : null,
        );

        $this->broadcast('mobile.message.published', $channels, [
            'mobile_message_id' => $message->id,
            'recipient_id' => $recipient->id,
            'parent_account_id' => $recipient->parent_account_id,
            'tenant_id' => $message->tenant_id,
            'school_id' => $message->school_id,
            'student_id' => $recipient->student_id,
            'category' => $message->category,
            'priority' => $message->priority,
            'published_at' => $message->published_at?->toIso8601String(),
        ]);
    }

    public function officialMessageRead(ParentAccount $parent, MobileMessage $message): void
    {
        $this->broadcast('mobile.message.read', $this->parentChannels((int) $parent->id, (int) $message->school_id), [
            'mobile_message_id' => $message->id,
            'parent_account_id' => $parent->id,
            'school_id' => $message->school_id,
        ]);
    }

    public function childLinked(ParentStudentLink $link, Collection $threads): void
    {
        $link->loadMissing(['parentAccount', 'student.school', 'student.class']);

        if (! $link->parentAccount || ! $link->student) {
            return;
        }

        $student = $link->student;
        $channels = $this->parentChannels(
            (int) $link->parentAccount->id,
            $student->school_id ? (int) $student->school_id : null,
            (int) $student->id,
        );
        $channels[] = "parent.{$link->parentAccount->id}.children";

        $this->broadcast('mobile.child.linked', $channels, [
            'parent_account_id' => $link->parentAccount->id,
            'link_id' => $link->id,
            'student_id' => $student->id,
            'school_id' => $student->school_id,
            'class_id' => $student->class_id,
            'threads' => $threads
                ->map(fn (ConversationThread $thread): array => [
                    'id' => $thread->id,
                    'type' => $thread->type,
                    'school_id' => $thread->school_id,
                    'class_id' => $thread->class_id,
                    'student_id' => $thread->student_id,
                    'realtime_channel' => $thread->realtimeChannel(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function attendanceRecorded(AttendanceEvent $event): void
    {
        $event->loadMissing(['student', 'device']);

        if (! $event->student || ! $event->student->isAvailableInMobile()) {
            return;
        }

        $links = ParentStudentLink::query()
            ->with('parentAccount')
            ->where('student_id', $event->student_id)
            ->where('status', 'active')
            ->whereNotNull('parent_account_id')
            ->get();

        foreach ($links as $link) {
            if (! $link->parentAccount || $link->parentAccount->status !== 'active') {
                continue;
            }

            $this->broadcast('mobile.attendance.recorded', $this->parentChannels(
                (int) $link->parentAccount->id,
                $event->school_id ? (int) $event->school_id : null,
                (int) $event->student_id,
            ), [
                'attendance_event_id' => $event->id,
                'event_key' => $event->event_key,
                'event_type' => $event->event_type,
                'event_time' => $event->event_time?->toIso8601String(),
                'parent_account_id' => $link->parentAccount->id,
                'tenant_id' => $event->tenant_id,
                'school_id' => $event->school_id,
                'student_id' => $event->student_id,
                'device_id' => $event->device_id,
            ]);
        }
    }

    public function conversationMessageCreated(ConversationThread $thread, ConversationMessage $message): void
    {
        $thread->loadMissing(['school', 'class', 'student']);

        $channels = collect([
            "conversation.{$thread->id}",
        ]);

        if ($thread->school_id) {
            $channels->push("school.{$thread->school_id}.admins.conversations");
        }

        if ($thread->type === ConversationThread::TYPE_CLASS_GROUP && $thread->class_id) {
            $channels->push("school.{$thread->school_id}.class.{$thread->class_id}.parents");
        }

        if ($thread->type === ConversationThread::TYPE_SCHOOL_CHANNEL) {
            $channels->push("school.{$thread->school_id}.channels");
        }

        $this->broadcast('mobile.conversation.message.created', $channels, [
            'thread' => [
                'id' => $thread->id,
                'type' => $thread->type,
                'title' => $thread->title,
                'status' => $thread->status,
                'tenant_id' => $thread->tenant_id,
                'school_id' => $thread->school_id,
                'class_id' => $thread->class_id,
                'student_id' => $thread->student_id,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
                'realtime_channel' => $thread->realtimeChannel(),
            ],
            'message' => [
                'id' => $message->id,
                'thread_id' => $message->thread_id,
                'sender_type' => $message->sender_type,
                'sender_id' => $message->sender_id,
                'sender_display_name' => $message->sender_display_name,
                'message_type' => $message->message_type,
                'body' => $message->body,
                'status' => $message->status,
                'sent_at' => $message->sent_at?->toIso8601String(),
            ],
        ]);
    }

    public function conversationThreadChanged(ConversationThread $thread): void
    {
        $channels = collect(["conversation.{$thread->id}"]);

        if ($thread->school_id) {
            $channels->push("school.{$thread->school_id}.admins.conversations");
        }

        $this->broadcast('mobile.conversation.thread.changed', $channels, [
            'thread_id' => $thread->id,
            'type' => $thread->type,
            'status' => $thread->status,
            'school_id' => $thread->school_id,
            'class_id' => $thread->class_id,
            'student_id' => $thread->student_id,
            'realtime_channel' => $thread->realtimeChannel(),
        ]);
    }

    /**
     * @param  iterable<int, string>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function broadcast(string $eventName, iterable $channels, array $payload): void
    {
        $channels = collect($channels)
            ->map(fn (string $channel): string => trim($channel))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($channels === []) {
            return;
        }

        $event = new MobileRealtimeEvent($eventName, $channels, $payload);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => event($event));

            return;
        }

        event($event);
    }

    /**
     * @return array<int, string>
     */
    private function parentChannels(int $parentId, ?int $schoolId = null, ?int $studentId = null): array
    {
        $channels = [
            "parent.{$parentId}",
            "parent.{$parentId}.notifications",
        ];

        if ($studentId) {
            $channels[] = "parent.{$parentId}.student.{$studentId}";
        }

        if ($schoolId) {
            $channels[] = "school.{$schoolId}.parent.{$parentId}";
        }

        return $channels;
    }
}
