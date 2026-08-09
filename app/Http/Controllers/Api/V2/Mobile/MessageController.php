<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\MobileMessage;
use App\Models\V2\MobileMessageRecipient;
use App\Models\V2\ParentAccount;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $parent = $this->parent($request);

        $validated = $request->validate([
            'read_status' => ['nullable', Rule::in(['all', 'read', 'unread'])],
            'category' => ['nullable', 'string', 'max:60'],
            'school_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MobileMessageRecipient::query()
            ->with(['message.school', 'student'])
            ->where('parent_account_id', $parent->id)
            ->whereHas('message', fn ($message) => $message->publishedVisible());

        if (! empty($validated['category'])) {
            $query->whereHas('message', fn ($message) => $message->where('category', $validated['category']));
        }

        if (! empty($validated['school_id'])) {
            $query->whereHas('message', fn ($message) => $message->where('school_id', (int) $validated['school_id']));
        }

        if (! empty($validated['student_id'])) {
            $query->where('student_id', (int) $validated['student_id']);
        }

        $unreadCount = (clone $query)->whereNull('read_at')->count();

        match ($validated['read_status'] ?? 'all') {
            'read' => $query->whereNotNull('read_at'),
            'unread' => $query->whereNull('read_at'),
            default => null,
        };

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $query
                    ->latest()
                    ->limit((int) ($validated['limit'] ?? 30))
                    ->get()
                    ->map(fn (MobileMessageRecipient $recipient) => $this->recipientPayload($recipient))
                    ->values(),
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function show(Request $request, MobileMessage $message): JsonResponse
    {
        $recipients = $this->recipientsForParentMessage($this->parent($request), $message)
            ->with(['message.school', 'student'])
            ->get();

        if ($recipients->isEmpty() || ! $message->newQuery()->whereKey($message->id)->publishedVisible()->exists()) {
            abort(404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'message' => $this->messagePayload($message->load('school')),
                'recipients' => $recipients->map(fn (MobileMessageRecipient $recipient) => $this->recipientMetaPayload($recipient))->values(),
            ],
        ]);
    }

    public function markRead(
        Request $request,
        MobileMessage $message,
        MobileRealtimeBroadcaster $realtime
    ): JsonResponse {
        $recipients = $this->recipientsForParentMessage($this->parent($request), $message)->get();

        if ($recipients->isEmpty() || ! $message->newQuery()->whereKey($message->id)->publishedVisible()->exists()) {
            abort(404);
        }

        $recipients->each(fn (MobileMessageRecipient $recipient) => $recipient->markAsRead());
        $realtime->officialMessageRead($this->parent($request), $message);

        return response()->json([
            'status' => 'success',
            'data' => [
                'marked_read' => $recipients->count(),
            ],
        ]);
    }

    private function parent(Request $request): ParentAccount
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        return $parent;
    }

    private function recipientsForParentMessage(ParentAccount $parent, MobileMessage $message)
    {
        return MobileMessageRecipient::query()
            ->where('parent_account_id', $parent->id)
            ->where('message_id', $message->id);
    }

    private function recipientPayload(MobileMessageRecipient $recipient): array
    {
        return [
            'recipient' => $this->recipientMetaPayload($recipient),
            'message' => $this->messagePayload($recipient->message),
        ];
    }

    private function recipientMetaPayload(MobileMessageRecipient $recipient): array
    {
        return [
            'id' => $recipient->id,
            'student_id' => $recipient->student_id,
            'recipient_phone' => $recipient->recipient_phone,
            'delivery_status' => $recipient->delivery_status,
            'delivered_at' => $recipient->delivered_at?->toIso8601String(),
            'read_at' => $recipient->read_at?->toIso8601String(),
            'student' => $recipient->student ? [
                'id' => $recipient->student->id,
                'student_number' => $recipient->student->student_number,
                'full_name' => $recipient->student->full_name,
            ] : null,
        ];
    }

    private function messagePayload(MobileMessage $message): array
    {
        return [
            'id' => $message->id,
            'tenant_id' => $message->tenant_id,
            'school_id' => $message->school_id,
            'category' => $message->category,
            'priority' => $message->priority,
            'title' => $message->title,
            'body' => $message->body,
            'sender_type' => $message->sender_type,
            'sender_name' => $message->sender_name,
            'audience_type' => $message->audience_type,
            'published_at' => $message->published_at?->toIso8601String(),
            'expires_at' => $message->expires_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'school' => $message->school ? [
                'id' => $message->school->id,
                'name' => $message->school->name,
                'code' => $message->school->code,
            ] : null,
        ];
    }
}
