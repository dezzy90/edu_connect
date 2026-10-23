<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminAuthUtf8Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\Biometrics\GateFixture;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
    (require base_path('database/migrations/2026_10_20_000023_add_gate_only_account_restriction.php'))->up();
});

it('denies public administrator bootstrap independently of existing roles', function (?string $role): void {
    if ($role) {
        AdminUser::create(['name' => 'SYNTHETIC existing', 'email' => 'existing@example.test',
            'password' => Hash::make('SYNTHETIC-Password-123!'), 'role' => $role, 'is_active' => true]);
    }
    $before = AdminUser::count();
    $this->post('/admin/register', ['name' => 'SYNTHETIC unauthorized', 'email' => 'untrusted@example.test',
        'password' => 'SYNTHETIC-Password-123!', 'password_confirmation' => 'SYNTHETIC-Password-123!', 'role' => 'super_admin'])->assertForbidden();
    $this->get('/admin/register')->assertForbidden();
    $this->postJson('/admin/register', [])->assertForbidden();
    $this->postJson('/admin/register', ['role' => ['super_admin'], 'email' => 'existing@example.test'])->assertForbidden();
    expect(AdminUser::count())->toBe($before)->and(Auth::guard('admin')->check())->toBeFalse();
})->with([null, 'school_admin', 'super_admin']);

it('closes both controller representations even when invoked directly by an authenticated administrator', function (string $controller, string $method): void {
    $admin = AdminUser::create(['name' => 'SYNTHETIC global', 'email' => 'global@example.test',
        'password' => Hash::make('SYNTHETIC-Password-123!'), 'role' => 'super_admin', 'is_active' => true]);
    $this->actingAs($admin, 'admin');
    $request = Request::create('/admin/register', 'POST', ['name' => 'SYNTHETIC unauthorized', 'email' => 'untrusted@example.test',
        'password' => 'SYNTHETIC-Password-123!', 'password_confirmation' => 'SYNTHETIC-Password-123!', 'role' => 'super_admin']);
    try {
        app($controller)->$method($request);
        $this->fail('The legacy registration method must deny before doing any work.');
    } catch (HttpExceptionInterface $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    expect(AdminUser::count())->toBe(1)->and(Auth::guard('admin')->id())->toBe($admin->id);
})->with([
    [AdminAuthUtf8Controller::class, 'showRegister'], [AdminAuthUtf8Controller::class, 'register'],
    [AdminAuthController::class, 'showRegister'], [AdminAuthController::class, 'register'],
]);

it('preserves existing active web administrator login and its inactive and bad-password failures', function (string $case): void {
    $admin = AdminUser::create(['name' => 'SYNTHETIC sign-in', 'email' => 'signin@example.test',
        'password' => Hash::make('SYNTHETIC-Password-123!'), 'role' => 'school_admin', 'is_active' => $case !== 'inactive']);
    $response = $this->from('/admin/login')->post('/admin/login', ['email' => $admin->email,
        'password' => $case === 'bad_password' ? 'incorrect' : 'SYNTHETIC-Password-123!']);
    if ($case === 'active') {
        $response->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin, 'admin');
        expect($admin->fresh()->last_login_at)->not->toBeNull();
    } else {
        $response->assertRedirect('/admin/login')->assertSessionHasErrors('email');
        $this->assertGuest('admin');
        expect($admin->fresh()->last_login_at)->toBeNull();
    }
})->with(['active', 'inactive', 'bad_password']);

it('preserves the restricted setup destination and discards unrelated intended navigation on login', function (): void {
    GateFixture::boot();
    (require base_path('database/migrations/2026_10_20_000023_add_gate_only_account_restriction.php'))->up();
    (require base_path('database/migrations/2026_10_21_000024_create_gate_setup_drafts.php'))->up();
    config(['gate.setup_enabled' => true, 'gate.setup_school_ids' => [1], 'device_registry.enabled' => false]);
    $admin = AdminUser::create(['name' => 'SYNTHETIC setup', 'email' => 'setup@example.test',
        'password' => Hash::make('SYNTHETIC-Password-123!'), 'role' => 'school_admin', 'is_active' => true]);
    $admin->forceFill(['gate_only' => true])->save();
    DB::table('ec_gate_setup_assignments')->insert(['tenant_id' => 1, 'school_id' => 1, 'admin_id' => $admin->id, 'active' => true]);
    $this->withSession(['url.intended' => '/admin/students'])->withHeader('X-Inertia', 'true')->post('/admin/login',
        ['email' => $admin->email, 'password' => 'SYNTHETIC-Password-123!'])->assertStatus(409)
        ->assertHeader('X-Inertia-Location', '/admin/gate/setup')->assertSessionMissing('url.intended');
    $this->assertAuthenticatedAs($admin, 'admin');
});
