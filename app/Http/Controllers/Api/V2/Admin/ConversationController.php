<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Services\Conversations\ConversationService;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request, ConversationService $conversations): JsonResponse
    {
        $admin = $this->admin($request);

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
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ConversationThread::query()
            ->with(['school', 'class', 'student'])
            ->withCount(['participants', 'messages']);

        $conversations->scopeVisibleToAdmin($query, $admin);

        foreach (['type', 'school_id', 'class_id', 'student_id', 'status'] as $filter) {
            if (array_key_exists($filter, $validated) && $validated[$filter] !== null) {
                $query->where($filter, $validated[$filter]);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $query
                    ->orderByDesc('last_message_at')
                    ->latest()
                    ->limit((int) ($validated['limit'] ?? 50))
                    ->get()
                    ->map(fn (ConversationThread $thread) => $this->threadPayload($thread))
                    ->values(),
            ],
        ]);
    }

    public function show(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        $admin = $this->admin($request);
        $conversations->ensureAdminCanAccessThread($admin, $thread);

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'integer'],
        ]);

        $messages = ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->when(! empty($validated['before_id']), fn (Builder $query) => $query->where('id', '<', (int) $validated['before_id']))
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->threadPayload($thread->load(['school', 'class', 'student'])->loadCount(['participants', 'messages'])),
                'messages' => $messages->map(fn (ConversationMessage $message) => $this->messagePayload($message, $admin))->values(),
            ],
        ]);
    }

    public function postMessage(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = $conversations->postAdminMessage($this->admin($request), $thread, trim($validated['body']));

        return response()->json([
            'status' => 'success',
            'data' => [
                'message' => $this->messagePayload($message, $this->admin($request)),
                'thread' => $this->threadPayload($thread->refresh()->load(['school', 'class', 'student'])->loadCount(['participants', 'messages'])),
            ],
        ], 201);
    }

    public function markRead(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'marked_read' => $conversations->markReadForAdmin($this->admin($request), $thread),
            ],
        ]);
    }

    public function updateStatus(
        Request $request,
        ConversationThread $thread,
        ConversationService $conversations,
        MobileRealtimeBroadcaster $realtime
    ): JsonResponse {
        $conversations->ensureAdminCanAccessThread($this->admin($request), $thread);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'closed', 'archived'])],
        ]);

        $thread->forceFill(['status' => $validated['status']])->save();
        $realtime->conversationThreadChanged($thread->refresh());

        return response()->json([
            'status' => 'success',
            'data' => $this->threadPayload($thread->refresh()->load(['school', 'class', 'student'])->loadCount(['participants', 'messages'])),
        ]);
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return $admin;
    }

    private function threadPayload(ConversationThread $thread): array
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
            'last_message' => $lastMessage ? $this->messagePayload($lastMessage) : null,
        ];
    }

    private function messagePayload(ConversationMessage $message, ?AdminUser $admin = null): array
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
            'own_message' => $admin
                ? $message->sender_type === ConversationMessage::SENDER_ADMIN && (int) $message->sender_id === (int) $admin->id
                : false,
        ];
    }
}
