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
    Sanctum::actingAs($this->parent);
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
    Sanctum::actingAs($this->parent);
    $this->getJson('/api/mobile/v2/children/'.$this->gate['student']->id.'/gate-history')->assertNotFound();
})->with(['stranger', 'revoked', 'school', 'hidden']);

it('preserves withdrawn corrections in parent history without exposing operator reasons or identities', function (): void {
    $id = GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_hikvision_observations')->where('id', $id)->update(['status' => 'held_content_conflict']);
    app(DayProjector::class)->work();
    Sanctum::actingAs($this->parent);
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
