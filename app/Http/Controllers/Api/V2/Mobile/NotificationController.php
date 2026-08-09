<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationPreference;
use App\Models\V2\ParentAccount;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    private const DEFAULT_CATEGORIES = [
        'attendance',
        'messages',
        'results',
        'fees',
        'timetable',
        'discipline',
        'general',
    ];

    public function index(Request $request): JsonResponse
    {
        $parent = $this->parent($request);

        $validated = $request->validate([
            'read_status' => ['nullable', Rule::in(['all', 'read', 'unread'])],
            'type' => ['nullable', 'string', 'max:60'],
            'school_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MobileNotification::query()
            ->with('school')
            ->where('parent_account_id', $parent->id)
            ->visible();

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['school_id'])) {
            $query->where('school_id', (int) $validated['school_id']);
        }

        $unreadCount = (clone $query)->whereNull('read_at')->count();

        match ($validated['read_status'] ?? 'all') {
            'read' => $query->whereNotNull('read_at'),
            'unread' => $query->whereNull('read_at'),
            default => null,
        };

        $items = $query
            ->latest()
            ->limit((int) ($validated['limit'] ?? 30))
            ->get()
            ->map(fn (MobileNotification $notification) => $this->notificationPayload($notification))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $items,
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function markRead(
        Request $request,
        MobileNotification $notification,
        MobileRealtimeBroadcaster $realtime
    ): JsonResponse {
        $this->authorizeNotification($this->parent($request), $notification);

        $notification->markAsRead();
        $realtime->notificationsChanged(
            $this->parent($request),
            $notification->school_id ? (int) $notification->school_id : null,
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->notificationPayload($notification->refresh()->load('school')),
        ]);
    }

    public function readAll(Request $request, MobileRealtimeBroadcaster $realtime): JsonResponse
    {
        $parent = $this->parent($request);

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:60'],
            'school_id' => ['nullable', 'integer'],
        ]);

        $query = MobileNotification::query()
            ->where('parent_account_id', $parent->id)
            ->whereNull('read_at')
            ->visible();

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['school_id'])) {
            $query->where('school_id', (int) $validated['school_id']);
        }

        $count = (clone $query)->count();
        $query->update(['read_at' => now()]);
        $realtime->notificationsChanged(
            $parent,
            ! empty($validated['school_id']) ? (int) $validated['school_id'] : null,
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'marked_read' => $count,
            ],
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->preferencePayloads($this->parent($request)),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $parent = $this->parent($request);

        $validated = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', 'string', 'max:60'],
            'preferences.*.in_app_enabled' => ['sometimes', 'boolean'],
            'preferences.*.push_enabled' => ['sometimes', 'boolean'],
            'preferences.*.sms_enabled' => ['sometimes', 'boolean'],
            'preferences.*.email_enabled' => ['sometimes', 'boolean'],
            'preferences.*.quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'preferences.*.quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);

        foreach ($validated['preferences'] as $preference) {
            $attributes = collect($preference)
                ->only([
                    'in_app_enabled',
                    'push_enabled',
                    'sms_enabled',
                    'email_enabled',
                    'quiet_hours_start',
                    'quiet_hours_end',
                ])
                ->all();

            NotificationPreference::query()->updateOrCreate(
                [
                    'parent_account_id' => $parent->id,
                    'category' => $preference['category'],
                ],
                $attributes,
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->preferencePayloads($parent),
        ]);
    }

    private function parent(Request $request): ParentAccount
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        return $parent;
    }

    private function authorizeNotification(ParentAccount $parent, MobileNotification $notification): void
    {
        if ((int) $notification->parent_account_id !== (int) $parent->id) {
            abort(404);
        }
    }

    private function notificationPayload(MobileNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'tenant_id' => $notification->tenant_id,
            'school_id' => $notification->school_id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data ?? [],
            'priority' => $notification->priority,
            'channel' => $notification->channel,
            'delivery_status' => $notification->delivery_status,
            'read_at' => $notification->read_at?->toIso8601String(),
            'sent_at' => $notification->sent_at?->toIso8601String(),
            'expires_at' => $notification->expires_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'school' => $notification->school ? [
                'id' => $notification->school->id,
                'name' => $notification->school->name,
                'code' => $notification->school->code,
            ] : null,
        ];
    }

    private function preferencePayloads(ParentAccount $parent): array
    {
        $existing = NotificationPreference::query()
            ->where('parent_account_id', $parent->id)
            ->get()
            ->keyBy('category');

        return collect(self::DEFAULT_CATEGORIES)
            ->merge($existing->keys())
            ->unique()
            ->values()
            ->map(function (string $category) use ($existing): array {
                $preference = $existing->get($category);

                return [
                    'category' => $category,
                    'in_app_enabled' => $preference?->in_app_enabled ?? true,
                    'push_enabled' => $preference?->push_enabled ?? true,
                    'sms_enabled' => $preference?->sms_enabled ?? false,
                    'email_enabled' => $preference?->email_enabled ?? false,
                    'quiet_hours_start' => $preference?->quiet_hours_start,
                    'quiet_hours_end' => $preference?->quiet_hours_end,
                ];
            })
            ->all();
    }
}
