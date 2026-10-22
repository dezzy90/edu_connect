<?php

use App\Services\Biometrics\Gate\DayClassifier;
use App\Services\Biometrics\Gate\DayPolicy;

function gateDayPolicy(array $overrides = []): DayPolicy
{
    return new DayPolicy(array_replace(['tenant_id' => 1, 'school_id' => 1, 'version' => 'SYNTHETIC-v1',
        'day' => '2026-10-20', 'timezone' => 'Africa/Douala', 'closed' => false, 'late_at_cutoff' => false,
        'arrival_start' => '07:00:00', 'arrival_end' => '08:00:00', 'late_cutoff' => '08:00:00',
        'departure_start' => '15:00:00', 'departure_end' => '16:00:00', 'repeat_seconds' => 5,
        'reversal_seconds' => 30, 'future_tolerance_seconds' => 300,
        'return_rules' => ['min_seconds' => 60, 'max_seconds' => 3600, 'checkout_delay_seconds' => 60]], $overrides));
}

function gateDayScan(int $id, string $time, array $overrides = []): array
{
    return array_replace(['key' => hash('sha256', (string) $id), 'student_id' => 1, 'device_id' => 1,
        'status' => 'recognition_candidate', 'occurred_at' => '2026-10-20T'.$time.'+01:00',
        'received_at' => '2026-10-20T'.$time.'+01:00', 'policy' => gateDayPolicy()], $overrides);
}

function gateDayAction(string $kind, array $overrides = []): array
{
    return array_replace(['id' => hash('sha256', 'synthetic-action'), 'tenant_id' => 1, 'school_id' => 1,
        'student_id' => 1, 'kind' => $kind, 'role' => 'guard', 'actor_id' => 1, 'reason_code' => 'synthetic_reason',
        'created_at' => '2026-10-20T14:58:00+01:00', 'expires_at' => '2026-10-20T15:00:00+01:00',
        'return_allowed' => true], $overrides);
}

it('automatically arrives departs and ignores repeated scans without toggling direction', function (): void {
    $result = (new DayClassifier)->project([gateDayScan(1, '07:30:00'), gateDayScan(2, '07:30:10'),
        gateDayScan(3, '15:00:00'), gateDayScan(4, '15:00:10')]);
    expect(array_column($result['movements'], 'movement'))->toBe(['check_in', 'check_out']);
    expect($result['state'])->toBe('outside');
    expect($result['needs_review'])->toBeFalse();
});

it('automatically checks in a late first arrival until departure begins', function (): void {
    $result = (new DayClassifier)->project([gateDayScan(1, '10:30:00')]);
    expect(array_values($result['movements'])[0]['late'])->toBeTrue();
    expect(array_values($result['movements'])[0]['reason_code'])->toBe('late_arrival');
});

it('requires the guard for a first departure-period scan and does not invent a morning arrival', function (): void {
    $scan = gateDayScan(1, '15:00:00');
    $result = (new DayClassifier)->project([$scan]);
    expect($result['movements'])->toBe([]);
    expect($result['decisions'][$scan['key']]['reason'])->toBe('first_scan_requires_guard');
    $action = gateDayAction('resolve', ['observation_key' => $scan['key'], 'movement' => 'check_out']);
    $resolved = (new DayClassifier)->project([$scan], [$action]);
    expect(array_column($resolved['movements'], 'movement'))->toBe(['check_out']);
    expect($resolved['state'])->toBe('outside');
});

it('uses original occurrence time for delayed out-of-order scans', function (): void {
    $scans = [gateDayScan(2, '15:00:00'), gateDayScan(1, '07:30:00', ['received_at' => '2026-10-20T15:01:00+01:00'])];
    $result = (new DayClassifier)->project($scans);
    expect(array_column($result['movements'], 'movement'))->toBe(['check_in', 'check_out']);
    expect($result['needs_review'])->toBeFalse();
});

it('never manufactures a movement from denied recognition or a clock timer', function (): void {
    $scan = gateDayScan(1, '07:30:00', ['status' => 'held_authentication_failed']);
    expect((new DayClassifier)->project([$scan])['movements'])->toBe([]);
    $result = (new DayClassifier)->project([gateDayScan(2, '07:31:00')]);
    expect($result['state'])->toBe('inside');
    expect(count($result['movements']))->toBe(1);
});

it('holds closed days future clocks and ambiguous equal-time ordering', function (string $reason): void {
    $scan = gateDayScan(1, '07:30:00');
    $scans = [$scan];
    if ($reason === 'closed_day') {
        $scans[0]['policy'] = gateDayPolicy(['closed' => true]);
    } elseif ($reason === 'future_clock') {
        $scans[0]['received_at'] = '2026-10-20T06:00:00+01:00';
    } else {
        $scans[] = gateDayScan(2, '07:30:00');
    }
    $result = (new DayClassifier)->project($scans);
    expect($result['movements'])->toBe([]);
    expect($result['decisions'][$scan['key']]['reason'])->toBe($reason);
})->with(['closed_day', 'future_clock', 'ambiguous_equal_time_order']);

it('needs a fresh eligible scan to consume an early exit and a one-use authorized return', function (): void {
    $permit = gateDayAction('permit_exit');
    $scans = [gateDayScan(1, '07:30:00'), gateDayScan(2, '14:59:00'), gateDayScan(3, '14:59:10'),
        gateDayScan(4, '15:01:00'), gateDayScan(5, '15:01:20'), gateDayScan(6, '15:02:00'), gateDayScan(7, '15:02:10')];
    $result = (new DayClassifier)->project($scans, [$permit]);
    expect(array_column($result['movements'], 'movement'))->toBe(['check_in', 'check_out', 'check_in', 'check_out']);
    expect($result['consumed'])->toBe([$permit['id'] => $scans[1]['key']]);
    expect($result['decisions'][$scans[2]['key']]['reason'])->toBe('exit_repeat_before_return_window');
    expect($result['decisions'][$scans[4]['key']]['reason'])->toBe('return_checkout_delay');
    $movements = array_values($result['movements']);
    expect($movements[2]['related_event_id'])->toBe($movements[1]['event_id']);
    expect($result['state'])->toBe('outside');
});

it('does not consume an exit authorization with a scan captured before it existed', function (): void {
    $result = (new DayClassifier)->project([gateDayScan(1, '07:30:00'), gateDayScan(2, '14:57:00',
        ['received_at' => '2026-10-20T14:59:00+01:00'])], [gateDayAction('permit_exit')]);
    expect(array_column($result['movements'], 'movement'))->toBe(['check_in']);
    expect($result['consumed'])->toBe([]);
});

it('holds an authorization whose pinned device or policy changed', function (array $pin): void {
    $scan = gateDayScan(2, '14:59:00');
    $result = (new DayClassifier)->project([gateDayScan(1, '07:30:00'), $scan], [gateDayAction('permit_exit', $pin)]);
    expect(count($result['movements']))->toBe(1);
    expect($result['decisions'][$scan['key']]['reason'])->toBe('exit_authorization_scope_changed');
    expect($result['consumed'])->toBe([]);
})->with([[['device_id' => 2]], [['policy_version' => 'SYNTHETIC-changed']]]);

it('preserves school return timing frozen on the authorization and requires the same gate', function (): void {
    $exit = gateDayScan(2, '14:59:00');
    $return = gateDayScan(3, '15:01:00', ['device_id' => 2]);
    $permit = gateDayAction('permit_exit', ['return_rules_snapshot' => ['min_seconds' => 60, 'max_seconds' => 3600, 'checkout_delay_seconds' => 60]]);
    $result = (new DayClassifier)->project([gateDayScan(1, '07:30:00'), $exit, $return], [$permit]);
    expect($result['decisions'][$return['key']]['reason'])->toBe('return_device_mismatch');
    $exit['policy'] = gateDayPolicy(['return_rules' => null]);
    $return['device_id'] = 1;
    $result = (new DayClassifier)->project([gateDayScan(1, '07:30:00'), $exit, $return], [$permit]);
    expect(array_column($result['movements'], 'movement'))->toBe(['check_in', 'check_out', 'check_in']);
});

it('rejects subsecond pre-approval recognition despite whole-second SQL creation timestamps', function (): void {
    $scan = gateDayScan(2, '14:58:00.500');
    $result = (new DayClassifier)->project([gateDayScan(1, '07:30:00'), $scan], [gateDayAction('permit_exit', ['not_before' => '2026-10-20T14:58:01+01:00'])]);
    expect(count($result['movements']))->toBe(1);
    expect($result['consumed'])->toBe([]);
});

it('allows discipline-master decisions directly while preserving role and audit provenance', function (): void {
    $permit = gateDayAction('permit_exit', ['role' => 'discipline_master', 'return_allowed' => false]);
    $result = (new DayClassifier)->project([gateDayScan(1, '07:30:00'), gateDayScan(2, '14:59:00')], [$permit]);
    expect(array_values($result['movements'])[1]['decision_source'])->toBe('discipline_master');
    expect(array_values($result['movements'])[1]['decision_id'])->toBe($permit['id']);
});

it('preserves an excluded raw scan but holds subsequent interpretation for review', function (): void {
    $first = gateDayScan(1, '07:30:00');
    $action = gateDayAction('exclude', ['role' => 'discipline_master', 'observation_key' => $first['key']]);
    $result = (new DayClassifier)->project([$first, gateDayScan(2, '07:31:00')], [$action]);
    expect($result['movements'])->toBe([]);
    expect($result['state'])->toBe('unknown');
    expect($result['decisions'][$first['key']]['reason'])->toBe('corrected_movement_excluded');
});

it('rejects mixed student school or actor scopes instead of borrowing their permissions', function (string $case): void {
    $scans = [gateDayScan(1, '07:30:00')];
    $actions = [];
    if ($case === 'student') {
        $scans[] = gateDayScan(2, '07:31:00', ['student_id' => 2]);
    } elseif ($case === 'school') {
        $scans[] = gateDayScan(2, '07:31:00', ['policy' => gateDayPolicy(['school_id' => 2])]);
    } else {
        $actions[] = gateDayAction('permit_exit', ['school_id' => 2]);
    }
    (new DayClassifier)->project($scans, $actions);
})->throws(InvalidArgumentException::class)->with(['student', 'school', 'actor']);

it('rejects invalid actor identities reasons and unauthorized correction roles', function (array $override): void {
    (new DayClassifier)->project([gateDayScan(1, '07:30:00')], [gateDayAction('exclude', array_replace([
        'role' => 'discipline_master', 'observation_key' => hash('sha256', '1'),
    ], $override))]);
})->throws(InvalidArgumentException::class)->with([
    [['actor_id' => 0]], [['actor_id' => -1]], [['reason_code' => '']], [['reason_code' => 'unbounded private text']], [['role' => 'guard']],
]);
