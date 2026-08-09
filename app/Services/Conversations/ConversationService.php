<?php

namespace App\Services\Conversations;

use App\Models\AdminUser;
use App\Models\V2\AcademicClass;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationMessageReceipt;
use App\Models\V2\ConversationParticipant;
use App\Models\V2\ConversationThread;
use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationPreference;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public const DIRECT_DESKS = [
        'general' => 'General office',
        'principal' => 'Principal',
        'class_teacher' => 'Class teacher',
        'accounts' => 'Accounts office',
        'discipline' => 'Discipline office',
    ];

    public function __construct(private readonly MobileRealtimeBroadcaster $realtime) {}

    public function scopeVisibleToParent(Builder $query, ParentAccount $parent): void
    {
        $context = $this->parentContext($parent);

        $query->where(function (Builder $visible) use ($context, $parent): void {
            $visible
                ->where(function (Builder $direct) use ($context): void {
                    $direct->where('type', ConversationThread::TYPE_DIRECT)
                        ->whereIn('student_id', $context['student_ids']);
                })
                ->orWhere(function (Builder $groups) use ($context): void {
                    $groups->where('type', ConversationThread::TYPE_CLASS_GROUP)
                        ->whereIn('class_id', $context['class_ids']);
                })
                ->orWhere(function (Builder $channels) use ($context): void {
                    $channels->where('type', ConversationThread::TYPE_SCHOOL_CHANNEL)
                        ->whereIn('school_id', $context['school_ids']);
                })
                ->orWhereHas('participants', fn (Builder $participants) => $participants
                    ->where('participant_type', ConversationParticipant::TYPE_PARENT)
                    ->where('participant_id', $parent->id)
                    ->whereNull('left_at'));
        });
    }

    public function scopeVisibleToAdmin(Builder $query, AdminUser $admin): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        $query->whereIn('school_id', $this->schoolIdsForAdmin($admin)->all());
    }

    public function ensureParentCanAccessThread(ParentAccount $parent, ConversationThread $thread): void
    {
        $query = ConversationThread::query()->whereKey($thread->id);
        $this->scopeVisibleToParent($query, $parent);

        if (! $query->exists()) {
            abort(404);
        }
    }

    public function ensureParentCanPost(ParentAccount $parent, ConversationThread $thread): void
    {
        $this->ensureParentCanAccessThread($parent, $thread);

        if ($thread->status !== 'open') {
            abort(403, 'This conversation is closed.');
        }

        $metadata = $thread->metadata ?? [];

        if ($thread->type === ConversationThread::TYPE_SCHOOL_CHANNEL && ($metadata['parents_can_post'] ?? false) !== true) {
            abort(403, 'Only school administrators can post in this channel.');
        }
    }

    public function ensureAdminCanAccessThread(AdminUser $admin, ConversationThread $thread): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        if (! $this->schoolIdsForAdmin($admin)->contains((int) $thread->school_id)) {
            abort(403, 'You cannot access this conversation.');
        }
    }

    public function ensureAdminCanAccessSchool(AdminUser $admin, School $school): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        if (! $this->schoolIdsForAdmin($admin)->contains((int) $school->id)) {
            abort(403, 'You cannot manage conversations for this school.');
        }
    }

    public function ensureAdminCanAccessClass(AdminUser $admin, AcademicClass $class): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        if (! $this->schoolIdsForAdmin($admin)->contains((int) $class->school_id)) {
            abort(403, 'You cannot manage conversations for this class.');
        }
    }

    public function ensureDefaultThreadsForParent(ParentAccount $parent): Collection
    {
        return ParentStudentLink::query()
            ->with(['student.class', 'student.school'])
            ->where('parent_account_id', $parent->id)
            ->where('status', 'active')
            ->whereHas('student', fn (Builder $query) => $query
                ->where('mobile_visible', true)
                ->whereIn('status', Student::MOBILE_VISIBLE_STATUSES))
            ->get()
            ->flatMap(fn (ParentStudentLink $link) => $this->ensureDefaultThreadsForLink($link))
            ->unique('id')
            ->values();
    }

    public function ensureDefaultThreadsForLink(ParentStudentLink $link): Collection
    {
        $link->loadMissing(['parentAccount', 'student.class', 'student.school']);

        if (! $link->parentAccount instanceof ParentAccount || ! $link->student instanceof Student) {
            return collect();
        }

        $threads = collect();

        if ($link->student->class instanceof AcademicClass) {
            $threads->push($this->findOrCreateClassGroup($link->student->class));
        }

        if ($link->student->school instanceof School) {
            $threads->push($this->findOrCreateSchoolChannel($link->student->school));
        }

        return $threads->each(fn (ConversationThread $thread) => $this->ensureParentParticipant($thread, $link->parentAccount));
    }

    public function startDirectThread(ParentAccount $parent, Student $student, ?string $subject = null, ?string $desk = null): ConversationThread
    {
        $this->ensureParentCanAccessStudent($parent, $student);
        $desk = $this->normalizeDirectDesk($desk);

        $existing = ConversationThread::query()
            ->where('type', ConversationThread::TYPE_DIRECT)
            ->where('student_id', $student->id)
            ->whereHas('participants', fn (Builder $participants) => $participants
                ->where('participant_type', ConversationParticipant::TYPE_PARENT)
                ->where('participant_id', $parent->id)
                ->whereNull('left_at'))
            ->get()
            ->first(fn (ConversationThread $thread): bool => ($thread->metadata['desk'] ?? 'general') === $desk);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($parent, $student, $subject, $desk): ConversationThread {
            $deskLabel = self::DIRECT_DESKS[$desk];

            $thread = ConversationThread::query()->create([
                'tenant_id' => $student->tenant_id,
                'school_id' => $student->school_id,
                'student_id' => $student->id,
                'type' => ConversationThread::TYPE_DIRECT,
                'title' => $subject ?: "{$deskLabel} - {$student->full_name}",
                'status' => 'open',
                'source_system' => 'local',
                'source_id' => "direct:parent:{$parent->id}:student:{$student->id}:desk:{$desk}",
                'created_by_type' => ConversationParticipant::TYPE_PARENT,
                'created_by_id' => $parent->id,
                'metadata' => [
                    'mode' => 'parent_admin',
                    'desk' => $desk,
                    'desk_label' => $deskLabel,
                ],
            ]);

            $this->ensureParentParticipant($thread, $parent);
            $this->syncAdminParticipants($thread);

            return $thread->refresh();
        });
    }

    public function findOrCreateClassGroup(AcademicClass $class, ?AdminUser $admin = null, ?string $title = null): ConversationThread
    {
        $thread = ConversationThread::query()
            ->where('type', ConversationThread::TYPE_CLASS_GROUP)
            ->where('class_id', $class->id)
            ->where('status', 'open')
            ->first();

        if (! $thread) {
            $thread = ConversationThread::query()->create([
                'tenant_id' => $class->tenant_id,
                'school_id' => $class->school_id,
                'class_id' => $class->id,
                'type' => ConversationThread::TYPE_CLASS_GROUP,
                'title' => $title ?: "{$class->full_name} Parents",
                'status' => 'open',
                'source_system' => 'local',
                'source_id' => "class_group:{$class->id}",
                'created_by_type' => ConversationParticipant::TYPE_SYSTEM,
                'created_by_id' => null,
                'metadata' => [
                    'parents_can_post' => true,
                    'moderated_by' => 'school_admins',
                    'system_managed' => true,
                ],
            ]);
        }

        if ($admin) {
            $this->ensureAdminParticipant($thread, $admin);
        }

        $this->syncAdminParticipants($thread);
        $this->syncParentParticipants($thread);

        return $thread->refresh();
    }

    public function findOrCreateSchoolChannel(School $school): ConversationThread
    {
        $thread = ConversationThread::query()
            ->where('type', ConversationThread::TYPE_SCHOOL_CHANNEL)
            ->where('school_id', $school->id)
            ->where('status', 'open')
            ->first();

        if (! $thread) {
            $thread = ConversationThread::query()->create([
                'tenant_id' => $school->tenant_id,
                'school_id' => $school->id,
                'type' => ConversationThread::TYPE_SCHOOL_CHANNEL,
                'title' => "{$school->name} Channel",
                'status' => 'open',
                'source_system' => 'local',
                'source_id' => "school_channel:{$school->id}",
                'created_by_type' => ConversationParticipant::TYPE_SYSTEM,
                'created_by_id' => null,
                'metadata' => [
                    'description' => 'General school information from administrators.',
                    'parents_can_post' => false,
                    'mode' => 'school_forum',
                    'system_managed' => true,
                ],
            ]);
        }

        $this->syncAdminParticipants($thread);
        $this->syncParentParticipants($thread);

        return $thread->refresh();
    }

    public function postParentMessage(ParentAccount $parent, ConversationThread $thread, string $body): ConversationMessage
    {
        $this->ensureParentCanPost($parent, $thread);

        return DB::transaction(function () use ($parent, $thread, $body): ConversationMessage {
            $participant = $this->ensureParentParticipant($thread, $parent);

            return $this->createMessage(
                $thread,
                ConversationMessage::SENDER_PARENT,
                $parent->id,
                $parent->full_name ?: $parent->phone,
                $body,
                $participant
            );
        });
    }

    public function postAdminMessage(AdminUser $admin, ConversationThread $thread, string $body): ConversationMessage
    {
        $this->ensureAdminCanAccessThread($admin, $thread);

        if ($thread->status !== 'open') {
            abort(403, 'This conversation is closed.');
        }

        return DB::transaction(function () use ($admin, $thread, $body): ConversationMessage {
            $participant = $this->ensureAdminParticipant($thread, $admin);

            if (in_array($thread->type, [ConversationThread::TYPE_CLASS_GROUP, ConversationThread::TYPE_SCHOOL_CHANNEL], true)) {
                $this->syncParentParticipants($thread);
            }

            return $this->createMessage(
                $thread,
                ConversationMessage::SENDER_ADMIN,
                $admin->id,
                $admin->name,
                $body,
                $participant
            );
        });
    }

    public function markReadForParent(ParentAccount $parent, ConversationThread $thread): int
    {
        $this->ensureParentCanAccessThread($parent, $thread);

        $participant = $this->ensureParentParticipant($thread, $parent);
        $latestMessageId = (int) ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->max('id');

        if ($latestMessageId > 0) {
            $participant->forceFill(['last_read_message_id' => $latestMessageId])->save();
        }

        return ConversationMessageReceipt::query()
            ->where('participant_id', $participant->id)
            ->whereNull('read_at')
            ->update([
                'delivered_at' => DB::raw('COALESCE(delivered_at, CURRENT_TIMESTAMP)'),
                'read_at' => now(),
            ]);
    }

    public function markReadForAdmin(AdminUser $admin, ConversationThread $thread): int
    {
        $this->ensureAdminCanAccessThread($admin, $thread);

        $participant = $this->ensureAdminParticipant($thread, $admin);
        $latestMessageId = (int) ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->max('id');

        if ($latestMessageId > 0) {
            $participant->forceFill(['last_read_message_id' => $latestMessageId])->save();
        }

        return ConversationMessageReceipt::query()
            ->where('participant_id', $participant->id)
            ->whereNull('read_at')
            ->update([
                'delivered_at' => DB::raw('COALESCE(delivered_at, CURRENT_TIMESTAMP)'),
                'read_at' => now(),
            ]);
    }

    public function unreadCountForParent(ParentAccount $parent, ConversationThread $thread): int
    {
        $participant = ConversationParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('participant_type', ConversationParticipant::TYPE_PARENT)
            ->where('participant_id', $parent->id)
            ->whereNull('left_at')
            ->first();

        $query = ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->where(function (Builder $sender) use ($parent): void {
                $sender->where('sender_type', '!=', ConversationMessage::SENDER_PARENT)
                    ->orWhere('sender_id', '!=', $parent->id);
            });

        if ($participant?->last_read_message_id) {
            $query->where('id', '>', $participant->last_read_message_id);
        }

        return $query->count();
    }

    public function unreadCountForAdmin(AdminUser $admin, ConversationThread $thread): int
    {
        $this->ensureAdminCanAccessThread($admin, $thread);

        $participant = ConversationParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('participant_type', ConversationParticipant::TYPE_ADMIN)
            ->where('participant_id', $admin->id)
            ->whereNull('left_at')
            ->first();

        $query = ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->where(function (Builder $sender) use ($admin): void {
                $sender->where('sender_type', '!=', ConversationMessage::SENDER_ADMIN)
                    ->orWhere('sender_id', '!=', $admin->id);
            });

        if ($participant?->last_read_message_id) {
            $query->where('id', '>', $participant->last_read_message_id);
        }

        return $query->count();
    }

    public function ensureParentParticipant(ConversationThread $thread, ParentAccount $parent): ConversationParticipant
    {
        return ConversationParticipant::query()->firstOrCreate(
            [
                'thread_id' => $thread->id,
                'participant_type' => ConversationParticipant::TYPE_PARENT,
                'participant_id' => $parent->id,
            ],
            [
                'display_name' => $parent->full_name ?: $parent->phone,
                'role' => 'parent',
                'joined_at' => now(),
            ]
        );
    }

    public function ensureAdminParticipant(ConversationThread $thread, AdminUser $admin): ConversationParticipant
    {
        return ConversationParticipant::query()->firstOrCreate(
            [
                'thread_id' => $thread->id,
                'participant_type' => ConversationParticipant::TYPE_ADMIN,
                'participant_id' => $admin->id,
            ],
            [
                'display_name' => $admin->name,
                'role' => $admin->role,
                'joined_at' => now(),
            ]
        );
    }

    /**
     * @return Collection<int, int>
     */
    public function schoolIdsForAdmin(AdminUser $admin): Collection
    {
        if ($admin->isSuperAdmin()) {
            return School::query()->pluck('id')->map(fn ($id) => (int) $id);
        }

        if (! $admin->school_id) {
            return collect();
        }

        return School::query()
            ->where(function (Builder $schools) use ($admin): void {
                $schools->where('id', $admin->school_id)
                    ->orWhere(function (Builder $legacy) use ($admin): void {
                        $legacy->where('source_system', 'legacy')
                            ->where('source_id', (string) $admin->school_id);
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function ensureParentCanAccessStudent(ParentAccount $parent, Student $student): void
    {
        $allowed = ParentStudentLink::query()
            ->where('parent_account_id', $parent->id)
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->whereHas('student', fn (Builder $query) => $query
                ->where('mobile_visible', true)
                ->whereIn('status', Student::MOBILE_VISIBLE_STATUSES))
            ->exists();

        if (! $allowed) {
            abort(404);
        }
    }

    private function normalizeDirectDesk(?string $desk): string
    {
        $desk = strtolower(trim((string) $desk));

        return array_key_exists($desk, self::DIRECT_DESKS) ? $desk : 'general';
    }

    private function syncAdminParticipants(ConversationThread $thread): void
    {
        $thread->loadMissing('school');

        $schoolIds = collect([(int) $thread->school_id]);

        if ($thread->school?->source_system === 'legacy' && $thread->school->source_id) {
            $schoolIds->push((int) $thread->school->source_id);
        }

        AdminUser::query()
            ->active()
            ->where('role', 'school_admin')
            ->whereIn('school_id', $schoolIds->unique()->values()->all())
            ->get()
            ->each(fn (AdminUser $admin) => $this->ensureAdminParticipant($thread, $admin));
    }

    private function syncParentParticipants(ConversationThread $thread): void
    {
        $query = ParentStudentLink::query()
            ->with('parentAccount')
            ->where('tenant_id', $thread->tenant_id)
            ->where('school_id', $thread->school_id)
            ->where('status', 'active')
            ->whereNotNull('parent_account_id')
            ->whereHas('student', fn (Builder $student) => $student
                ->where('mobile_visible', true)
                ->whereIn('status', Student::MOBILE_VISIBLE_STATUSES));

        if ($thread->type === ConversationThread::TYPE_CLASS_GROUP) {
            $query->whereHas('student', fn (Builder $student) => $student->where('class_id', $thread->class_id));
        }

        $query->get()
            ->pluck('parentAccount')
            ->filter(fn ($parent) => $parent instanceof ParentAccount && $parent->status === 'active')
            ->unique('id')
            ->each(fn (ParentAccount $parent) => $this->ensureParentParticipant($thread, $parent));
    }

    private function createMessage(
        ConversationThread $thread,
        string $senderType,
        ?int $senderId,
        string $senderDisplayName,
        string $body,
        ConversationParticipant $senderParticipant
    ): ConversationMessage {
        $message = ConversationMessage::query()->create([
            'thread_id' => $thread->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sender_display_name' => $senderDisplayName,
            'message_type' => 'text',
            'body' => $body,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $thread->forceFill(['last_message_at' => $message->sent_at])->save();
        $this->createReceipts($thread, $message, $senderParticipant);
        $this->notifyParentParticipants($thread->refresh(), $message, $senderType, $senderId);
        $this->realtime->conversationMessageCreated($thread->refresh(), $message);

        return $message->refresh();
    }

    private function createReceipts(
        ConversationThread $thread,
        ConversationMessage $message,
        ConversationParticipant $senderParticipant
    ): void {
        $participants = ConversationParticipant::query()
            ->where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->get();

        foreach ($participants as $participant) {
            ConversationMessageReceipt::query()->create([
                'message_id' => $message->id,
                'participant_id' => $participant->id,
                'delivered_at' => $participant->id === $senderParticipant->id ? now() : null,
                'read_at' => $participant->id === $senderParticipant->id ? now() : null,
            ]);
        }

        $senderParticipant->forceFill(['last_read_message_id' => $message->id])->save();
    }

    private function notifyParentParticipants(
        ConversationThread $thread,
        ConversationMessage $message,
        string $senderType,
        ?int $senderId
    ): void {
        $thread->loadMissing(['school', 'class', 'student']);

        ConversationParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('participant_type', ConversationParticipant::TYPE_PARENT)
            ->whereNull('left_at')
            ->get()
            ->each(function (ConversationParticipant $participant) use ($thread, $message, $senderType, $senderId): void {
                if ($senderType === ConversationMessage::SENDER_PARENT && (int) $senderId === (int) $participant->participant_id) {
                    return;
                }

                $parent = ParentAccount::query()->find($participant->participant_id);

                if (! $parent || $parent->status !== 'active') {
                    return;
                }

                $preferences = $this->preferences($parent, 'messages');

                if (! $preferences['in_app_enabled'] && ! $preferences['push_enabled']) {
                    return;
                }

                MobileNotification::query()->create([
                    'parent_account_id' => $parent->id,
                    'tenant_id' => $thread->tenant_id,
                    'school_id' => $thread->school_id,
                    'type' => 'messages',
                    'title' => $thread->title,
                    'body' => config('educonnect.notifications.privacy_mode', 'discreet') === 'discreet'
                        ? 'You have a new conversation message.'
                        : (string) $message->body,
                    'data' => [
                        'conversation_thread_id' => $thread->id,
                        'conversation_message_id' => $message->id,
                        'conversation_type' => $thread->type,
                        'school_id' => $thread->school_id,
                        'class_id' => $thread->class_id,
                        'student_id' => $thread->student_id,
                        'realtime_channel' => $thread->realtimeChannel(),
                    ],
                    'priority' => 'normal',
                    'channel' => $this->channel($preferences),
                    'delivery_status' => 'queued',
                ]);
            });
    }

    /**
     * @return array{student_ids: array<int, int>, class_ids: array<int, int>, school_ids: array<int, int>}
     */
    private function parentContext(ParentAccount $parent): array
    {
        $links = ParentStudentLink::query()
            ->with('student:id,school_id,class_id,status,mobile_visible')
            ->where('parent_account_id', $parent->id)
            ->where('status', 'active')
            ->whereHas('student', fn (Builder $query) => $query
                ->where('mobile_visible', true)
                ->whereIn('status', Student::MOBILE_VISIBLE_STATUSES))
            ->get();

        return [
            'student_ids' => $links->pluck('student_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'class_ids' => $links->pluck('student.class_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'school_ids' => $links->pluck('school_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
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
}
