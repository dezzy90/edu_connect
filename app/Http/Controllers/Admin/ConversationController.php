<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Services\Conversations\ConversationService;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ConversationController extends Controller
{
    public function index(ConversationService $conversations): InertiaResponse
    {
        $admin = $this->admin()->load('school');
        $threads = $this->visibleThreadQuery($admin, $conversations)
            ->where('status', '!=', 'archived')
            ->limit(80)
            ->get();

        $selectedThread = $threads->first();
        $messages = $selectedThread
            ? $this->messagesForThread($selectedThread)
            : collect();

        return Inertia::render('Admin/Conversations/Index', [
            'admin' => $admin,
            'isSuper' => $admin->isSuperAdmin(),
            'csrfToken' => csrf_token(),
            'threads' => $threads->map(fn (ConversationThread $thread) => $this->threadPayload($thread, $conversations, $admin))->values(),
            'selectedThread' => $selectedThread
                ? $this->threadPayload($selectedThread, $conversations, $admin)
                : null,
            'messages' => $messages->map(fn (ConversationMessage $message) => $this->messagePayload($message, $admin))->values(),
            'realtime' => $this->realtimeConfig($admin, $conversations, $threads),
        ]);
    }

    public function listThreads(Request $request, ConversationService $conversations): JsonResponse
    {
        $admin = $this->admin();
        $validated = $request->validate([
            'type' => ['nullable', Rule::in([
                ConversationThread::TYPE_DIRECT,
                ConversationThread::TYPE_CLASS_GROUP,
                ConversationThread::TYPE_SCHOOL_CHANNEL,
            ])],
            'school_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['open', 'closed', 'archived'])],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleThreadQuery($admin, $conversations);

        if (empty($validated['status'])) {
            $query->where('status', '!=', 'archived');
        }

        foreach (['type', 'school_id', 'class_id', 'student_id', 'status'] as $filter) {
            if (array_key_exists($filter, $validated) && $validated[$filter] !== null) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['search'])) {
            $search = '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['search']).'%';
            $query->where(function (Builder $threads) use ($search): void {
                $threads->where('title', 'like', $search)
                    ->orWhereHas('student', fn (Builder $student) => $student
                        ->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('student_number', 'like', $search))
                    ->orWhereHas('school', fn (Builder $school) => $school->where('name', 'like', $search))
                    ->orWhereHas('class', fn (Builder $class) => $class
                        ->where('name', 'like', $search)
                        ->orWhere('full_name', 'like', $search));
            });
        }

        $threads = $query
            ->limit((int) ($validated['limit'] ?? 80))
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $threads->map(fn (ConversationThread $thread) => $this->threadPayload($thread, $conversations, $admin))->values(),
                'realtime_channels' => $this->allowedInitialChannels($admin, $conversations, $threads)->values(),
            ],
        ]);
    }

    public function show(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        $admin = $this->admin();
        $conversations->ensureAdminCanAccessThread($admin, $thread);

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'integer'],
        ]);

        $messages = $this->messagesForThread($thread, (int) ($validated['limit'] ?? 60), $validated['before_id'] ?? null);

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->threadPayload($this->threadForPayload($thread), $conversations, $admin),
                'messages' => $messages->map(fn (ConversationMessage $message) => $this->messagePayload($message, $admin))->values(),
            ],
        ]);
    }

    public function postMessage(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $admin = $this->admin();
        $message = $conversations->postAdminMessage($admin, $thread, trim($validated['body']));

        return response()->json([
            'status' => 'success',
            'data' => [
                'message' => $this->messagePayload($message, $admin),
                'thread' => $this->threadPayload($this->threadForPayload($thread->refresh()), $conversations, $admin),
            ],
        ], 201);
    }

    public function markRead(ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'marked_read' => $conversations->markReadForAdmin($this->admin(), $thread),
            ],
        ]);
    }

    public function updateStatus(
        Request $request,
        ConversationThread $thread,
        ConversationService $conversations,
        MobileRealtimeBroadcaster $realtime
    ): JsonResponse {
        $admin = $this->admin();
        $conversations->ensureAdminCanAccessThread($admin, $thread);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'closed', 'archived'])],
        ]);

        $thread->forceFill(['status' => $validated['status']])->save();
        $thread = $this->threadForPayload($thread->refresh());
        $realtime->conversationThreadChanged($thread);

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->threadPayload($thread, $conversations, $admin),
            ],
        ]);
    }

    public function authorizeRealtime(Request $request, ConversationService $conversations): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:120'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        $admin = $this->admin();
        $channelName = $validated['channel_name'];

        if (! $this->canAccessRealtimeChannel($admin, $conversations, $channelName)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This admin account cannot access the requested realtime channel.',
            ], 403);
        }

        $auth = $this->pusherStyleAuth($validated['socket_id'], $channelName);

        return response()->json([
            'auth' => $auth,
            'status' => 'success',
            'data' => [
                'authorized' => true,
                'channel_name' => $channelName,
                'socket_id' => $validated['socket_id'],
                'auth' => $auth,
            ],
        ]);
    }

    private function realtimeConfig(AdminUser $admin, ConversationService $conversations, Collection $threads): array
    {
        return [
            'enabled' => (bool) config('educonnect.realtime.enabled'),
            'driver' => config('educonnect.realtime.driver'),
            'authEndpoint' => '/admin/conversations/realtime/auth',
            'channels' => $this->allowedInitialChannels($admin, $conversations, $threads)->values(),
            'connection' => [
                'key' => config('educonnect.realtime.app_key'),
                'host' => config('educonnect.realtime.host'),
                'port' => config('educonnect.realtime.port'),
                'scheme' => config('educonnect.realtime.scheme'),
            ],
        ];
    }

    private function allowedInitialChannels(AdminUser $admin, ConversationService $conversations, Collection $threads): Collection
    {
        return $conversations->schoolIdsForAdmin($admin)
            ->map(fn (int $schoolId): string => "private-school.{$schoolId}.admins.conversations")
            ->merge($threads->map(fn (ConversationThread $thread): string => $thread->realtimeChannel()))
            ->filter()
            ->unique()
            ->values();
    }

    private function canAccessRealtimeChannel(AdminUser $admin, ConversationService $conversations, string $channelName): bool
    {
        if (preg_match('/^private-school\.(\d+)\.admins\.conversations$/', $channelName, $matches) === 1) {
            return $conversations->schoolIdsForAdmin($admin)->contains((int) $matches[1]);
        }

        if (preg_match('/^private-conversation\.(\d+)$/', $channelName, $matches) === 1) {
            $query = ConversationThread::query()->whereKey((int) $matches[1]);
            $conversations->scopeVisibleToAdmin($query, $admin);

            return $query->exists();
        }

        return false;
    }

    private function visibleThreadQuery(AdminUser $admin, ConversationService $conversations): Builder
    {
        $query = ConversationThread::query()
            ->with(['school', 'class', 'student'])
            ->withCount(['participants', 'messages'])
            ->orderByDesc('last_message_at')
            ->latest();

        $conversations->scopeVisibleToAdmin($query, $admin);

        return $query;
    }

    private function threadForPayload(ConversationThread $thread): ConversationThread
    {
        return $thread->load(['school', 'class', 'student'])->loadCount(['participants', 'messages']);
    }

    private function messagesForThread(ConversationThread $thread, int $limit = 60, mixed $beforeId = null): Collection
    {
        return ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->when(! empty($beforeId), fn (Builder $query) => $query->where('id', '<', (int) $beforeId))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    private function threadPayload(ConversationThread $thread, ConversationService $conversations, AdminUser $admin): array
    {
        $lastMessage = ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->latest('id')
            ->first();

        return [
            'id' => $thread->id,
            'tenant_id' => $thread->tenant_id,
            'school_id' => $thread->school_id,
            'class_id' => $thread->class_id,
            'student_id' => $thread->student_id,
            'type' => $thread->type,
            'title' => $thread->title,
            'status' => $thread->status,
            'metadata' => $thread->metadata ?? [],
            'can_post' => $thread->status === 'open',
            'unread_count' => $conversations->unreadCountForAdmin($admin, $thread),
            'participants_count' => $thread->participants_count ?? null,
            'messages_count' => $thread->messages_count ?? null,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'realtime_channel' => $thread->realtimeChannel(),
            'school' => $thread->school ? [
                'id' => $thread->school->id,
                'name' => $thread->school->name,
                'code' => $thread->school->code,
            ] : null,
            'class' => $thread->class ? [
                'id' => $thread->class->id,
                'name' => $thread->class->name,
                'full_name' => $thread->class->full_name,
            ] : null,
            'student' => $thread->student ? [
                'id' => $thread->student->id,
                'student_number' => $thread->student->student_number,
                'full_name' => $thread->student->full_name,
            ] : null,
            'last_message' => $lastMessage ? $this->messagePayload($lastMessage, $admin) : null,
        ];
    }

    private function messagePayload(ConversationMessage $message, AdminUser $admin): array
    {
        return [
            'id' => $message->id,
            'thread_id' => $message->thread_id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'sender_display_name' => $message->sender_display_name,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'status' => $message->status,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'own_message' => $message->sender_type === ConversationMessage::SENDER_ADMIN
                && (int) $message->sender_id === (int) $admin->id,
        ];
    }

    private function pusherStyleAuth(string $socketId, string $channelName): ?string
    {
        $appKey = config('educonnect.realtime.app_key');
        $secret = config('educonnect.realtime.app_secret');

        if (! $appKey || ! $secret) {
            return null;
        }

        return $appKey.':'.hash_hmac('sha256', "{$socketId}:{$channelName}", $secret);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
