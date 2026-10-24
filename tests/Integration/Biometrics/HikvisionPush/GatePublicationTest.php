<?php

use App\Models\AdminUser;
use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\GateWork;
use App\Services\Biometrics\Gate\PolicyRoster;
use App\Services\Biometrics\Gate\PublicationPolicies;
use App\Services\Biometrics\Gate\PublishedCalendar;
use App\Services\Biometrics\Gate\SetupRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T06:00:00Z'));
    $this->f = GateFixture::boot();
    foreach (['2026_10_19_000021_create_hikvision_enrollments', '2026_10_20_000023_add_gate_only_account_restriction',
        '2026_10_21_000024_create_gate_setup_drafts', '2026_10_21_000026_create_hikvision_enrollment_work', '2026_10_22_000027_create_gate_publications'] as $name) {
        (require base_path('database/migrations/'.$name.'.php'))->up();
    }
    DB::table('ec_gate_policies')->delete();
    $this->f['student']->update(['source_system' => 'edu_admin', 'source_id' => '2934', 'device_sync_enabled' => true]);
    $this->f['device']->update(['settings' => ['hikvision_enrollment' => ['enabled' => true, 'verified' => true,
        'model' => 'SYNTHETIC', 'firmware' => 'SYNTHETIC', 'fdid' => '1', 'capacity' => 100, 'unmanaged_count' => 0]]]);
    DB::table('ec_hikvision_push_sessions')->insert(['push_device_id' => 1, 'phase' => 'online', 'challenge' => 'SYNTHETIC',
        'salt' => 'SYNTHETIC', 'iterations' => 100, 'expires_at' => now()->timestamp + 300, 'last_seen_at' => now()]);
    config(['gate.setup_enabled' => true, 'gate.setup_school_ids' => [$this->f['school']->id],
        'gate.activation_enabled' => true, 'gate.activation_release_ready' => true, 'gate.operator_enabled' => true,
        'hikvision_push.enabled' => true, 'hikvision_push.enrollment_enabled' => true,
        'hikvision_push.enrollment_work_enabled' => true, 'hikvision_push.enrollment_device_writes_enabled' => true]);
    $this->actor = AdminUser::create(['name' => 'SYNTHETIC publisher', 'email' => 'publisher@gate-publication.test',
        'password' => 'SYNTHETIC', 'role' => 'school_admin', 'is_active' => true]);
    $scope = ['tenant_id' => $this->f['tenant']->id, 'school_id' => $this->f['school']->id, 'admin_id' => $this->actor->id, 'active' => true];
    DB::table('ec_gate_setup_assignments')->insert($scope);
    DB::table('ec_gate_activation_assignments')->insert($scope);
    foreach (['guard', 'discipline_master'] as $role) {
        DB::table('ec_gate_operator_assignments')->insert($scope + ['role' => $role]);
    }
    Sanctum::actingAs($this->actor, ['admin:*']);
    Http::preventStrayRequests();
    $this->url = '/api/admin/v2/gate/setup/schools/'.$this->f['school']->id;
    $this->c = array_replace(SetupRules::blank(), ['timezone' => 'Africa/Douala', 'weekdays' => range(1, 7),
        'device_ids' => [$this->f['device']->id], 'arrival_start' => '07:00:00', 'arrival_end' => '08:00:00',
        'late_cutoff' => '07:30:00', 'late_at_cutoff' => false, 'departure_start' => '15:00:00', 'departure_end' => '16:00:00',
        'repeat_seconds' => 5, 'reversal_seconds' => 30, 'future_tolerance_seconds' => 0, 'correction_reason_codes' => ['synthetic_mistake']]);
    $this->postJson($this->url, ['expected_revision' => 0, 'idempotency_key' => 'SYNTHETIC-draft', 'configuration' => $this->c])->assertOk();
    $this->body = ['expected_revision' => 1, 'starts_on' => '2026-10-20', 'idempotency_key' => 'SYNTHETIC-publish', 'confirmed' => true];
});

afterEach(function (): void {
    $this->travelBack();
});

it('publishes an immutable saved school revision with prospective policies and no device commands', function (): void {
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.activation.can_activate', true);
    $before = DB::table('ec_biometric_devices')->sole()->settings;
    $this->postJson($this->url.'/activate', $this->body)->assertOk()->assertJsonPath('data.state', 'active')->assertJsonPath('data.replayed', false);
    expect(DB::table('ec_gate_publications')->count())->toBe(1);
    expect(DB::table('ec_gate_policies')->count())->toBe(2);
    $p = DB::table('ec_gate_policies')->where('day', '2026-10-20')->sole();
    expect($p->effective_from)->toBe('2026-10-20 06:00:00');
    expect($p->effective_until)->toBe('2026-10-20 23:00:00');
    expect(json_decode($p->student_ids, true))->toBe([]);
    expect(json_decode($p->snapshot, true))->toMatchArray(['closed' => false, 'return_rules' => null,
        'operator_rules' => ['correction' => ['reason_codes' => ['synthetic_mistake']]]]);
    expect(DB::table('ec_hikvision_enrollments')->count())->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_commands')->count())->toBe(0);
    expect(DB::table('ec_gate_outbox')->count())->toBe(0);
    expect(DB::table('ec_biometric_devices')->sole()->settings)->toBe($before);
    Http::assertNothingSent();
});

it('uses the opted-in secretary staffing model for primary nursery and secondary', function (string $type, bool $ready): void {
    config(['gate.secretary_complex_ids' => [$this->f['tenant']->id]]);
    DB::table('ec_schools')->where('id', $this->f['school']->id)->update(['type' => $type]);
    DB::table('ec_gate_operator_assignments')->where('role', 'guard')->update(['role' => 'secretary']);
    DB::table('ec_gate_operator_assignments')->where('role', 'discipline_master')->delete();
    $response = $this->getJson('/api/admin/v2/gate/setup')->assertOk();
    expect(collect($response->json('data.activation.checks'))->firstWhere('key', 'staff')['ok'])->toBe($ready);
})->with([['primary', true], ['nursery', true], ['secondary', false]]);

it('does not infer activation authority from a draft or super administrator role', function (): void {
    $this->actor->update(['role' => 'super_admin']);
    DB::table('ec_gate_activation_assignments')->delete();
    $this->postJson($this->url.'/activate', $this->body)->assertForbidden();
    expect(DB::table('ec_gate_publications')->count())->toBe(0);
});

it('fails closed on missing release prerequisites', function (string $case): void {
    match ($case) {
        'release' => config(['gate.activation_release_ready' => false]),
        'release_string' => config(['gate.activation_release_ready' => 'true']),
        'activation' => config(['gate.activation_enabled' => false]),
        'scope' => config(['gate.school_ids' => []]),
        'scope_strings' => config(['gate.school_ids' => [(string) $this->f['school']->id]]),
        'gate' => config(['gate.enabled' => false]),
        'mirror' => config(['gate.mirror_delivery_enabled' => false]),
        'operator' => config(['gate.operator_enabled' => false]),
        'receiver' => config(['hikvision_push.enabled' => false]),
        'enrollment' => config(['hikvision_push.enrollment_enabled' => false]),
        'enrollment_worker' => config(['hikvision_push.enrollment_work_enabled' => false]),
        'enrollment_writes' => config(['hikvision_push.enrollment_device_writes_enabled' => false]),
        'staff' => DB::table('ec_gate_operator_assignments')->where('role', 'guard')->delete(),
        'binding' => DB::table('ec_gate_mirror_bindings')->update(['enabled' => false]),
        'contact' => DB::table('ec_hikvision_push_sessions')->update(['last_seen_at' => now()->subSeconds(61)]),
        'expired' => DB::table('ec_hikvision_push_sessions')->update(['expires_at' => now()->timestamp]),
        'device' => DB::table('ec_biometric_devices')->update(['status' => 'inactive']),
    };
    $this->postJson($this->url.'/activate', $this->body)->assertStatus(409);
    expect(DB::table('ec_gate_publications')->count())->toBe(0);
})->with(['release', 'release_string', 'activation', 'scope', 'scope_strings', 'gate', 'mirror', 'operator', 'receiver', 'enrollment', 'enrollment_worker', 'enrollment_writes', 'staff', 'binding', 'contact', 'expired', 'device']);

it('does not silently widen enrollment profiles or ignore capacity and firmware', function (array $patch): void {
    $settings = $this->f['device']->fresh()->settings;
    $settings['hikvision_enrollment'] = array_replace($settings['hikvision_enrollment'], $patch);
    $this->f['device']->update(['settings' => $settings]);
    $this->postJson($this->url.'/activate', $this->body)->assertStatus(409);
    expect(DB::table('ec_biometric_devices')->sole()->settings)->toBe(json_encode($settings));
})->with([
    [['student_ids' => []]], [['student_ids' => [1]]], [['firmware' => 'OTHER']], [['verified' => false]],
    [['capacity' => 0]], [['capacity' => 1, 'unmanaged_count' => 1]], [['unmanaged_count' => -1]], [['unmanaged_count' => '0']],
]);

it('rejects unconfirmed malformed forged or stale publication requests', function (array $patch): void {
    $this->postJson($this->url.'/activate', array_replace($this->body, $patch))->assertStatus(isset($patch['expected_revision']) && is_int($patch['expected_revision']) ? 409 : 422);
    expect(DB::table('ec_gate_publications')->count())->toBe(0);
})->with([
    [['confirmed' => false]], [['confirmed' => 1]], [['expected_revision' => '1']], [['expected_revision' => 2]],
    [['starts_on' => '2026-02-30']], [['starts_on' => '2026-10-19']], [['starts_on' => '2028-01-01']], [['tenant_id' => 999]],
]);

it('deduplicates activation retries after draft changes and never reactivates a paused publication', function (): void {
    $p = $this->postJson($this->url.'/activate', $this->body)->assertOk()->json('data.id');
    $this->postJson($this->url, ['expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-draft2', 'configuration' => []])->assertOk();
    $this->postJson($this->url.'/activate', $this->body)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.draft_revision', 1);
    $this->postJson($this->url.'/activate', array_replace($this->body, ['starts_on' => '2026-10-21']))->assertStatus(409);
    $this->postJson($this->url.'/publications/'.$p.'/pause', ['reason_code' => 'incident', 'idempotency_key' => 'SYNTHETIC-pause'])->assertOk();
    $this->postJson($this->url.'/activate', $this->body)->assertOk()->assertJsonPath('data.state', 'paused');
    expect(DB::table('ec_gate_publication_actions')->count())->toBe(2);
    DB::table('ec_gate_activation_assignments')->update(['active' => false]);
    $this->postJson($this->url.'/activate', $this->body)->assertForbidden();
});

it('pauses with disabled launch gates or offline devices without editing past policies or enrollment', function (): void {
    $p = $this->postJson($this->url.'/activate', $this->body)->assertOk()->json('data.id');
    $snapshot = DB::table('ec_gate_policies')->where('day', '2026-10-20')->value('snapshot');
    $this->travelTo(CarbonImmutable::parse('2026-10-21T07:00:00Z'));
    config(['gate.activation_release_ready' => false, 'gate.activation_enabled' => false, 'gate.enabled' => false]);
    DB::table('ec_hikvision_push_sessions')->delete();
    $body = ['reason_code' => 'configuration_change', 'idempotency_key' => 'SYNTHETIC-pause'];
    $this->postJson($this->url.'/publications/'.$p.'/pause', $body)->assertOk()->assertJsonPath('data.state', 'paused');
    $this->postJson($this->url.'/publications/'.$p.'/pause', $body)->assertOk()->assertJsonPath('data.replayed', true);
    expect(DB::table('ec_gate_policies')->where('day', '2026-10-20')->value('effective_until'))->toBe('2026-10-20 23:00:00');
    expect(DB::table('ec_gate_policies')->where('day', '2026-10-20')->value('snapshot'))->toBe($snapshot);
    expect(DB::table('ec_gate_policies')->where('day', '2026-10-21')->value('effective_until'))->toBe('2026-10-21 07:00:00');
    expect(DB::table('ec_hikvision_enrollment_commands')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rotates calendars idempotently with explicit weekdays and closures, without changing the draft', function (): void {
    $this->c['weekdays'] = [2];
    $this->c['closed_dates'] = ['2026-10-20'];
    $this->postJson($this->url, ['expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-draft2', 'configuration' => $this->c])->assertOk();
    $this->postJson($this->url.'/activate', array_replace($this->body, ['expected_revision' => 2]))->assertOk();
    expect(json_decode(DB::table('ec_gate_policies')->where('day', '2026-10-20')->value('snapshot'), true)['closed'])->toBeTrue();
    $this->travelTo(CarbonImmutable::parse('2026-10-22T06:00:00Z'));
    app(PublicationPolicies::class)->work();
    $before = DB::table('ec_gate_policies')->count();
    app(PublicationPolicies::class)->work();
    expect(DB::table('ec_gate_policies')->count())->toBe($before)->toBe(4);
    expect(json_decode(DB::table('ec_gate_policies')->where('day', '2026-10-22')->value('snapshot'), true)['closed'])->toBeTrue();
    expect(DB::table('ec_gate_setup_drafts')->sole()->revision)->toBe(2);
});

it('keeps earlier held scans frozen and projects only post-activation scans into the school day', function (): void {
    $old = GateFixture::scan($this->f, 1, '06:59:00');
    app(DayProjector::class)->work();
    $this->postJson($this->url.'/activate', $this->body)->assertOk();
    $new = GateFixture::scan($this->f, 2, '07:00:00');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_observation_links')->where('observation_id', $old)->value('error_code'))->toBe('policy_not_configured');
    expect(DB::table('ec_gate_observation_links')->where('observation_id', $new)->value('error_code'))->toBeNull();
    expect(DB::table('ec_gate_observation_links')->where('observation_id', $new)->value('day_id'))->not->toBeNull();
    expect(DB::table('ec_gate_movements')->count())->toBe(1);
    expect(app(\App\Services\Biometrics\Gate\OperatorActions::class)->preview(DB::table('ec_gate_days')->sole())['available'])->toBeFalse();
});

it('rolls back the publication if audit or policy creation fails', function (string $table): void {
    DB::unprepared("CREATE TRIGGER publication_fail BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'SYNTHETIC failure'); END");
    $this->postJson($this->url.'/activate', $this->body)->assertStatus(500);
    expect(DB::table('ec_gate_publications')->count())->toBe(0);
    expect(DB::table('ec_gate_publication_actions')->count())->toBe(0);
    expect(DB::table('ec_gate_policies')->count())->toBe(0);
})->with(['ec_gate_publication_actions', 'ec_gate_policies', 'ec_gate_policy_publications']);

it('holds conflicting policies and incomplete drafts without overwriting them', function (bool $conflict): void {
    if ($conflict) {
        DB::table('ec_gate_policies')->insert(['id' => 'SYNTHETIC-old', 'tenant_id' => $this->f['tenant']->id,
            'school_id' => $this->f['school']->id, 'day' => '2026-10-20', 'timezone' => 'Africa/Douala', 'version' => 'old',
            'snapshot' => '{}', 'student_ids' => '[]', 'device_ids' => '[]', 'effective_from' => '2026-10-20 00:00:00', 'enabled' => true, 'confirmed_by' => 1]);
    } else {
        DB::table('ec_gate_setup_drafts')->update(['configuration' => json_encode(SetupRules::blank())]);
    }
    $this->postJson($this->url.'/activate', $this->body)->assertStatus(409);
    expect(DB::table('ec_gate_publications')->count())->toBe(0);
})->with([true, false]);

it('uses school-local calendar midnights across daylight saving changes', function (string $day, string $from, string $until): void {
    $c = array_replace($this->c, ['timezone' => 'Europe/Paris']);
    $p = (object) ['id' => 1, 'tenant_id' => 1, 'school_id' => 1, 'configuration' => json_encode($c),
        'starts_on' => '2026-01-01', 'activated_at' => '2026-01-01 00:00:00', 'activated_by' => 1];
    $v = PublishedCalendar::policy($p, $day);
    expect($v['effective_from'])->toBe($from);
    expect($v['effective_until'])->toBe($until);
})->with([
    ['2026-03-29', '2026-03-28 23:00:00', '2026-03-29 22:00:00'],
    ['2026-10-25', '2026-10-24 22:00:00', '2026-10-25 23:00:00'],
]);

it('automatically includes future eligible imports without interpreting legacy or forged empty lists as all students', function (): void {
    $this->postJson($this->url.'/activate', $this->body)->assertOk();
    $policy = DB::table('ec_gate_policies')->where('day', '2026-10-20')->sole();
    $future = $this->f['student']->replicate()->fill(['student_number' => 'SYNTHETIC-FUTURE', 'source_id' => '99999']);
    $future->save();
    expect(PolicyRoster::includes($policy, $future->id))->toBeTrue();
    $future->update(['device_sync_enabled' => false]);
    expect(PolicyRoster::includes($policy, $future->id))->toBeFalse();
    $legacy = clone $policy;
    $legacy->snapshot = '{}';
    expect(PolicyRoster::includes($legacy, $this->f['student']->id))->toBeFalse();
    $forged = clone $policy;
    $forged->id = 'SYNTHETIC-unlinked';
    expect(PolicyRoster::includes($forged, $this->f['student']->id))->toBeFalse();
    $future->update(['device_sync_enabled' => true, 'source_system' => 'other']);
    expect(PolicyRoster::includes($policy, $future->id))->toBeFalse();
});

it('reserves combined shared-gate capacity for other assigned schools and pending removals', function (bool $pendingRemoval): void {
    (require base_path('database/migrations/2026_10_21_000025_create_device_registry.php'))->up();
    config(['device_registry.shared_gate_enabled' => true]);
    $other = \App\Models\V2\School::create(['tenant_id' => $this->f['tenant']->id, 'name' => 'SYNTHETIC other school', 'slug' => 'other', 'type' => 'secondary', 'status' => 'active']);
    $s = $this->f['student']->replicate()->fill(['school_id' => $other->id, 'student_number' => 'SYNTHETIC-OTHER', 'source_id' => '99998']);
    $s->save();
    DB::table('ec_device_registry')->insert(['device_id' => $this->f['device']->id, 'serial_key' => 'SYNTHETIC', 'scope_mode' => 'shared_gate', 'updated_by' => $this->actor->id]);
    foreach ([$this->f['school']->id, $other->id] as $id) {
        DB::table('ec_device_school_assignments')->insert(['device_id' => $this->f['device']->id, 'tenant_id' => $this->f['tenant']->id,
            'school_id' => $id, 'epoch' => \Illuminate\Support\Str::uuid(), 'active' => true, 'assigned_by' => $this->actor->id, 'authorized_from' => now()]);
    }
    if ($pendingRemoval) {
        $s->update(['status' => 'suspended']);
        DB::table('ec_hikvision_enrollments')->insert(['push_device_id' => 1, 'tenant_id' => $this->f['tenant']->id, 'school_id' => $other->id,
            'student_id' => $s->id, 'person_identifier' => 'EC'.$s->id, 'state' => 'removal_pending', 'person_owned' => true]);
    }
    $settings = $this->f['device']->fresh()->settings;
    $settings['hikvision_enrollment']['capacity'] = 1;
    $this->f['device']->update(['settings' => $settings]);
    $this->postJson($this->url.'/activate', $this->body)->assertStatus(409);
    $settings['hikvision_enrollment']['capacity'] = 2;
    $this->f['device']->update(['settings' => $settings]);
    $this->postJson($this->url.'/activate', $this->body)->assertOk();
})->with([false, true]);

it('keeps committed delivery working if calendar maintenance fails and redacts the failure', function (): void {
    $this->postJson($this->url.'/activate', $this->body)->assertOk();
    GateFixture::parent($this->f);
    GateFixture::scan($this->f, 1, '07:00:00');
    app(DayProjector::class)->work();
    app()->bind(PublicationPolicies::class, static fn () => throw new RuntimeException('PRIVATE-POLICY-ERROR'));
    config(['gate.mirror_delivery_enabled' => false]);
    $stats = app(GateWork::class)->tick();
    expect($stats['failures'])->toBe(['policy_materialization_failed'])->and($stats['delivery']['sent'])->toBe(1);
    expect(json_encode($stats))->not->toContain('PRIVATE-POLICY-ERROR');
    Http::assertNothingSent();
});

it('provides a read-only deployment checklist without names portraits credentials or writes', function (): void {
    $before = json_encode(DB::table('ec_gate_setup_drafts')->get());
    $exit = Artisan::call('gate:readiness', ['--admin' => (string) $this->actor->id, '--school' => (string) $this->f['school']->id, '--json' => true]);
    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true)['ok'])->toBeTrue();
    foreach (['Synthetic Adult', 'SYNTHETIC publisher', 'credential_encrypted', 'photo_encrypted', 'access_token'] as $private) {
        expect($output)->not->toContain($private);
    }
    expect(json_encode(DB::table('ec_gate_setup_drafts')->get()))->toBe($before);
    expect(DB::table('ec_gate_publications')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps replacement rules on a later local day and never resumes a paused calendar', function (): void {
    $id = $this->postJson($this->url.'/activate', $this->body)->assertOk()->json('data.id');
    $this->postJson($this->url.'/publications/'.$id.'/pause', ['reason_code' => 'planned_pause', 'idempotency_key' => 'SYNTHETIC-pause'])->assertOk();
    app(PublicationPolicies::class)->work();
    $body = array_replace($this->body, ['idempotency_key' => 'SYNTHETIC-replacement']);
    $this->postJson($this->url.'/activate', $body)->assertStatus(409);
    $this->postJson($this->url.'/activate', array_replace($body, ['starts_on' => '2026-10-21']))->assertOk();
    expect(DB::table('ec_gate_publications')->where('state', 'active')->count())->toBe(1);
    expect(DB::table('ec_gate_publications')->where('id', $id)->value('state'))->toBe('paused');
});

function publicationClass(object $test, int $slot): int
{
    $scope = ['tenant_id' => $test->f['tenant']->id, 'school_id' => $test->f['school']->id,
        'status' => 'active', 'source_system' => 'edu_admin', 'source_id' => 'SYNTHETIC-'.$slot];
    $section = DB::table('ec_sections')->insertGetId($scope + ['name' => 'SYNTHETIC section '.$slot]);
    $stream = DB::table('ec_streams')->insertGetId($scope + ['section_id' => $section, 'name' => 'SYNTHETIC stream '.$slot]);

    return DB::table('ec_classes')->insertGetId($scope + ['stream_id' => $stream, 'name' => 'SYNTHETIC class '.$slot, 'full_name' => 'SYNTHETIC class '.$slot]);
}

it('implements the confirmed Baptist normal grace and late minute boundaries', function (string $time, string $reason, bool $late): void {
    $policy = new \App\Services\Biometrics\Gate\DayPolicy(array_replace($this->f['policy'], ['arrival_start' => '06:15:00',
        'arrival_end' => '14:00:00', 'normal_arrival_end' => '07:30:59', 'late_cutoff' => '07:40:00', 'late_at_cutoff' => true,
        'departure_start' => '14:00:00', 'departure_end' => '18:00:00']));
    $scan = ['key' => hash('sha256', $time), 'student_id' => $this->f['student']->id, 'device_id' => $this->f['device']->id,
        'status' => 'recognition_candidate', 'occurred_at' => '2026-10-20T'.$time.'+01:00', 'received_at' => '2026-10-20T'.$time.'+01:00', 'policy' => $policy];
    $result = (new \App\Services\Biometrics\Gate\DayClassifier)->project([$scan]);
    expect(array_values($result['movements'])[0])->toMatchArray(['reason_code' => $reason, 'late' => $late]);
})->with([['06:15:00', 'arrival', false], ['07:30:59', 'arrival', false], ['07:31:00', 'grace_arrival', false],
    ['07:39:59', 'grace_arrival', false], ['07:40:00', 'late_arrival', true]]);

it('opens only chosen classes on an exact extra Saturday and never every Saturday', function (): void {
    $chosen = publicationClass($this, 1);
    $other = publicationClass($this, 2);
    $this->f['student']->update(['class_id' => $chosen, 'source_updated_at' => '2026-10-19 00:00:00']);
    $this->c['weekdays'] = [1, 2, 3, 4, 5];
    $this->c['class_days'] = [['date' => '2026-10-24', 'class_ids' => [$chosen]]];
    $this->postJson($this->url, ['expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-class-day', 'configuration' => $this->c])->assertOk();
    $this->postJson($this->url.'/activate', array_replace($this->body, ['expected_revision' => 2]))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-24T08:00:00Z'));
    app(PublicationPolicies::class)->work();
    $policy = DB::table('ec_gate_policies')->where('day', '2026-10-24')->sole();
    expect(json_decode($policy->snapshot, true))->toMatchArray(['closed' => false, 'class_ids' => [$chosen]]);
    expect(PolicyRoster::includes($policy, $this->f['student']->id, '2026-10-24T07:00:00+01:00'))->toBeTrue();
    $this->f['student']->update(['source_updated_at' => '2026-10-24 07:00:00']);
    expect(PolicyRoster::includes($policy, $this->f['student']->id, '2026-10-24T07:00:00+01:00'))->toBeFalse();
    $this->f['student']->update(['class_id' => $other]);
    expect(PolicyRoster::includes($policy, $this->f['student']->id))->toBeFalse();
    $this->travelTo(CarbonImmutable::parse('2026-10-31T08:00:00Z'));
    app(PublicationPolicies::class)->work();
    expect(json_decode(DB::table('ec_gate_policies')->where('day', '2026-10-31')->value('snapshot'), true)['closed'])->toBeTrue();
});

it('lets an explicit closure win over an extra class date', function (): void {
    $cls = publicationClass($this, 1);
    $c = array_replace($this->c, ['weekdays' => [1, 2, 3, 4, 5], 'closed_dates' => ['2026-10-24'],
        'class_days' => [['date' => '2026-10-24', 'class_ids' => [$cls]]]]);
    $p = (object) ['id' => 1, 'tenant_id' => 1, 'school_id' => 1, 'configuration' => json_encode($c),
        'starts_on' => '2026-10-20', 'activated_at' => '2026-10-20 00:00:00', 'activated_by' => 1];
    expect(json_decode(PublishedCalendar::policy($p, '2026-10-24')['snapshot'], true)['closed'])->toBeTrue();
});

it('rejects foreign malformed or ambiguous extra-class dates and grace boundaries', function (array $patch): void {
    $this->postJson($this->url, ['expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-invalid-class-day',
        'configuration' => array_replace($this->c, $patch)])->assertUnprocessable();
})->with([
    [['class_days' => [['date' => '2026-10-24', 'class_ids' => [99999]]]]],
    [['class_days' => [['date' => '2026-10-24', 'class_ids' => []]]]],
    [['class_days' => [['date' => '2026-10-24', 'class_ids' => ['1']]]]],
    [['class_days' => [['date' => '2026-02-30', 'class_ids' => [1]]]]],
    [['class_days' => [['date' => '2026-10-24', 'class_ids' => [1], 'all_students' => true]]]],
    [['normal_arrival_end' => '08:00:00']],
    [['normal_arrival_end' => '06:00:00']],
]);

it('replays pre-calendar draft requests without widening pilots or changing their original audit hash', function (): void {
    $old = $this->c;
    unset($old['class_days'], $old['normal_arrival_end']);
    $hash = hash('sha256', json_encode(['expected_revision' => 0, 'configuration' => $old]));
    DB::table('ec_gate_setup_revisions')->update(['configuration' => json_encode($old), 'request_hash' => $hash]);
    DB::table('ec_gate_setup_drafts')->update(['configuration' => json_encode($old)]);
    $this->postJson($this->url, ['expected_revision' => 0, 'idempotency_key' => 'SYNTHETIC-draft', 'configuration' => $old])
        ->assertOk()->assertJsonPath('data.replayed', true);
    expect(DB::table('ec_gate_setup_revisions')->sole()->request_hash)->toBe($hash);
    expect(DB::table('ec_gate_setup_revisions')->count())->toBe(1);
});
