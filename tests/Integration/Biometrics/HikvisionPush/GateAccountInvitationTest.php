<?php

use App\Models\AdminUser;
use App\Services\Biometrics\Gate\AccountInvitations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->f = GateFixture::boot();
    (require base_path('database/migrations/2026_10_20_000023_add_gate_only_account_restriction.php'))->up();
    (require base_path('database/migrations/2026_10_21_000024_create_gate_setup_drafts.php'))->up();
    (require base_path('database/migrations/2026_10_21_000025_create_device_registry.php'))->up();
    (require base_path('database/migrations/2026_10_23_000028_create_gate_account_invitations.php'))->up();
    config(['app.url' => 'https://setup.test', 'gate.account_invites_enabled' => true, 'gate.setup_school_ids' => [1],
        'device_registry.tenant_ids' => [1], 'device_registry.school_ids' => [1], 'gate.enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->owner = AdminUser::create(['name' => 'SYNTHETIC setup owner', 'email' => 'setup@example.test',
        'password' => bin2hex(random_bytes(32)), 'role' => 'school_admin', 'is_active' => false]);
    $this->owner->forceFill(['gate_only' => true])->save();
    DB::table('ec_gate_setup_assignments')->insert(['tenant_id' => 1, 'school_id' => 1, 'admin_id' => $this->owner->id, 'active' => true]);
});

function gateInvite(object $test): array
{
    $invite = app(AccountInvitations::class)->issue($test->owner->id, 24);
    $path = parse_url($invite['url'], PHP_URL_PATH);

    return [$invite, $path, basename($path)];
}

it('offers a private asset-free form without consuming its link on GET or leaking its token into storage', function (): void {
    [$invite, $path, $token] = gateInvite($this);
    $this->get($path)->assertOk()->assertSee('Choose your password')->assertSee('setup@example.test')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee($token);
    $this->get($path)->assertOk();
    $row = DB::table('ec_gate_account_invitations')->sole();
    expect($row->used_at)->toBeNull()->and($row->token_hash)->toBe(hash('sha256', $token));
    expect(json_encode((array) $row))->not->toContain($token, 'setup@example.test');
    Http::assertNothingSent();
});

it('consumes once atomically without granting global roles staff permissions or enabling any pipeline', function (): void {
    [$invite, $path] = gateInvite($this);
    $password = 'SYNTHETIC-Strong-Only-123!';
    $this->post($path, ['password' => $password, 'password_confirmation' => $password])->assertOk()->assertSee('Your setup account is active');
    $this->owner->refresh();
    expect(Hash::check($password, $this->owner->password))->toBeTrue()->and($this->owner->is_active)->toBeTrue()
        ->and($this->owner->role)->toBe('school_admin')->and($this->owner->gate_only)->toBeTrue()->and($this->owner->school_id)->toBeNull()
        ->and($this->owner->email_verified_at)->toBeNull('A private chat link is not proof of mailbox access.');
    expect(DB::table('ec_gate_account_invitations')->value('used_at'))->not->toBeNull();
    expect(DB::table('ec_gate_account_invitations')->value('token_hash'))->toBeNull();
    $this->get($path)->assertNotFound();
    $this->post($path, ['password' => $password, 'password_confirmation' => $password])->assertNotFound();
    expect(DB::table('ec_gate_operator_assignments')->count())->toBe(0)->and(DB::table('ec_gate_policies')->count())->toBe(1)
        ->and(config('gate.enabled'))->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('requires a strong confirmed password and does not flash password values', function (): void {
    [, $path] = gateInvite($this);
    $this->from($path)->post($path, ['password' => 'weak', 'password_confirmation' => 'other'])->assertRedirect($path)->assertSessionHasErrors('password');
    expect(session()->getOldInput('password'))->toBeNull()->and(session()->getOldInput('password_confirmation'))->toBeNull();
    expect($this->owner->fresh()->is_active)->toBeFalse()->and(DB::table('ec_gate_account_invitations')->value('used_at'))->toBeNull();
});

it('rejects replaced expired tampered disabled or identity-altered invitations', function (string $case): void {
    [, $path] = gateInvite($this);
    match ($case) {
        'replaced' => app(AccountInvitations::class)->issue($this->owner->id, 24),
        'expired' => DB::table('ec_gate_account_invitations')->update(['expires_at' => now()->timestamp]),
        'disabled' => config(['gate.account_invites_enabled' => false]),
        'email_changed' => $this->owner->update(['email' => 'other@example.test']),
        'grant_revoked' => DB::table('ec_gate_setup_assignments')->update(['active' => false]),
        'tampered' => $path = substr($path, 0, -64).str_repeat('0', 64),
        'promoted' => $this->owner->update(['role' => 'super_admin']),
    };
    $this->get($path)->assertNotFound();
    $this->post($path, ['password' => 'SYNTHETIC-Strong-123!', 'password_confirmation' => 'SYNTHETIC-Strong-123!'])->assertNotFound();
    expect($this->owner->fresh()->is_active)->toBeFalse();
})->with(['replaced', 'expired', 'disabled', 'email_changed', 'grant_revoked', 'tampered', 'promoted']);

it('cannot issue links for existing active recovery unscoped or global-admin identities', function (string $case): void {
    match ($case) {
        'active' => $this->owner->update(['is_active' => true]),
        'global' => $this->owner->update(['role' => 'super_admin']),
        'broad' => $this->owner->forceFill(['gate_only' => false])->save(),
        'recovery' => $this->owner->forceFill(['test_recovery_only' => true])->save(),
        'unscoped' => DB::table('ec_gate_setup_assignments')->delete(),
        'verified' => $this->owner->forceFill(['email_verified_at' => now()])->save(),
    };
    expect(fn () => app(AccountInvitations::class)->issue($this->owner->id, 24))->toThrow(RuntimeException::class);
    expect(DB::table('ec_gate_account_invitations')->count())->toBe(0);
})->with(['active', 'global', 'broad', 'recovery', 'unscoped', 'verified']);

it('binds issuance to the trusted HTTPS origin and bounds lifetime', function (string $url, int $hours): void {
    config(['app.url' => $url]);
    expect(fn () => app(AccountInvitations::class)->issue($this->owner->id, $hours))->toThrow(RuntimeException::class);
})->with([['http://setup.test', 24], ['https://user:secret@setup.test', 24], ['https://setup.test?secret=value', 24], ['https://setup.test', 0], ['https://setup.test', 73]]);

it('exports an explicitly requested private link without printing it or its token', function (): void {
    $exit = Artisan::call('gate:invite-setup', ['admin_id' => (string) $this->owner->id, '--private-link' => true]);
    $status = json_decode(Artisan::output(), true, 8, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($status['delivery'])->toBe('private_file');
    $link = trim(file_get_contents($status['private_file']));
    try {
        expect($link)->toStartWith('https://setup.test/admin/gate/account/activate/');
        expect(Artisan::output())->not->toContain($link, basename($link), 'setup@example.test');
    } finally {
        unlink($status['private_file']);
    }
});

it('does not issue an undelivered link through a production log mailer', function (): void {
    $this->app->detectEnvironment(fn () => 'production');
    config(['mail.default' => 'log']);
    expect(Artisan::call('gate:invite-setup', ['admin_id' => (string) $this->owner->id, '--send' => true]))->toBe(1);
    expect(DB::table('ec_gate_account_invitations')->count())->toBe(0);
});
