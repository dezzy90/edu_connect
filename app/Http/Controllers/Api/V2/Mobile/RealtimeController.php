<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\ConversationParticipant;
use App\Models\V2\ConversationThread;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\RealtimeSubscription;
use App\Models\V2\Student;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RealtimeController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        $parent = $this->parent($request);
        $appKey = config('educonnect.realtime.app_key');
        $appSecret = config('educonnect.realtime.app_secret');
        $host = config('educonnect.realtime.host');
        $enabled = (bool) config('educonnect.realtime.enabled')
            && filled($appKey)
            && filled($appSecret)
            && filled($host);

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => $enabled,
                'driver' => config('educonnect.realtime.driver'),
                'auth_endpoint' => '/api/mobile/v2/realtime/auth',
                'heartbeat_endpoint' => '/api/mobile/v2/realtime/heartbeat',
                'channels' => $enabled ? $this->allowedChannels($parent)->values() : [],
                'connection' => [
                    'key' => $enabled ? $appKey : null,
                    'host' => $enabled ? $host : null,
                    'port' => config('educonnect.realtime.port'),
                    'scheme' => config('educonnect.realtime.scheme'),
                ],
            ],
        ]);
    }

    public function authorizeChannel(Request $request): JsonResponse
    {
        $parent = $this->parent($request);
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:120'],
            'channel_name' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $channelName = $validated['channel_name'];

        if (!$this->allowedChannels($parent)->contains($channelName)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This parent account cannot access the requested realtime channel.',
            ], 403);
        }

        $auth = $this->pusherStyleAuth($validated['socket_id'], $channelName);

        if ($auth === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Realtime private-channel authentication is not configured.',
            ], 503);
        }

        $subscription = RealtimeSubscription::query()->create([
            'parent_account_id' => $parent->id,
            'channel_name' => $channelName,
            'socket_id' => $validated['socket_id'],
            'connected_at' => now(),
            'last_seen_at' => now(),
            'metadata' => [
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
                'client' => $validated['metadata'] ?? [],
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'authorized' => true,
                'channel_name' => $channelName,
                'socket_id' => $validated['socket_id'],
                'auth' => $auth,
                'subscription' => [
                    'id' => $subscription->id,
                    'connected_at' => $subscription->connected_at?->toIso8601String(),
                    'last_seen_at' => $subscription->last_seen_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $parent = $this->parent($request);
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:120'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        if (!$this->allowedChannels($parent)->contains($validated['channel_name'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'This parent account cannot access the requested realtime channel.',
            ], 403);
        }

        $updated = RealtimeSubscription::query()
            ->where('parent_account_id', $parent->id)
            ->where('socket_id', $validated['socket_id'])
            ->where('channel_name', $validated['channel_name'])
            ->whereNull('disconnected_at')
            ->latest('connected_at')
            ->limit(1)
            ->update(['last_seen_at' => now()]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'updated' => (bool) $updated,
            ],
        ]);
    }

    private function parent(Request $request): ParentAccount
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        return $parent;
    }

    private function allowedChannels(ParentAccount $parent): Collection
    {
        app(ConversationService::class)->ensureDefaultThreadsForParent($parent);

        $channels = collect([
            "private-parent.{$parent->id}",
            "private-parent.{$parent->id}.notifications",
            "private-parent.{$parent->id}.children",
        ]);

        $links = ParentStudentLink::query()
            ->where('parent_account_id', $parent->id)
            ->where('status', 'active')
            ->whereHas('student', fn ($query) => $query
                ->where('mobile_visible', true)
                ->whereIn('status', Student::MOBILE_VISIBLE_STATUSES))
            ->with('student:id,school_id,class_id')
            ->get();

        $schoolIds = collect();
        $classIds = collect();
        $studentIds = collect();

        foreach ($links as $link) {
            $channels->push("private-parent.{$parent->id}.student.{$link->student_id}");
            $studentIds->push((int) $link->student_id);

            if ($link->student?->school_id) {
                $channels->push("private-school.{$link->student->school_id}.parent.{$parent->id}");
                $channels->push("private-school.{$link->student->school_id}.parents");
                $channels->push("private-school.{$link->student->school_id}.channels");
                $schoolIds->push((int) $link->student->school_id);
            }

            if ($link->student?->class_id) {
                $channels->push("private-school.{$link->student->school_id}.class.{$link->student->class_id}.parents");
                $classIds->push((int) $link->student->class_id);
            }
        }

        ConversationThread::query()
            ->where('status', '!=', 'archived')
            ->where(function ($visible) use ($studentIds, $classIds, $schoolIds, $parent): void {
                $visible
                    ->where(function ($direct) use ($studentIds): void {
                        $direct->where('type', ConversationThread::TYPE_DIRECT)
                            ->whereIn('student_id', $studentIds->all());
                    })
                    ->orWhere(function ($groups) use ($classIds): void {
                        $groups->where('type', ConversationThread::TYPE_CLASS_GROUP)
                            ->whereIn('class_id', $classIds->all());
                    })
                    ->orWhere(function ($channels) use ($schoolIds): void {
                        $channels->where('type', ConversationThread::TYPE_SCHOOL_CHANNEL)
                            ->whereIn('school_id', $schoolIds->all());
                    })
                    ->orWhereHas('participants', fn ($participants) => $participants
                        ->where('participant_type', ConversationParticipant::TYPE_PARENT)
                        ->where('participant_id', $parent->id)
                        ->whereNull('left_at'));
            })
            ->pluck('id')
            ->each(fn ($threadId) => $channels->push("private-conversation.{$threadId}"));

        return $channels->unique()->values();
    }

    private function pusherStyleAuth(string $socketId, string $channelName): ?string
    {
        $appKey = config('educonnect.realtime.app_key');
        $secret = config('educonnect.realtime.app_secret');

        if (!$appKey || !$secret) {
            return null;
        }

        return $appKey . ':' . hash_hmac('sha256', "{$socketId}:{$channelName}", $secret);
    }
}
