<?php

namespace App\Services\Notifications;

use App\Models\V2\MobileMessage;
use App\Models\V2\MobileMessageRecipient;
use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationPreference;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\Student;
use App\Models\V2\AcademicClass;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Models\V2\School;
use App\Services\Conversations\ConversationService;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MobileMessagePublisher
{
    public function __construct(
        private readonly MobileRealtimeBroadcaster $realtime,
        private readonly ConversationService $conversations,
    ) {}

    public function publish(MobileMessage $message): array
    {
        $stats = [
            'recipients_created' => 0,
            'notifications_created' => 0,
            'skipped' => 0,
        ];

        if (! $message->isParentVisible()) {
            $this->clearParentDeliveries($message);
            $stats['skipped']++;

            return $stats;
        }

        if (! $this->isPublishable($message)) {
            $stats['skipped']++;

            return $stats;
        }

        $mirroredToConversation = $this->mirrorToConversation($message);

        if ($mirroredToConversation) {
            $this->clearParentDeliveries($message);

            return $stats;
        }

        $this->recipientTargets($message)
            ->each(function (array $target) use ($message, &$stats): void {
                /** @var ParentAccount $parent */
                $parent = $target['parent'];

                if ($parent->status !== 'active') {
                    $stats['skipped']++;

                    return;
                }

                $recipient = $this->firstOrCreateRecipient($message, $target);

                if ($recipient->wasRecentlyCreated) {
                    $stats['recipients_created']++;
                    $this->realtime->officialMessageRecipientCreated($message, $recipient);
                }

                if ($this->notificationAlreadyExists($message, $parent)) {
                    return;
                }

                $preferences = $this->preferences($parent, 'messages');

                if (! $preferences['in_app_enabled'] && ! $preferences['push_enabled']) {
                    return;
                }

                MobileNotification::query()->create([
                    'parent_account_id' => $parent->id,
                    'tenant_id' => $message->tenant_id,
                    'school_id' => $message->school_id,
                    'type' => 'messages',
                    'title' => $message->title,
                    'body' => config('educonnect.notifications.privacy_mode', 'discreet') === 'discreet'
                        ? 'You have a new school message.'
                        : $message->body,
                    'data' => [
                        'mobile_message_id' => $message->id,
                        'recipient_id' => $recipient->id,
                        'category' => $message->category,
                        'student_id' => $recipient->student_id,
                    ],
                    'priority' => $message->priority,
                    'channel' => $this->channel($preferences),
                    'delivery_status' => 'queued',
                    'expires_at' => $message->expires_at,
                ]);

                $stats['notifications_created']++;
            });

        return $stats;
    }

    public function publishDue(int $limit = 50): array
    {
        $stats = [
            'messages' => 0,
            'recipients_created' => 0,
            'notifications_created' => 0,
            'skipped' => 0,
        ];

        MobileMessage::query()
            ->publishedVisible()
            ->oldest('published_at')
            ->limit($limit)
            ->get()
            ->each(function (MobileMessage $message) use (&$stats): void {
                $stats['messages']++;
                $published = $this->publish($message);
                $stats['recipients_created'] += $published['recipients_created'];
                $stats['notifications_created'] += $published['notifications_created'];
                $stats['skipped'] += $published['skipped'];
            });

        return $stats;
    }

    private function isPublishable(MobileMessage $message): bool
    {
        if ($message->status !== 'published') {
            return false;
        }

        if ($message->published_at && $message->published_at->isFuture()) {
            return false;
        }

        if ($message->expires_at && $message->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    private function clearParentDeliveries(MobileMessage $message): void
    {
        MobileMessageRecipient::query()
            ->where('message_id', $message->id)
            ->delete();

        MobileNotification::query()
            ->where('type', 'messages')
            ->where('data->mobile_message_id', $message->id)
            ->delete();
    }

    private function recipientTargets(MobileMessage $message): Collection
    {
        $query = ParentStudentLink::query()
            ->with(['parentAccount', 'student'])
            ->where('tenant_id', $message->tenant_id)
            ->where('school_id', $message->school_id)
            ->where('status', 'active')
            ->whereNotNull('parent_account_id')
            ->whereHas('student', fn (Builder $student) => $student
                ->where('mobile_visible', true)
                ->whereIn('status', Student::MOBILE_VISIBLE_STATUSES));

        $filters = $message->audience_filters ?? [];

        match ($message->audience_type) {
            'students', 'linked_students' => $query->whereIn('student_id', $this->filterIds($filters, 'student_ids')),
            'classes', 'class' => $query->whereHas('student', fn (Builder $student) => $student->whereIn('class_id', $this->filterIds($filters, 'class_ids'))),
            'phones', 'parent_phones' => $query->where(function (Builder $query) use ($filters) {
                $phones = $this->filterStrings($filters, 'parent_phones');

                $query
                    ->whereIn('parent_phone', $phones)
                    ->orWhereHas('parentAccount', fn (Builder $parent) => $parent->whereIn('phone', $phones));
            }),
            default => null,
        };

        return $query
            ->get()
            ->map(fn (ParentStudentLink $link) => [
                'parent' => $link->parentAccount,
                'student_id' => in_array($message->audience_type, ['students', 'linked_students', 'classes', 'class'], true)
                    ? $link->student_id
                    : null,
                'recipient_phone' => $link->parentAccount?->phone ?? $link->parent_phone,
            ])
            ->filter(fn (array $target) => $target['parent'] instanceof ParentAccount)
            ->unique(fn (array $target) => $target['parent']->id.':'.($target['student_id'] ?? 'all'))
            ->values();
    }

    private function firstOrCreateRecipient(MobileMessage $message, array $target): MobileMessageRecipient
    {
        $query = MobileMessageRecipient::query()
            ->where('message_id', $message->id)
            ->where('parent_account_id', $target['parent']->id);

        if ($target['student_id']) {
            $query->where('student_id', $target['student_id']);
        } else {
            $query->whereNull('student_id');
        }

        $existing = $query->first();

        if ($existing) {
            return $existing;
        }

        return MobileMessageRecipient::query()->create([
            'message_id' => $message->id,
            'parent_account_id' => $target['parent']->id,
            'student_id' => $target['student_id'],
            'recipient_phone' => $target['recipient_phone'],
            'delivery_status' => 'queued',
        ]);
    }

    private function notificationAlreadyExists(MobileMessage $message, ParentAccount $parent): bool
    {
        return MobileNotification::query()
            ->where('parent_account_id', $parent->id)
            ->where('type', 'messages')
            ->where('data->mobile_message_id', $message->id)
            ->exists();
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

    private function filterIds(array $filters, string $key): array
    {
        return collect($filters[$key] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
    }

    private function mirrorToConversation(MobileMessage $message): bool
    {
        $threads = collect();
        $filters = $message->audience_filters ?? [];

        if (in_array($message->audience_type, ['classes', 'class'], true)) {
            $classIds = $this->filterIds($filters, 'class_ids');

            $threads = AcademicClass::query()
                ->where('tenant_id', $message->tenant_id)
                ->where('school_id', $message->school_id)
                ->whereIn('id', $classIds)
                ->get()
                ->map(fn (AcademicClass $class) => $this->conversations->findOrCreateClassGroup($class));
        } elseif (blank($message->audience_type) || in_array($message->audience_type, ['parents', 'all', 'school', 'general'], true)) {
            $school = School::query()
                ->where('tenant_id', $message->tenant_id)
                ->find($message->school_id);

            if ($school) {
                $threads->push($this->conversations->findOrCreateSchoolChannel($school));
            }
        }

        $threads = $threads
            ->filter(fn ($thread) => $thread instanceof ConversationThread)
            ->unique('id')
            ->values();

        foreach ($threads as $thread) {
            if ($this->conversationMirrorExists($thread, $message)) {
                continue;
            }

            $this->conversations->postSystemMessage(
                $thread,
                $this->conversationBody($message),
                $message->sender_name ?: 'School administration',
                [
                    'source' => 'official_mobile_message',
                    'mobile_message_id' => $message->id,
                    'mobile_message_source_system' => $message->source_system,
                    'mobile_message_source_id' => $message->source_id,
                    'category' => $message->category,
                    'priority' => $message->priority,
                ]
            );
        }

        return $threads->isNotEmpty();
    }

    private function conversationMirrorExists(ConversationThread $thread, MobileMessage $message): bool
    {
        return ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->where('sender_type', ConversationMessage::SENDER_SYSTEM)
            ->where('metadata->source', 'official_mobile_message')
            ->where('metadata->mobile_message_id', $message->id)
            ->exists();
    }

    private function conversationBody(MobileMessage $message): string
    {
        $title = trim((string) $message->title);
        $body = trim((string) $message->body);

        if ($title === '') {
            return $body;
        }

        if ($body === '') {
            return $title;
        }

        return "{$title}\n\n{$body}";
    }

    private function filterStrings(array $filters, string $key): array
    {
        return collect($filters[$key] ?? [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }
}
