<?php

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
});

it('authenticates active admins with bearer tokens', function (): void {
    AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'password' => Hash::make('password-secret'),
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    $loginResponse = $this->postJson('/api/admin/v2/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'password-secret',
        'device_name' => 'Edu-connect web',
    ]);

    $loginResponse->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.admin.email', 'ada@example.com')
        ->assertJsonPath('data.admin.role', 'super_admin');

    $token = $loginResponse->json('data.access_token');
    expect($token)->not->toBeEmpty();

    $this->withToken($token)
        ->getJson('/api/admin/v2/auth/me')
        ->assertOk()
        ->assertJsonPath('data.admin.name', 'Ada Admin');

    $this->withToken($token)
        ->postJson('/api/admin/v2/auth/logout')
        ->assertOk();

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('rejects invalid and inactive admin logins', function (): void {
    AdminUser::query()->create([
        'name' => 'Inactive Admin',
        'email' => 'inactive@example.com',
        'password' => Hash::make('password-secret'),
        'role' => 'school_admin',
        'is_active' => false,
    ]);

    $this->postJson('/api/admin/v2/auth/login', [
        'email' => 'missing@example.com',
        'password' => 'password-secret',
    ])->assertUnprocessable();

    $this->postJson('/api/admin/v2/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'password-secret',
    ])->assertForbidden()
        ->assertJsonPath('message', 'This admin account is inactive.');
});
