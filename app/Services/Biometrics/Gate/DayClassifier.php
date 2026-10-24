<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use DateTimeImmutable;
use InvalidArgumentException;

/** Vendor-neutral occurrence-time classifier. Input actions must already be authenticated and audited. */
final class DayClassifier
{
    public function project(array $observations, array $actions = []): array
    {
        if (count($observations) > 10000 || count($actions) > 1000) {
            throw new InvalidArgumentException('Bounded student/day input required.');
        }
        $scope = null;
        $keys = $seconds = [];
        foreach ($observations as &$observation) {
            $policy = $observation['policy'] ?? null;
            if (! $policy instanceof DayPolicy || ! preg_match('/^[a-f0-9]{64}$/D', $observation['key'] ?? '')
                || isset($keys[$observation['key']]) || ! is_int($observation['student_id'] ?? null) || $observation['student_id'] < 1) {
                throw new InvalidArgumentException('One uniquely identified configured student/day required.');
            }
            $observation['at'] = self::instant($observation['occurred_at']);
            $observation['received'] = self::instant($observation['received_at']);
            $at = $observation['at']->setTimezone($policy->zone);
            $candidateScope = [$policy->values['tenant_id'], $policy->values['school_id'], $observation['student_id'],
                $policy->values['day'], $policy->values['timezone']];
            if (($scope !== null && $candidateScope !== $scope) || $at->format('Y-m-d') !== $policy->values['day']) {
                throw new InvalidArgumentException('Mixed student/day or school policy scope.');
            }
            $scope = $candidateScope;
            $keys[$observation['key']] = true;
            if ($observation['status'] === 'recognition_candidate') {
                $second = $observation['at']->getTimestamp();
                $seconds[$second] = ($seconds[$second] ?? 0) + 1;
            }
        }
        unset($observation);
        foreach ($actions as $action) {
            if ($scope === null || [$action['tenant_id'], $action['school_id'], $action['student_id']] !== array_slice($scope, 0, 3)
                || ! in_array($action['kind'] ?? null, ['resolve', 'permit_exit', 'exclude'], true)
                || ! in_array($action['role'] ?? null, $action['kind'] === 'exclude' ? ['discipline_master', 'correction_approver'] : ['guard', 'secretary', 'discipline_master'], true)
                || ! preg_match('/^[a-f0-9]{64}$/D', $action['id'] ?? '') || ! is_int($action['actor_id'] ?? null)
                || $action['actor_id'] < 1 || ! preg_match('/^[a-z0-9_]{1,64}$/D', $action['reason_code'] ?? '')) {
                throw new InvalidArgumentException('Explicit scoped operator action required.');
            }
        }
        usort($observations, static fn ($a, $b) => ($a['at'] <=> $b['at']) ?: strcmp($a['key'], $b['key']));
        $state = 'not_arrived';
        $blocked = false;
        $lastMovement = $returnPermission = $returnCheckoutAt = null;
        $decisions = $movements = $consumed = [];
        foreach ($observations as $o) {
            $p = $o['policy']->values;
            $at = $o['at'];
            $time = $at->setTimezone($o['policy']->zone)->format('H:i:s');
            $key = $o['key'];
            $linked = array_values(array_filter($actions, static fn ($a) => ($a['observation_key'] ?? null) === $key));
            $excluded = array_filter($linked, static fn ($a) => $a['kind'] === 'exclude');
            $reason = match (true) {
                $o['status'] === 'held_content_conflict' => 'conflicting_observation',
                $excluded !== [] => 'corrected_movement_excluded',
                $o['status'] !== 'recognition_candidate' => null,
                $blocked => 'earlier_unresolved_scan',
                $p['closed'] => 'closed_day',
                $at->getTimestamp() - $o['received']->getTimestamp() > $p['future_tolerance_seconds'] => 'future_clock',
                ($seconds[$at->getTimestamp()] ?? 0) > 1 => 'ambiguous_equal_time_order',
                default => null,
            };
            $type = null;
            $late = false;
            $action = null;
            $decisionSource = 'school_policy';
            $relatedEvent = null;
            if ($reason !== null) {
                $status = 'review';
            } elseif ($o['status'] !== 'recognition_candidate') {
                $reason = 'recognition_not_accepted';
                $status = 'ignored';
            } else {
                $resolutions = array_values(array_filter($linked, static fn ($a) => $a['kind'] === 'resolve'));
                $permits = array_values(array_filter($actions, static fn ($a) => $a['kind'] === 'permit_exit'
                    && ! isset($consumed[$a['id']]) && empty($a['cancelled_at'])
                    && (empty($a['consumed_observation_key']) || $a['consumed_observation_key'] === $key)
                    && (! isset($a['not_before']) || $at >= self::instant($a['not_before']))
                    && $at > self::instant($a['created_at']) && $at <= self::instant($a['expires_at'])));
                if (count($resolutions) > 1 || ($state === 'inside' && count($permits) > 1)) {
                    $reason = 'ambiguous_operator_actions';
                    $status = 'review';
                } elseif ($resolutions !== []) {
                    $action = $resolutions[0];
                    if (! in_array($action['movement'] ?? null, ['check_in', 'check_out'], true)
                        || ($action['movement'] === 'check_in' && $state === 'inside')
                        || ($action['movement'] === 'check_out' && $state === 'outside')) {
                        $reason = 'operator_direction_conflicts_with_state';
                        $status = 'review';
                    } else {
                        $type = $action['movement'];
                        $reason = $action['reason_code'];
                        $status = 'movement';
                    }
                } elseif ($state === 'inside' && $permits !== []) {
                    $action = $permits[0];
                    $returnRules = array_key_exists('return_rules_snapshot', $action)
                        ? $action['return_rules_snapshot'] : ($p['return_rules'] ?? null);
                    if (($action['authority_valid'] ?? true) !== true) {
                        $reason = 'exit_authority_not_current';
                        $status = 'review';
                    } elseif ((isset($action['device_id']) && $action['device_id'] !== $o['device_id'])
                        || (isset($action['policy_version']) && $action['policy_version'] !== $p['version'])) {
                        $reason = 'exit_authorization_scope_changed';
                        $status = 'review';
                    } elseif (($action['return_allowed'] ?? false) && $returnRules === null) {
                        $reason = 'return_policy_not_configured';
                        $status = 'review';
                    } else {
                        $type = 'check_out';
                        $reason = $action['reason_code'];
                        $status = 'movement';
                        $consumed[$action['id']] = $key;
                        $returnPermission = ($action['return_allowed'] ?? false) ? ['earliest' => $at->getTimestamp() + $returnRules['min_seconds'],
                            'latest' => $at->getTimestamp() + $returnRules['max_seconds'],
                            'checkout_delay' => $returnRules['checkout_delay_seconds'],
                            'device_id' => $o['device_id'],
                            'exit_event_id' => hash('sha256', $key.'|'.$p['version'].'|check_out')] : null;
                    }
                } elseif ($state === 'outside' && $returnPermission !== null) {
                    if ($o['device_id'] !== $returnPermission['device_id']) {
                        $reason = 'return_device_mismatch';
                        $status = 'review';
                    } elseif ($at->getTimestamp() < $returnPermission['earliest']) {
                        $reason = 'exit_repeat_before_return_window';
                        $status = 'no_movement';
                    } elseif ($at->getTimestamp() > $returnPermission['latest'] || $time >= $p['departure_end']) {
                        $reason = 'return_permission_expired';
                        $status = 'review';
                    } else {
                        $type = 'check_in';
                        $reason = 'authorized_return';
                        $status = 'movement';
                        $returnCheckoutAt = $at->getTimestamp() + $returnPermission['checkout_delay'];
                        $relatedEvent = $returnPermission['exit_event_id'];
                        $returnPermission = null;
                    }
                } elseif ($state === 'not_arrived' && $time >= $p['departure_start']) {
                    $reason = 'first_scan_requires_guard';
                    $status = 'review';
                } elseif ($time < $p['arrival_start'] || $time >= $p['departure_end']) {
                    $reason = 'outside_schedule';
                    $status = 'review';
                } elseif ($state === 'not_arrived') {
                    $type = 'check_in';
                    $late = $time > $p['late_cutoff'] || ($time === $p['late_cutoff'] && $p['late_at_cutoff']);
                    $reason = $late ? 'late_arrival'
                        : (($p['normal_arrival_end'] ?? null) !== null && $time > $p['normal_arrival_end'] ? 'grace_arrival' : 'arrival');
                    $status = 'movement';
                } elseif ($state === 'inside' && $time >= $p['departure_start']) {
                    if ($returnCheckoutAt !== null && $at->getTimestamp() < $returnCheckoutAt) {
                        $reason = 'return_checkout_delay';
                        $status = 'no_movement';
                    } elseif ($at->getTimestamp() - $lastMovement->getTimestamp() < $p['reversal_seconds']) {
                        $reason = 'rapid_reversal_requires_guard';
                        $status = 'review';
                    } else {
                        $type = 'check_out';
                        $reason = 'departure';
                        $status = 'movement';
                        $returnCheckoutAt = null;
                    }
                } else {
                    $reason = $state === 'inside' ? 'already_inside' : 'already_outside';
                    $status = 'no_movement';
                }
            }
            if ($action !== null && $status === 'movement') {
                // Source contracts retain guard-style provenance; the immutable action retains the actual secretary role.
                $decisionSource = $action['role'] === 'secretary' ? 'guard' : $action['role'];
            }
            $blocked = $blocked || $status === 'review';
            $decisions[$key] = ['status' => $status, 'reason' => $reason, 'policy_version' => $p['version']];
            if ($type !== null) {
                $state = $type === 'check_in' ? 'inside' : 'outside';
                $lastMovement = $at;
                $id = hash('sha256', $key.'|'.$p['version'].'|'.$type);
                $movements[$id] = ['event_id' => $id, 'observation_key' => $key, 'movement' => $type, 'late' => $late,
                    'policy_version' => $p['version'], 'occurred_at' => $o['occurred_at'], 'received_at' => $o['received_at'],
                    'decision_source' => $decisionSource, 'reason_code' => $reason,
                    'related_event_id' => $relatedEvent,
                    'actor_id' => $action !== null ? 'admin:'.$action['actor_id'] : null, 'decision_id' => $action['id'] ?? null];
            }
        }

        return ['state' => $blocked ? 'unknown' : $state, 'needs_review' => $blocked,
            'decisions' => $decisions, 'movements' => $movements, 'consumed' => $consumed];
    }

    private static function instant(string $time): DateTimeImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $time)) {
            throw new InvalidArgumentException('Offset-aware event and action times required.');
        }
        $date = new DateTimeImmutable($time);
        if ($date->format('Y-m-d\TH:i:s') !== substr($time, 0, 19)) {
            throw new InvalidArgumentException('Invalid event calendar time.');
        }

        return $date;
    }
}
