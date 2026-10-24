<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Services\Biometrics\Devices\DeviceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Asynchronous metadata projection. Never called from the terminal's receipt/ACK transaction. */
final class DayProjector
{
    public function work(int $limit = 100): int
    {
        $schools = config('gate.school_ids', []);
        if (! config('gate.enabled') || ! is_array($schools) || ! array_is_list($schools) || $schools === []
            || array_filter($schools, static fn ($id) => ! is_int($id) || $id < 1)) {
            return 0;
        }
        $ids = DB::table('ec_hikvision_observations as o')
            ->leftJoin('ec_gate_observation_links as l', 'l.observation_id', '=', 'o.id')
            ->whereIn('o.school_id', $schools)->where(function ($q): void {
                $q->whereNull('l.observation_id')->orWhereColumn('l.source_status', '!=', 'o.status')
                    ->orWhereColumn('l.source_fingerprint', '!=', 'o.fingerprint');
            })->orderBy('o.id')->limit(max(1, min(1000, $limit)))->pluck('o.id');
        foreach ($ids as $id) {
            $this->capture((int) $id, $schools);
        }

        return $ids->count();
    }

    private function capture(int $id, array $schools): void
    {
        // Resolve before opening a transaction. The first transactional read must
        // lock the day, before any snapshot/observation lock, so concurrent units
        // cannot overwrite a newer projection using a pre-wait MySQL snapshot.
        $before = DB::table('ec_hikvision_observations')->where('id', $id)->whereIn('school_id', $schools)->sole();
        $existing = DB::table('ec_gate_observation_links')->where('observation_id', $id)->first();
        $resolution = null;
        $dayId = $existing?->day_id;
        if (! $existing) {
            $resolution = $this->resolve($before, json_decode($before->metadata, true, 16, JSON_THROW_ON_ERROR));
            if (is_array($resolution)) {
                [$policy, $student] = $resolution;
                $scope = ['tenant_id' => (int) $before->tenant_id, 'school_id' => (int) $before->school_id,
                    'student_id' => (int) $student->id, 'day' => $policy->day];
                DB::table('ec_gate_days')->insertOrIgnore($scope + ['updated_at' => now()]);
                $dayId = DB::table('ec_gate_days')->where($scope)->value('id');
            }
        }
        DB::transaction(function () use ($id, $schools, $before, $resolution, $dayId): void {
            if ($dayId !== null) {
                DB::table('ec_gate_days')->where('id', $dayId)->lockForUpdate()->sole();
            }
            $row = DB::table('ec_hikvision_observations')->where('id', $id)->whereIn('school_id', $schools)->lockForUpdate()->sole();
            $link = DB::table('ec_gate_observation_links')->where('observation_id', $id)->lockForUpdate()->first();
            $values = ['source_status' => $row->status, 'source_fingerprint' => $row->fingerprint];
            if ($link) {
                if ($link->day_id !== $dayId) {
                    return; // A concurrent link won; pick up its actual day on the next pass.
                }
                if ($link->source_status === $row->status && $link->source_fingerprint === $row->fingerprint) {
                    return;
                }
                // Scope/policy/student links are frozen. A conflict cannot remap the old scan to another person.
                DB::table('ec_gate_observation_links')->where('observation_id', $id)->update($values);
                if ($link->day_id !== null) {
                    $this->rebuild((int) $link->day_id);
                }

                return;
            }
            if ($row->status !== $before->status || $row->fingerprint !== $before->fingerprint) {
                return; // Never commit a mapping resolved from a superseded receipt.
            }
            $values += ['observation_id' => $id, 'day_id' => null, 'policy_id' => null, 'error_code' => null,
                'original_fingerprint' => $row->fingerprint, 'linked_at' => now()];
            if (is_string($resolution)) {
                $values['error_code'] = $resolution;
                DB::table('ec_gate_observation_links')->insert($values);

                return;
            }
            [$policy, $student, $device] = $resolution;
            $values['day_id'] = $dayId;
            $values['policy_id'] = $policy->id;
            $values['policy_snapshot'] = $policy->snapshot;
            $values['device_uid'] = $device->device_uid;
            DB::table('ec_gate_observation_links')->insert($values);
            $this->rebuild((int) $dayId);
        }, 3);
    }

    private function resolve(object $row, array $metadata): array|string
    {
        if ($row->status !== 'recognition_candidate') {
            return 'recognition_not_accepted';
        }
        $push = DB::table('ec_hikvision_push_devices')->where('id', $row->push_device_id)
            ->where('tenant_id', $row->tenant_id)->first();
        $shared = isset($metadata['shared_gate_scope']);
        if (! $push || (int) ($metadata['device_id'] ?? 0) !== (int) $push->device_id
            || (int) ($metadata['tenant_id'] ?? 0) !== (int) $row->tenant_id
            || (int) ($metadata['school_id'] ?? 0) !== (int) $row->school_id
            || ($shared ? ! DeviceScope::receiptValid($push, $row, $metadata) : (int) $push->school_id !== (int) $row->school_id)) {
            return 'device_scope_mismatch';
        }
        $device = DB::table('ec_biometric_devices')->where('id', $push->device_id)->where('tenant_id', $row->tenant_id)
            ->where('school_id', $push->school_id)->where('status', 'active')->whereNull('deleted_at')->first();
        if (! $device || ! preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $device->device_uid)) {
            return 'device_not_active';
        }
        $students = DB::table('ec_device_personnel as p')->join('ec_students as s', 's.id', '=', 'p.student_id')
            ->where('p.device_id', $push->device_id)->where('p.tenant_id', $row->tenant_id)->where('p.school_id', $row->school_id)
            ->where('p.person_identifier', $metadata['employee_no'] ?? null)->where('p.sync_status', 'acknowledged')
            ->where('s.tenant_id', $row->tenant_id)->where('s.school_id', $row->school_id)
            ->whereIn('s.status', ['active', 'enrolled'])->whereNull('s.deleted_at')->limit(2)->get(['s.id']);
        if ($students->count() !== 1) {
            return 'person_mapping_not_unique';
        }
        $student = $students->sole();
        $instant = CarbonImmutable::parse(Instant::normalize($metadata['occurred_at'] ?? null))->utc();
        $policies = DB::table('ec_gate_policies')->where('tenant_id', $row->tenant_id)->where('school_id', $row->school_id)
            ->where('enabled', true)->where('effective_from', '<=', $instant->format('Y-m-d H:i:s'))
            ->where(function ($q) use ($instant): void {
                $q->whereNull('effective_until')->orWhere('effective_until', '>', $instant->format('Y-m-d H:i:s'));
            })->get()->filter(function ($p) use ($instant, $student, $push): bool {
                $devices = json_decode($p->device_ids, true, 16, JSON_THROW_ON_ERROR);

                return PolicyRoster::includes($p, (int) $student->id, $instant->toIso8601String()) && $this->selection($devices, (int) $push->device_id)
                    && $instant->setTimezone($p->timezone)->format('Y-m-d') === $p->day;
            })->values();
        if ($policies->count() !== 1) {
            return $policies->isEmpty() ? 'policy_not_configured' : 'policy_overlap';
        }
        $policy = $policies->sole();
        $snapshot = new DayPolicy(json_decode($policy->snapshot, true, 16, JSON_THROW_ON_ERROR));
        if ([$snapshot->values['tenant_id'], $snapshot->values['school_id'], $snapshot->values['day'], $snapshot->values['version'], $snapshot->values['timezone']]
            !== [(int) $row->tenant_id, (int) $row->school_id, $policy->day, $policy->version, $policy->timezone]) {
            return 'policy_scope_mismatch';
        }

        return [$policy, $student, $device];
    }

    /** Caller must hold the day row lock; raw scans and immutable policy snapshots remain untouched. */
    public function rebuild(int $dayId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Gate day projection requires a transaction and row lock.');
        }
        $day = DB::table('ec_gate_days')->where('id', $dayId)->lockForUpdate()->sole();
        if (! config('gate.enabled') || ! in_array((int) $day->school_id, config('gate.school_ids', []), true)) {
            throw new RuntimeException('Gate day is outside the enabled processing scope.');
        }
        $rows = DB::table('ec_gate_observation_links as l')->join('ec_hikvision_observations as o', 'o.id', '=', 'l.observation_id')
            ->where('l.day_id', $dayId)->orderBy('o.id')
            ->limit(10001)->get(['o.*', 'l.policy_snapshot', 'l.device_uid', 'l.original_fingerprint']);
        $observations = $byKey = [];
        foreach ($rows as $row) {
            $m = json_decode($row->metadata, true, 16, JSON_THROW_ON_ERROR);
            $status = $row->status;
            if (! hash_equals($row->original_fingerprint, $row->fingerprint)) {
                $status = 'held_content_conflict';
            }
            $observations[] = ['key' => $row->event_key, 'student_id' => (int) $day->student_id,
                'device_id' => $m['device_id'], 'status' => $status, 'occurred_at' => $m['occurred_at'],
                'received_at' => CarbonImmutable::parse($row->received_at, 'UTC')->toIso8601String(),
                'policy' => new DayPolicy(json_decode($row->policy_snapshot, true, 16, JSON_THROW_ON_ERROR))];
            $byKey[$row->event_key] = $row;
        }
        $actions = DB::table('ec_gate_actions')->where('day_id', $dayId)->whereIn('kind', ['resolve', 'permit_exit', 'exclude'])
            ->whereIn('status', ['active', 'consumed', 'approved'])
            ->orderBy('id')->limit(1001)->get()->map(function ($a) use ($day, $byKey): array {
                $parameters = json_decode($a->parameters, true, 16, JSON_THROW_ON_ERROR);
                $consumedKey = null;
                if ($a->consumed_observation_id !== null) {
                    foreach ($byKey as $key => $row) {
                        if ((int) $row->id === (int) $a->consumed_observation_id) {
                            $consumedKey = $key;
                            break;
                        }
                    }
                    // A spent authorization never migrates to a different observation on replay.
                    $consumedKey ??= 'missing_consumed_observation';
                }
                $authorityValid = true;
                if ($a->kind === 'permit_exit' && $a->consumed_observation_id === null && ($parameters['operation'] ?? null) === 'permit_exit') {
                    $sourceUser = DB::table('admin_users')->where('id', $a->actor_id)->first();
                    $sourceValid = ! ($sourceUser->gate_source_only ?? false) || DB::table('ec_gate_source_actors')
                        ->where('admin_id', $a->actor_id)->where('authority_until', '>', now()->utc())->exists();
                    $authorityValid = $sourceValid && (bool) config('gate.operator_enabled')
                        && DB::table('admin_users')->where('id', $a->actor_id)->where('is_active', true)->where('test_recovery_only', false)->lockForUpdate()->first() !== null
                        && StaffingPolicy::restrict(DB::table('ec_gate_operator_assignments as a')
                            ->join('ec_schools as s', fn ($q) => $q->on('s.id', '=', 'a.school_id')->on('s.tenant_id', '=', 'a.tenant_id'))
                            ->where('a.admin_id', $a->actor_id)->where('a.tenant_id', $day->tenant_id)
                            ->where('a.school_id', $day->school_id)->where('a.role', $a->role)->where('a.active', true))->lockForUpdate()->first() !== null;
                }

                return array_replace($parameters, ['id' => $a->id, 'tenant_id' => (int) $day->tenant_id,
                    'school_id' => (int) $day->school_id, 'student_id' => (int) $day->student_id,
                    'kind' => $a->kind, 'role' => $a->role, 'actor_id' => (int) $a->actor_id, 'reason_code' => $a->reason_code,
                    'created_at' => CarbonImmutable::parse($a->created_at, 'UTC')->toIso8601String(),
                    'consumed_observation_key' => $consumedKey,
                    'authority_valid' => $authorityValid,
                    'expires_at' => $a->expires_at ? CarbonImmutable::parse($a->expires_at, 'UTC')->toIso8601String() : null]);
            })->all();
        $projection = (new DayClassifier)->project($observations, $actions);
        $hash = hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR));
        if ($day->projection_hash === $hash) {
            return $projection;
        }
        $heads = DB::table('ec_gate_movements')->where('day_id', $dayId)->get()->keyBy('event_id');
        foreach ($projection['movements'] as $eventId => $movement) {
            $row = $byKey[$movement['observation_key']];
            $payload = $movement + ['tenant_id' => (int) $day->tenant_id, 'school_id' => (int) $day->school_id,
                'student_id' => (int) $day->student_id, 'device_uid' => $row->device_uid, 'state' => 'active',
                'timezone' => json_decode($row->policy_snapshot, true, 16, JSON_THROW_ON_ERROR)['timezone']];
            $this->revision($day, (int) $row->id, $eventId, $payload, $heads->get($eventId));
        }
        foreach ($heads as $head) {
            if (isset($projection['movements'][$head->event_id]) || $head->state === 'withdrawn') {
                continue;
            }
            $payload = json_decode($head->payload, true, 16, JSON_THROW_ON_ERROR);
            $payload['state'] = 'withdrawn';
            $payload['decision_source'] = 'reconciliation';
            $payload['decision_id'] = $hash;
            $payload['actor_id'] = null;
            $payload['reason_code'] = 'observation_reconciled';
            foreach ($actions as $action) {
                if ($action['kind'] === 'exclude' && ($action['observation_key'] ?? null) === $payload['observation_key']) {
                    $payload['decision_source'] = $action['role'] === 'discipline_master' ? 'discipline_master' : 'correction';
                    $payload['decision_id'] = $action['id'];
                    $payload['actor_id'] = 'admin:'.$action['actor_id'];
                    $payload['reason_code'] = $action['reason_code'];
                    break;
                }
            }
            $this->revision($day, (int) $head->observation_id, $head->event_id, $payload, $head);
        }
        foreach ($projection['consumed'] as $actionId => $key) {
            DB::table('ec_gate_actions')->where('id', $actionId)->where('day_id', $dayId)
                ->update(['status' => 'consumed', 'consumed_observation_id' => $byKey[$key]->id, 'updated_at' => now()]);
        }
        DB::table('ec_gate_days')->where('id', $dayId)->update(['state' => $projection['state'],
            'revision' => (int) $day->revision + 1, 'projection_hash' => $hash,
            'decisions' => json_encode($projection['decisions'], JSON_THROW_ON_ERROR), 'updated_at' => now()]);

        return $projection;
    }

    private function revision(object $day, int $observationId, string $eventId, array $payload, ?object $head): void
    {
        $semanticHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($head && hash_equals($head->projection_payload_hash, $semanticHash)) {
            return;
        }
        if ($head && $payload['decision_source'] === 'school_policy') {
            $payload['decision_source'] = 'reconciliation';
            $payload['decision_id'] = $semanticHash;
        }
        $revision = $head ? (int) $head->revision + 1 : 1;
        $values = ['day_id' => $day->id, 'observation_id' => $observationId, 'revision' => $revision,
            'state' => $payload['state'], 'movement' => $payload['movement'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'projection_payload_hash' => $semanticHash, 'updated_at' => now()];
        if ($head) {
            DB::table('ec_gate_movements')->where('event_id', $eventId)->update($values);
        } else {
            DB::table('ec_gate_movements')->insert($values + ['event_id' => $eventId, 'created_at' => now()]);
        }
        DB::table('ec_gate_movement_revisions')->insert(['event_id' => $eventId, 'revision' => $revision,
            'payload_hash' => hash('sha256', $values['payload']), 'payload' => $values['payload'], 'created_at' => now()]);
        // Durable intents are committed with the projection. Separate switches/workers control all delivery.
        foreach (['edu_admin', 'parents'] as $destination) {
            $snapshot = $destination === 'edu_admin' ? DeliverySnapshot::mirror($payload) : DeliverySnapshot::parents($payload);
            DB::table('ec_gate_outbox')->insert(['day_id' => $day->id, 'event_id' => $eventId, 'revision' => $revision,
                'destination' => $destination, 'payload' => $values['payload'], 'delivery_snapshot' => $snapshot === null ? null : json_encode($snapshot, JSON_THROW_ON_ERROR),
                'status' => $snapshot === null ? 'held_mapping' : 'pending', 'available_at' => now(), 'created_at' => now()]);
        }
    }

    private function selection(mixed $values, int $id): bool
    {
        return is_array($values) && array_is_list($values) && $values !== [] && count($values) === count(array_unique($values))
            && ! array_filter($values, static fn ($value) => ! is_int($value) || $value < 1) && in_array($id, $values, true);
    }
}
