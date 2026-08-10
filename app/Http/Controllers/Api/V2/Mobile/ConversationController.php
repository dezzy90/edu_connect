<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationMessageReceipt;
use App\Models\V2\ConversationThread;
use App\Models\V2\ParentAccount;
use App\Models\V2\Student;
use App\Services\Conversations\ConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request, ConversationService $conversations): JsonResponse
    {
        $parent = $this->parent($request);
        $conversations->ensureDefaultThreadsForParent($parent);

        $validated = $request->validate([
            'type' => ['nullable', Rule::in([
                ConversationThread::TYPE_DIRECT,
                ConversationThread::TYPE_CLASS_GROUP,
                ConversationThread::TYPE_SCHOOL_CHANNEL,
            ])],
            'school_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ConversationThread::query()
            ->with(['school', 'class', 'student'])
            ->withCount(['participants', 'messages'])
            ->where('status', '!=', 'archived');

        $conversations->scopeVisibleToParent($query, $parent);

        foreach (['type', 'school_id', 'class_id', 'student_id'] as $filter) {
            if (array_key_exists($filter, $validated) && $validated[$filter] !== null) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $items = $query
            ->orderByDesc('last_message_at')
            ->latest()
            ->limit((int) ($validated['limit'] ?? 30))
            ->get()
            ->map(function (ConversationThread $thread) use ($conversations, $parent): array {
                $conversations->ensureParentParticipant($thread, $parent);

                return $this->threadPayload($thread, $conversations, $parent);
            })
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $items,
            ],
        ]);
    }

    public function startDirect(Request $request, ConversationService $conversations): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('ec_students', 'id')],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:5000'],
            'desk' => ['nullable', Rule::in(array_keys(ConversationService::DIRECT_DESKS))],
        ]);

        $thread = $conversations->startDirectThread(
            $this->parent($request),
            Student::query()->findOrFail((int) $validated['student_id']),
            $validated['subject'] ?? null,
            $validated['desk'] ?? null
        );

        $message = null;

        if (!empty($validated['body'])) {
            $message = $conversations->postParentMessage($this->parent($request), $thread, trim($validated['body']));
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->threadPayload($thread->load(['school', 'class', 'student'])->loadCount(['participants', 'messages']), $conversations, $this->parent($request)),
                'message' => $message ? $this->messagePayload($message, $this->parent($request)) : null,
            ],
        ], 201);
    }

    public function show(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        $parent = $this->parent($request);
        $conversations->ensureParentCanAccessThread($parent, $thread);
        $conversations->ensureParentParticipant($thread, $parent);

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'integer'],
        ]);

        $messages = ConversationMessage::query()
            ->where('thread_id', $thread->id)
            ->when(!empty($validated['before_id']), fn (Builder $query) => $query->where('id', '<', (int) $validated['before_id']))
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get()
            ->reverse()
            ->values();

        if (empty($validated['before_id'])) {
            $conversations->markReadForParent($parent, $thread);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'thread' => $this->threadPayload($thread->load(['school', 'class', 'student'])->loadCount(['participants', 'messages']), $conversations, $parent),
                'messages' => $messages->map(fn (ConversationMessage $message) => $this->messagePayload($message, $parent))->values(),
            ],
        ]);
    }

    public function postMessage(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = $conversations->postParentMessage($this->parent($request), $thread, trim($validated['body']));

        return response()->json([
            'status' => 'success',
            'data' => [
                'message' => $this->messagePayload($message, $this->parent($request)),
                'thread' => $this->threadPayload($thread->refresh()->load(['school', 'class', 'student'])->loadCount(['participants', 'messages']), $conversations, $this->parent($request)),
            ],
        ], 201);
    }

    public function markRead(Request $request, ConversationThread $thread, ConversationService $conversations): JsonResponse
    {
        $marked = $conversations->markReadForParent($this->parent($request), $thread);

        return response()->json([
            'status' => 'success',
            'data' => [
                'marked_read' => $marked,
            ],
        ]);
    }

    private function parent(Request $request): ParentAccount
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        return $parent;
    }

    private function threadPayload(ConversationThread $thread, ConversationService $conversations, ParentAccount $parent): array
    {
        $metadata = $thread->metadata ?? [];
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
            'metadata' => $metadata,
            'can_post' => $thread->status === 'open'
                && ($thread->type !== ConversationThread::TYPE_SCHOOL_CHANNEL || ($metadata['parents_can_post'] ?? false) === true),
            'unread_count' => $conversations->unreadCountForParent($parent, $thread),
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
            'last_message' => $lastMessage ? $this->messagePayload($lastMessage, $parent) : null,
        ];
    }

    private function messagePayload(ConversationMessage $message, ParentAccount $parent): array
    {
        $ownMessage = $message->sender_type === ConversationMessage::SENDER_PARENT
            && (int) $message->sender_id === (int) $parent->id;

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
            'own_message' => $ownMessage,
        ] + ($ownMessage ? $this->receiptPayload($message, $parent) : []);
    }

    private function receiptPayload(ConversationMessage $message, ParentAccount $parent): array
    {
        $receipts = ConversationMessageReceipt::query()
            ->where('message_id', $message->id)
            ->whereHas('participant', function (Builder $query) use ($parent): void {
                $query->where(function (Builder $participant) use ($parent): void {
                    $participant->where('participant_type', '!=', ConversationMessage::SENDER_PARENT)
                        ->orWhere('participant_id', '!=', $parent->id);
                });
            })
            ->get(['delivered_at', 'read_at']);

        $deliveredAt = $receipts
            ->pluck('delivered_at')
            ->filter()
            ->sortByDesc(fn ($date) => $date->getTimestamp())
            ->first();
        $readAt = $receipts
            ->pluck('read_at')
            ->filter()
            ->sortByDesc(fn ($date) => $date->getTimestamp())
            ->first();

        return [
            'delivered_at' => $deliveredAt?->toIso8601String(),
            'read_at' => $readAt?->toIso8601String(),
            'delivered_to_count' => $receipts->whereNotNull('delivered_at')->count(),
            'read_by_count' => $receipts->whereNotNull('read_at')->count(),
        ];
    }
}
