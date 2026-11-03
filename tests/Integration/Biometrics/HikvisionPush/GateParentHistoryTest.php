<?php

use App\Models\AdminUser;
use App\Models\V2\School;
use App\Services\Biometrics\Gate\DayProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20T18:00:00Z');
    $this->gate = GateFixture::boot();
    $this->parent = GateFixture::parent($this->gate);
});

afterEach(fn () => Carbon::setTestNow());

it('exposes the current gate history only through the active school-scoped parent link', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $this->getJson('/api/mobile/v2/children/'.$this->gate['student']->id.'/gate-history')->assertOk()
        ->assertJsonPath('data.data.0.event.movement', 'check_in')->assertJsonMissingPath('data.data.0.event.actor_id')
        ->assertJsonMissingPath('data.data.0.event.device_uid')->assertHeader('Cache-Control', 'no-store, private');
});

it('refuses strangers revoked links changed school scope and hidden students', function (string $case): void {
    match ($case) {
        'stranger' => DB::table('ec_parent_student_links')->delete(),
        'revoked' => DB::table('ec_parent_student_links')->update(['status' => 'revoked']),
        'school' => DB::table('ec_parent_student_links')->update(['school_id' => School::create([
            'tenant_id' => $this->gate['tenant']->id, 'name' => 'Other synthetic school', 'slug' => 'other', 'type' => 'secondary', 'status' => 'active',
        ])->id]),
        'hidden' => $this->gate['student']->update(['mobile_visible' => false]),
    };
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $this->getJson('/api/mobile/v2/children/'.$this->gate['student']->id.'/gate-history')->assertNotFound();
})->with(['stranger', 'revoked', 'school', 'hidden']);

it('preserves withdrawn corrections in parent history without exposing operator reasons or identities', function (): void {
    $id = GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_hikvision_observations')->where('id', $id)->update(['status' => 'held_content_conflict']);
    app(DayProjector::class)->work();
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $this->getJson('/api/mobile/v2/children/'.$this->gate['student']->id.'/gate-history')->assertOk()
        ->assertJsonPath('data.data.0.revision', 2)->assertJsonPath('data.data.0.event.state', 'withdrawn')
        ->assertJsonPath('data.data.0.recorded_day_state', 'unknown')->assertJsonMissingPath('data.data.0.event.reason_code');
});

it('does not substitute an admin token for parent authorization', function (): void {
    $admin = AdminUser::create(['name' => 'Synthetic admin', 'email' => 'gate-admin@example.test',
        'password' => bcrypt('synthetic-only'), 'role' => 'super_admin', 'is_active' => true]);
    Sanctum::actingAs($admin);
    $this->getJson('/api/mobile/v2/children/'.$this->gate['student']->id.'/gate-history')->assertForbidden();
});

it('supplies check-in and check-out to existing mobile dashboard and child detail without legacy writes', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    GateFixture::scan($this->gate, 2, '15:30:00');
    app(DayProjector::class)->work();
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $url = '/api/mobile/v2/children/'.$this->gate['student']->id;
    $response = $this->getJson($url.'/attendance?date_from=2026-10-20&date_to=2026-10-20')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.event_type', 'check_out')
        ->assertJsonPath('data.1.event_type', 'check_in')->assertJsonPath('data.1.source', 'gate')
        ->assertJsonPath('data.1.event_time', '2026-10-20T06:30:00.000000Z')
        ->assertJsonMissingPath('data.1.device_uid')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('data.1.id'))->toBeLessThan(0);
    $this->getJson($url)->assertOk()->assertJsonPath('data.recent_attendance.1.id', $response->json('data.1.id'));
    $this->getJson($url.'/attendance?date_from=2026-10-21')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'/attendance?event_type=check_in&limit=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_type', 'check_in');
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
});

it('removes withdrawn movements from the dashboard while preserving gate correction history', function (): void {
    $id = GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_hikvision_observations')->where('id', $id)->update(['status' => 'held_content_conflict']);
    app(DayProjector::class)->work();
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $url = '/api/mobile/v2/children/'.$this->gate['student']->id;
    $this->getJson($url.'/attendance')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'/gate-history')->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.event.state', 'withdrawn');
});

it('does not expose gate dashboard events through a revoked or cross-school link', function (string $case): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_parent_student_links')->update($case === 'revoked' ? ['status' => 'revoked'] : ['school_id' => School::create([
        'tenant_id' => $this->gate['tenant']->id, 'name' => 'Other synthetic school', 'slug' => 'other', 'status' => 'active',
    ])->id]);
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $this->getJson('/api/mobile/v2/children/'.$this->gate['student']->id.'/attendance')->assertNotFound();
})->with(['revoked', 'school']);

it('keeps legacy attendance for schools not using the gate workflow', function (): void {
    config(['gate.enabled' => false]);
    DB::table('ec_attendance_events')->insert(['tenant_id' => $this->gate['tenant']->id,
        'school_id' => $this->gate['school']->id, 'student_id' => $this->gate['student']->id,
        'device_id' => $this->gate['device']->id,
        'event_key' => 'SYNTHETIC-legacy', 'event_type' => 'check_in', 'event_time' => '2026-10-20 06:30:00',
        'verify_status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $this->getJson('/api/mobile/v2/children/'.$this->gate['student']->id.'/attendance')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.event_type', 'check_in')->assertJsonMissingPath('data.0.source');
});

it('filters the existing parent attendance endpoint by school day across UTC midnight', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    $row = DB::table('ec_gate_movements')->sole();
    $payload = json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR);
    $payload['occurred_at'] = '2026-10-19T23:30:00.000000Z';
    DB::table('ec_gate_movements')->where('event_id', $row->event_id)
        ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    Sanctum::actingAs($this->parent, ['mobile:parent']);
    $url = '/api/mobile/v2/children/'.$this->gate['student']->id.'/attendance';
    $this->getJson($url.'?date_from=2026-10-20&date_to=2026-10-20&limit=20')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_time', '2026-10-19T23:30:00.000000Z');
    $this->getJson($url.'?date_from=2026-10-19&date_to=2026-10-19')
        ->assertOk()->assertJsonCount(0, 'data');
});
