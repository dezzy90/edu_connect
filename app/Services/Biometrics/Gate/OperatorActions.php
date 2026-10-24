<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Authenticated, day-serialized actions. No network IO, device writes or raw-scan changes. */
final class OperatorActions
{
    public function preview(object $day): ?array
    {
        try {
            [$policy] = $this->currentPolicy($day);
            $local = CarbonImmutable::now($policy['timezone']);

            return ['version' => $policy['version'], 'rules' => $policy['operator_rules']['early_exit'] ?? null,
                'return_rules' => $policy['return_rules'] ?? null,
                'available' => is_array($policy['operator_rules']['early_exit'] ?? null)
                    && $day->state === 'inside' && ! $policy['closed'] && $local->format('Y-m-d') === $day->day
                    && $local->format('H:i:s') >= $policy['arrival_start'] && $local->format('H:i:s') < $policy['departure_start']];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }

            return null;
        }
    }

    public function submit(AdminUser $actor, int $dayId, array $input): array
    {
        return DB::transaction(function () use ($actor, $dayId, $input): array {
            // First transactional read locks the day, matching the projector's lock order.
            $day = DB::table('ec_gate_days')->where('id', $dayId)->lockForUpdate()->first();
            abort_unless($day, 404);
            $roles = app(OperatorAccess::class)->roles($actor, $day, true);
            $operation = $input['operation'];
            abort_if(in_array((int) $day->school_id, config('gate.recording_only_school_ids', []), true)
                && in_array($operation, ['correct', 'approve_correction'], true), 403, 'DM-only corrections are disabled for this school.');
            $permitted = match ($operation) {
                'correct' => ['discipline_master'],
                'approve_correction' => ['discipline_master', 'correction_approver'],
                'request_correction' => ['guard', 'secretary'],
                default => ['discipline_master', 'guard', 'secretary'],
            };
            $role = array_values(array_intersect($permitted, $roles))[0] ?? null;
            abort_unless($role, 403, 'This gate assignment cannot perform that action.');
            $id = hash('sha256', 'gate-action|'.$dayId.'|'.$actor->id.'|'.$input['idempotency_key']);
            $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $existing = DB::table('ec_gate_actions')->where('id', $id)->first();
            if ($existing) {
                $old = json_decode($existing->parameters, true, 16, JSON_THROW_ON_ERROR);
                abort_unless(($old['request_hash'] ?? null) === $requestHash, 409, 'Idempotency key was already used for another request.');

                return $this->result($existing, $day, true);
            }
            abort_unless((int) $day->revision === $input['expected_revision'], 409, 'Gate history changed. Refresh before acting.');
            abort_if(DB::table('ec_gate_actions')->where('day_id', $dayId)->count() >= 1000, 409, 'Day action limit requires review.');
            $values = ['id' => $id, 'day_id' => $dayId, 'actor_id' => $actor->id, 'role' => $role,
                'kind' => $operation, 'observation_id' => null, 'movement_id' => null, 'reference_action_id' => null,
                'reason_code' => $input['reason_code'], 'status' => 'active', 'basis_revision' => $day->revision,
                'expires_at' => null, 'created_at' => now()->utc(), 'updated_at' => now()->utc()];
            $parameters = ['request_hash' => $requestHash, 'operation' => $operation, 'note' => $input['note'] ?? ''];
            if (in_array($operation, ['correct', 'request_correction', 'approve_correction'], true)) {
                $request = null;
                $movementId = $input['movement_id'] ?? null;
                if ($operation === 'approve_correction') {
                    $request = DB::table('ec_gate_actions')->where('id', $input['action_id'])->where('day_id', $dayId)
                        ->where('kind', 'correction_request')->where('status', 'pending')->first();
                    abort_unless($request, 409, 'Correction request is no longer pending.');
                    abort_if($role !== 'discipline_master' && (int) $request->actor_id === (int) $actor->id, 403, 'A correction requester cannot approve their own request.');
                    $movementId = $request->movement_id;
                    $values['reference_action_id'] = $request->id;
                }
                $movement = DB::table('ec_gate_movements')->where('event_id', $movementId)->where('day_id', $dayId)->first();
                abort_unless($movement && $movement->state === 'active', 409, 'The original movement is no longer active.');
                $link = $this->link($dayId, (int) $movement->observation_id);
                $policy = json_decode($link->policy_snapshot, true, 16, JSON_THROW_ON_ERROR);
                OperatorRules::reason($policy, 'correction', $input['reason_code']);
                if ($request) {
                    $basis = json_decode($request->parameters, true, 16, JSON_THROW_ON_ERROR);
                    abort_unless((int) $request->basis_revision === (int) $day->revision
                        && ($basis['movement_revision'] ?? null) === (int) $movement->revision
                        && ($basis['movement_hash'] ?? null) === $movement->projection_payload_hash, 409, 'Correction request is stale; reassess the current history.');
                }
                $values['observation_id'] = $movement->observation_id;
                $values['movement_id'] = $movement->event_id;
                $values['kind'] = $operation === 'request_correction' ? 'correction_request' : 'exclude';
                $values['status'] = $operation === 'request_correction' ? 'pending' : 'approved';
                $parameters += ['observation_key' => $link->event_key, 'movement_revision' => (int) $movement->revision,
                    'movement_hash' => $movement->projection_payload_hash];
                if ($request) {
                    DB::table('ec_gate_actions')->where('id', $request->id)->update(['status' => 'approved', 'updated_at' => now()->utc()]);
                }
            } elseif ($operation === 'resolve') {
                $link = $this->link($dayId, $input['observation_id']);
                $policy = json_decode($link->policy_snapshot, true, 16, JSON_THROW_ON_ERROR);
                $rules = OperatorRules::reason($policy, 'resolution', $input['reason_code']);
                $metadata = json_decode($link->metadata, true, 16, JSON_THROW_ON_ERROR);
                $at = CarbonImmutable::parse(Instant::normalize($metadata['occurred_at'] ?? null))->utc();
                $age = now()->utc()->getTimestamp() - $at->getTimestamp();
                $decisions = json_decode($day->decisions ?? '{}', true, 16, JSON_THROW_ON_ERROR);
                abort_unless($link->status === 'recognition_candidate' && hash_equals($link->original_fingerprint, $link->fingerprint)
                    && ($decisions[$link->event_key]['reason'] ?? null) === 'first_scan_requires_guard'
                    && $age >= 0 && $age <= OperatorRules::seconds($rules['max_age_seconds'] ?? null),
                    409, 'Only a fresh unresolved direction scan can be resolved here.');
                $values['observation_id'] = $input['observation_id'];
                $parameters += ['observation_key' => $link->event_key, 'movement' => $input['movement']];
            } elseif ($operation === 'permit_exit') {
                abort_unless($day->state === 'inside', 409, 'Early exit requires an unambiguous recorded inside state.');
                [$policy, $policyId, $deviceId] = $this->currentPolicy($day);
                $rules = OperatorRules::reason($policy, 'early_exit', $input['reason_code']);
                $checks = OperatorRules::codes($rules['required_checks'] ?? null);
                $provided = $input['checks'];
                sort($checks);
                sort($provided);
                abort_unless($checks === $provided, 409, 'Complete exactly the school-required release checks.');
                abort_if($input['return_allowed'] && ($policy['return_rules'] ?? null) === null, 409, 'School return timing is not configured.');
                $local = CarbonImmutable::now($policy['timezone']);
                abort_unless(! $policy['closed'] && $local->format('Y-m-d') === $day->day
                    && $local->format('H:i:s') >= $policy['arrival_start'] && $local->format('H:i:s') < $policy['departure_start'],
                    409, 'Early-exit preparation is outside the configured school period.');
                abort_if(DB::table('ec_gate_actions')->where('day_id', $dayId)->where('kind', 'permit_exit')->where('status', 'active')
                    ->where('expires_at', '>', now()->utc())->exists(), 409, 'An exit authorization is already waiting for a scan.');
                $expiry = min(now()->utc()->getTimestamp() + OperatorRules::seconds($rules['validity_seconds'] ?? null),
                    CarbonImmutable::parse($day->day.'T'.$policy['departure_start'], $policy['timezone'])->utc()->getTimestamp());
                if ($actor->gate_source_only) {
                    $until = DB::table('ec_gate_source_actors')->where('admin_id', $actor->id)->value('authority_until');
                    abort_unless($until && CarbonImmutable::parse($until, 'UTC')->isFuture(), 403);
                    $expiry = min($expiry, CarbonImmutable::parse($until, 'UTC')->timestamp);
                }
                $values['expires_at'] = CarbonImmutable::createFromTimestampUTC($expiry);
                $parameters += ['device_id' => $deviceId, 'policy_version' => $policy['version'], 'policy_id' => $policyId,
                    'policy_snapshot' => $policy, 'return_rules_snapshot' => $policy['return_rules'] ?? null,
                    // SQL timestamps have whole-second precision: never admit a pre-approval fractional scan.
                    'not_before' => CarbonImmutable::createFromTimestampUTC(now()->utc()->getTimestamp() + 1)->toIso8601String(),
                    'release_checks' => $checks, 'return_allowed' => $input['return_allowed']];
            } else { // Cancel only a still-unused authorization, retaining a separate audit action.
                $permit = DB::table('ec_gate_actions')->where('id', $input['action_id'])->where('day_id', $dayId)
                    ->where('kind', 'permit_exit')->where('status', 'active')->whereNull('consumed_observation_id')->first();
                abort_unless($permit, 409, 'Only an unused exit authorization can be cancelled.');
                abort_unless($role === 'discipline_master' || (int) $permit->actor_id === (int) $actor->id, 403);
                $old = json_decode($permit->parameters, true, 16, JSON_THROW_ON_ERROR);
                OperatorRules::reason($old['policy_snapshot'] ?? [], 'early_exit', $input['reason_code']);
                DB::table('ec_gate_actions')->where('id', $permit->id)->update(['status' => 'cancelled', 'updated_at' => now()->utc()]);
                $values['reference_action_id'] = $permit->id;
                $values['status'] = 'recorded';
            }
            $values['parameters'] = json_encode($parameters, JSON_THROW_ON_ERROR);
            DB::table('ec_gate_actions')->insert($values);
            if (in_array($values['kind'], ['resolve', 'exclude'], true)) {
                app(DayProjector::class)->rebuild($dayId);
            }
            $updated = DB::table('ec_gate_days')->where('id', $dayId)->sole();

            return $this->result((object) $values, $updated, false);
        }, 3);
    }

    private function link(int $dayId, int $observationId): object
    {
        $row = DB::table('ec_gate_observation_links as l')->join('ec_hikvision_observations as o', 'o.id', '=', 'l.observation_id')
            ->where('l.day_id', $dayId)->where('l.observation_id', $observationId)->first(['o.*', 'l.policy_snapshot', 'l.original_fingerprint']);
        abort_unless($row, 404);

        return $row;
    }

    private function currentPolicy(object $day): array
    {
        $policies = DB::table('ec_gate_policies')->where('tenant_id', $day->tenant_id)->where('school_id', $day->school_id)
            ->where('day', $day->day)->where('enabled', true)->where('effective_from', '<=', now()->utc())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()->utc()))->get()
            ->filter(static function ($p) use ($day): bool {
                return PolicyRoster::includes($p, (int) $day->student_id);
            })->values();
        abort_unless($policies->count() === 1, 409, 'One current school policy is required.');
        $row = $policies->sole();
        $policy = (new DayPolicy(json_decode($row->snapshot, true, 16, JSON_THROW_ON_ERROR)))->values;
        $devices = json_decode($row->device_ids, true, 16, JSON_THROW_ON_ERROR);
        abort_unless(is_array($devices) && array_is_list($devices) && count($devices) === 1 && is_int($devices[0]) && $devices[0] > 0
            && [$policy['tenant_id'], $policy['school_id'], $policy['day'], $policy['version'], $policy['timezone']]
            === [(int) $day->tenant_id, (int) $day->school_id, $day->day, $row->version, $row->timezone],
            409, 'Explicit matching single-gate policy is required.');
        $activeStudent = DB::table('ec_students')->where('id', $day->student_id)->where('tenant_id', $day->tenant_id)
            ->where('school_id', $day->school_id)->whereIn('status', ['active', 'enrolled'])->whereNull('deleted_at')->exists();
        $activeDevice = \App\Services\Biometrics\Devices\DeviceScope::schoolDevices((int) $day->tenant_id, (int) $day->school_id)
            ->where('id', $devices[0])->exists();
        abort_unless($activeStudent && $activeDevice, 409, 'Current student and device eligibility is required.');

        return [$policy, $row->id, $devices[0]];
    }

    private function result(object $action, object $day, bool $replayed): array
    {
        return ['action_id' => $action->id, 'status' => $action->status, 'kind' => $action->kind,
            'day_revision' => (int) $day->revision, 'recorded_state' => $day->state, 'replayed' => $replayed];
    }
}
