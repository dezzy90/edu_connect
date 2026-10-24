<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Leased, revision-ordered delivery. Never sends a device receipt through the legacy attendance endpoint. */
final class OutboxDispatcher
{
    public function work(int $limit = 100): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'retry' => 0, 'held' => 0];
        $schools = config('gate.school_ids', []);
        if (! config('gate.enabled') || ! is_array($schools) || ! array_is_list($schools) || $schools === []
            || array_filter($schools, static fn ($id) => ! is_int($id) || $id < 1)) {
            return $stats;
        }
        $destinations = [];
        if (config('gate.mirror_delivery_enabled')) {
            $destinations[] = 'edu_admin';
        }
        if (config('gate.parent_delivery_enabled')) {
            $destinations[] = 'parents';
        }
        if ($destinations === []) {
            return $stats;
        }
        for ($i = 0; $i < max(1, min(1000, $limit)); $i++) {
            $row = $this->claim($schools, $destinations);
            if (! $row) {
                break;
            }
            try {
                if ($row->destination === 'edu_admin') {
                    $this->mirror($row);
                    $this->finish($row, 'sent');
                    $stats['sent']++;
                } else {
                    $state = $this->parents($row);
                    $stats[$state]++;
                }
            } catch (Throwable $error) {
                // Never retain an HTTP exception/response body, token or portrait.
                $failure = $error instanceof DeliveryFailure ? $error : new DeliveryFailure('transport_failure');
                $state = $failure->permanent ? 'held' : 'retry';
                $this->finish($row, $state, $failure->reason);
                $stats[$state]++;
            }
        }

        return $stats;
    }

    private function claim(array $schools, array $destinations): ?object
    {
        return DB::transaction(function () use ($schools, $destinations): ?object {
            $row = DB::table('ec_gate_outbox as o')->join('ec_gate_days as d', 'd.id', '=', 'o.day_id')
                ->whereIn('d.school_id', $schools)->whereIn('o.destination', $destinations)
                ->whereIn('o.status', ['pending', 'retry', 'processing'])->where('o.available_at', '<=', now())
                ->where('o.lease_until', '<=', now()->getTimestamp())
                ->whereNotExists(function ($q): void {
                    $q->selectRaw('1')->from('ec_gate_outbox as prior')->whereColumn('prior.event_id', 'o.event_id')
                        ->whereColumn('prior.destination', 'o.destination')->whereColumn('prior.revision', '<', 'o.revision')
                        ->whereNotIn('prior.status', ['sent', 'skipped']);
                })->orderBy('o.id')->lockForUpdate()->first(['o.*']);
            if (! $row) {
                return null;
            }
            $row->lease_token = (string) Str::uuid();
            $row->attempts = (int) $row->attempts + 1;
            DB::table('ec_gate_outbox')->where('id', $row->id)->update(['status' => 'processing',
                'lease_token' => $row->lease_token, 'lease_until' => now()->getTimestamp() + 90, 'attempts' => $row->attempts]);

            return $row;
        }, 3);
    }

    private function mirror(object $row): void
    {
        $event = json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR);
        $snapshot = json_decode($row->delivery_snapshot, true, 16, JSON_THROW_ON_ERROR);
        $binding = DB::table('ec_gate_mirror_bindings')->where('id', $snapshot['binding_id'])
            ->where('tenant_id', $event['tenant_id'])->where('school_id', $event['school_id'])->first();
        if (! $binding || ! $binding->enabled) {
            throw new DeliveryFailure('binding_unavailable');
        }
        if ((int) $binding->connection_id !== $snapshot['connection_id'] || $binding->source_id !== $snapshot['source_id']
            || (int) $binding->remote_school_id !== $snapshot['school_id']) {
            throw new DeliveryFailure('binding_changed', true);
        }
        $connection = DB::table('ec_integration_connections')->where('id', $snapshot['connection_id'])
            ->where('tenant_id', $event['tenant_id'])->where('provider', 'edu_admin')->where('status', 'active')->first();
        $allowed = $connection ? json_decode($connection->enabled_school_ids ?? '[]', true) : [];
        if (! $connection || ! is_array($allowed) || ! in_array($event['school_id'], $allowed, true)) {
            throw new DeliveryFailure('connection_unavailable');
        }
        $origin = parse_url($snapshot['base_url']);
        if (rtrim($connection->base_url, '/') !== $snapshot['base_url'] || ! is_array($origin)
            || ($origin['scheme'] ?? null) !== 'https' || empty($origin['host'])
            || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment'])
            || ! in_array($origin['path'] ?? '', ['', '/'], true)) {
            throw new DeliveryFailure('origin_changed', true);
        }
        if (($event['related_event_id'] ?? null) !== null && ! DB::table('ec_gate_outbox')
            ->where('event_id', $event['related_event_id'])->where('destination', 'edu_admin')->where('status', 'sent')->exists()) {
            throw new DeliveryFailure('exit_delivery_pending');
        }
        try {
            $token = Crypt::decryptString($connection->encrypted_access_token);
            $secret = Crypt::decryptString($connection->webhook_secret);
        } catch (Throwable) {
            throw new DeliveryFailure('credentials_unavailable', true);
        }
        if ($token === '' || $secret === '') {
            throw new DeliveryFailure('credentials_unavailable', true);
        }
        $wire = array_intersect_key($event, array_flip(['event_id', 'movement', 'state', 'occurred_at', 'received_at',
            'policy_version', 'device_uid', 'observation_key', 'decision_source', 'decision_id', 'actor_id', 'reason_code', 'late', 'related_event_id']));
        $wire += ['revision' => (int) $row->revision, 'school_id' => $snapshot['school_id'], 'student_id' => $snapshot['student_id'],
            'source_tenant_id' => $event['tenant_id'], 'source_school_id' => $event['school_id']];
        $body = json_encode(['schema_version' => 1, 'source_id' => $snapshot['source_id'], 'events' => [$wire]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $response = Http::acceptJson()->withToken($token)->withHeaders(['X-Edu-Connect-Timestamp' => $timestamp,
            'X-Edu-Connect-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)])
            ->withBody($body, 'application/json')->connectTimeout(5)->timeout(15)->withoutRedirecting()
            ->post($snapshot['base_url'].'/api/v1/integrations/edu-connect/gate-events');
        if (in_array($response->status(), [401, 403, 409, 422], true)) {
            throw new DeliveryFailure('remote_rejected_'.$response->status(), true);
        }
        if ($response->redirect()) {
            throw new DeliveryFailure('remote_redirect', true);
        }
        if ($response->status() !== 200) {
            throw new DeliveryFailure('remote_unavailable');
        }
        $ack = $response->json();
        $key = $row->event_id.':'.$row->revision;
        if (! is_array($ack) || ! is_array($ack['accepted'] ?? null) || ! is_array($ack['duplicates'] ?? null)
            || ! in_array($key, [...$ack['accepted'], ...$ack['duplicates']], true)) {
            throw new DeliveryFailure('invalid_acknowledgement');
        }
    }

    private function parents(object $row): string
    {
        return DB::transaction(function () use ($row): string {
            $locked = DB::table('ec_gate_outbox')->where('id', $row->id)->where('lease_token', $row->lease_token)->lockForUpdate()->first();
            if (! $locked) {
                throw new DeliveryFailure('lease_lost');
            }
            $event = json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR);
            $snapshot = json_decode($row->delivery_snapshot, true, 16, JSON_THROW_ON_ERROR);
            $head = DB::table('ec_gate_movements')->where('event_id', $row->event_id)->sole();
            $superseded = (int) $head->revision > (int) $row->revision;
            $schoolAllows = ParentAlertSettings::permits($event);
            $created = 0;
            $hasPush = false;
            foreach ($snapshot['parent_ids'] as $id) {
                $existing = DB::table('ec_gate_parent_deliveries')->where('outbox_id', $row->id)->where('parent_account_id', $id)->first();
                if ($existing) {
                    $created += $existing->status === 'sent' ? 1 : 0;

                    continue;
                }
                $link = DB::table('ec_parent_student_links as l')->join('ec_parent_accounts as p', 'p.id', '=', 'l.parent_account_id')
                    ->join('ec_students as s', 's.id', '=', 'l.student_id')->where('p.id', $id)->where('p.status', 'active')->whereNull('p.deleted_at')
                    ->where('l.student_id', $event['student_id'])->where('l.tenant_id', $event['tenant_id'])->where('l.school_id', $event['school_id'])
                    ->where('l.status', 'active')->whereNull('l.deleted_at')->where('s.tenant_id', $event['tenant_id'])->where('s.school_id', $event['school_id'])
                    ->whereNull('s.deleted_at')->where('s.mobile_visible', true)->whereIn('s.status', ['active', 'enrolled'])->exists();
                $preference = DB::table('ec_notification_preferences')->where('parent_account_id', $id)->where('category', 'attendance')->first();
                $seen = DB::table('ec_gate_parent_deliveries as pd')->join('ec_gate_outbox as po', 'po.id', '=', 'pd.outbox_id')
                    ->where('po.event_id', $row->event_id)->where('po.revision', '<', $row->revision)
                    ->where('pd.parent_account_id', $id)->where('pd.status', 'sent')->exists();
                $inApp = ! $preference || $preference->in_app_enabled;
                $push = config('gate.parent_push_enabled') === true && (! $preference || $preference->push_enabled);
                $allowed = $schoolAllows && $link && ($inApp || $push) && ! $superseded && ($event['state'] === 'active' || $seen);
                $notification = null;
                if ($allowed) {
                    $corrected = $seen && ($event['state'] === 'withdrawn' || (int) $row->revision > 1);
                    $at = CarbonImmutable::parse($event['occurred_at'])->setTimezone($event['timezone']);
                    $data = array_intersect_key($event, array_flip(['student_id', 'school_id', 'movement', 'state', 'occurred_at', 'received_at', 'timezone', 'late']));
                    $data += ['source' => 'gate', 'gate_event_id' => $event['event_id'], 'revision' => (int) $row->revision,
                        'delayed' => CarbonImmutable::parse($event['received_at'])->diffInSeconds(CarbonImmutable::parse($event['occurred_at']), true) > 60];
                    $student = DB::table('ec_students')->where('id', $event['student_id'])->where('tenant_id', $event['tenant_id'])->where('school_id', $event['school_id'])->first(['first_name', 'last_name']);
                    $school = DB::table('ec_schools')->where('id', $event['school_id'])->where('tenant_id', $event['tenant_id'])->value('name');
                    $private = in_array(config('educonnect.notifications.privacy_mode'), ['hidden', 'private'], true);
                    $name = $private ? 'Your child' : trim(($student?->first_name ?? '').' '.($student?->last_name ?? ''));
                    $movement = $event['movement'] === 'check_in' ? 'arrived at' : 'left';
                    $channel = $push ? ($inApp ? 'in_app_push' : 'push') : 'in_app';
                    // Queue push explicitly after the durable feed insert; do not invoke legacy attendance observers.
                    $notification = DB::table('ec_mobile_notifications')->insertGetId(['parent_account_id' => $id,
                        'tenant_id' => $event['tenant_id'], 'school_id' => $event['school_id'], 'type' => 'attendance',
                        'title' => $corrected ? 'Attendance record updated' : ($event['movement'] === 'check_in' ? 'Arrived at school' : 'Left school'),
                        'body' => $corrected ? 'A recorded school gate movement has been corrected. Open the gate history for the current record.'
                            : $name.' '.$movement.' '.($private ? 'school' : $school).' at '.$at->format('H:i').' on '.$at->format('d M Y').'.',
                        'data' => json_encode($data, JSON_THROW_ON_ERROR), 'priority' => 'normal', 'channel' => $channel,
                        'delivery_status' => $push ? 'queued' : 'sent', 'sent_at' => $push ? null : now(), 'created_at' => now(), 'updated_at' => now()]);
                    if ($push) {
                        $hasPush = true;
                        app(\App\Services\Notifications\PushNotificationDispatcher::class)->enqueueNotification(
                            \App\Models\V2\MobileNotification::withoutGlobalScopes()->findOrFail($notification));
                    }
                    $created++;
                }
                DB::table('ec_gate_parent_deliveries')->insert(['outbox_id' => $row->id, 'parent_account_id' => $id,
                    'status' => $allowed ? 'sent' : 'skipped', 'reason_code' => $allowed ? null : match (true) {
                        ! $schoolAllows => 'school_alert_disabled', ! $link => 'link_not_active', $superseded => 'superseded_before_delivery',
                        $event['state'] === 'withdrawn' && ! $seen => 'withdrawn_before_delivery', default => 'preference_disabled',
                    },
                    'notification_id' => $notification, 'created_at' => now()]);
            }
            $state = $created > 0 ? 'sent' : 'skipped';
            if ($hasPush) DB::afterCommit(fn () => \App\Jobs\V2\Notifications\DispatchGateParentAlertsJob::dispatch()
                ->onQueue((string) config('integrations.scheduler.queue', 'edu-connect')));
            $this->finish($row, $state);

            return $state;
        }, 3);
    }

    private function finish(object $row, string $state, ?string $reason = null): void
    {
        $delay = min(3600, 15 * (2 ** min(8, (int) $row->attempts - 1)));
        DB::table('ec_gate_outbox')->where('id', $row->id)->where('lease_token', $row->lease_token)->update([
            'status' => $state, 'lease_token' => null, 'lease_until' => 0, 'last_error' => $reason,
            'available_at' => now()->addSeconds($delay), 'sent_at' => in_array($state, ['sent', 'skipped'], true) ? now() : null,
        ]);
    }
}
