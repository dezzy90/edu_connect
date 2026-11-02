<?php

use App\Models\AdminUser;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Auth\MobileOtpAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Process\Process;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
    (require base_path('database/migrations/2026_10_23_000028_create_ec_mobile_otp_challenges.php'))->up();
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('e', 32)),
        'mobile_otp.enabled' => true,
        'mobile_otp.cache_store' => 'array',
        'mobile_otp.hmac_key' => str_repeat('h', 32),
        'services.scmc.enabled' => true,
        'services.scmc.api_key' => 'sk_offline_test',
        'services.scmc.otp_template_id' => 'b045f9d9-ba98-4ba1-9884-faab98aa169c',
        'services.scmc.app_name' => 'EduConnect',
    ]);
    Cache::store('array')->flush();
    otpFake();
    $this->freezeTime();
});

function otpFake(mixed $callback = null): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($callback ?? ['api.scmchost.com/*' => Http::response(['success' => true, 'data' => ['sent' => 1]])]);
}

function otpParent(array $attributes = []): ParentAccount
{
    return ParentAccount::query()->create(array_merge([
        'phone' => '+237677223344', 'first_name' => 'Test', 'last_name' => 'Parent', 'status' => 'active',
    ], $attributes));
}

function otpStudent(array $attributes = []): Student
{
    $tenant = Tenant::query()->create(['name' => 'School tenant', 'slug' => (string) Str::uuid(), 'status' => 'active']);
    $school = School::query()->create([
        'tenant_id' => $tenant->id, 'name' => 'School', 'slug' => (string) Str::uuid(), 'status' => 'active',
    ]);

    return Student::query()->create(array_merge([
        'tenant_id' => $tenant->id, 'school_id' => $school->id, 'student_number' => (string) Str::uuid(),
        'first_name' => 'Child', 'last_name' => 'Student', 'parent_name' => 'Do not copy this identity',
        'parent_phone' => '677 223 344', 'status' => 'enrolled', 'mobile_visible' => true,
    ], $attributes));
}

function otpLink(Student $student, array $attributes = []): ParentStudentLink
{
    return ParentStudentLink::query()->create(array_merge([
        'tenant_id' => $student->tenant_id, 'school_id' => $student->school_id,
        'student_id' => $student->id, 'parent_phone' => '+237677223344', 'relationship' => 'guardian',
        'source_system' => 'edu_admin', 'source_id' => (string) Str::uuid(),
        'status' => 'verified', 'linking_code' => 'OTP-CHILD-001',
    ], $attributes));
}

function otpRequest($test, string $phone = '+237677223344'): string
{
    return $test->postJson('/api/mobile/v2/auth/otp/request', ['phone' => $phone])
        ->assertOk()->json('data.challenge_id');
}

function otpFailedRequest($test): string
{
    $test->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '+237677223344'])
        ->assertStatus(503)->assertExactJson([
            'status' => 'error', 'message' => 'Phone sign-in is temporarily unavailable.',
        ]);

    return DB::table('ec_mobile_otp_challenges')->sole()->id;
}

function otpCode(): string
{
    return Http::recorded()->last()[0]['variables']['CODE'];
}

function otpVerify($test, string $id, ?string $code = null)
{
    return $test->postJson('/api/mobile/v2/auth/otp/verify', [
        'challenge_id' => $id, 'code' => $code ?? otpCode(), 'device_name' => 'Offline test device',
    ]);
}

function otpBearer($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->withToken($token);
}

it('returns the challenge contract and issues an indefinite token only after confirmed delivery and verification', function (): void {
    $parent = otpParent();
    $response = $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '677 223 344'])
        ->assertOk();
    expect($response->json('data.expires_in_seconds'))->toBeBetween(299, 300);
    $response->assertExactJson([
        'status' => 'success',
        'data' => [
            'challenge_id' => DB::table('ec_mobile_otp_challenges')->value('id'),
            'phone_masked' => '+**********44', 'expires_in_seconds' => $response->json('data.expires_in_seconds'), 'resend_after_seconds' => 60,
        ],
    ]);
    $id = $response->json('data.challenge_id');
    expect(Str::isUuid($id))->toBeTrue();
    expect(PersonalAccessToken::count())->toBe(0);
    expect($parent->refresh()->phone_verified_at)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['recipients'] === ['+237677223344']
        && $request['variables']['TEMPS'] === '5' && preg_match('/^[0-9]{6}$/D', $request['variables']['CODE']));

    $row = DB::table('ec_mobile_otp_challenges')->first();
    expect(Crypt::decryptString($row->phone_ciphertext))->toBe('+237677223344');
    expect($row->phone_hash)->toBe(hash_hmac('sha256', MobileOtpAuth::PURPOSE.'|phone|+237677223344', str_repeat('h', 32)));
    expect($row->code_hash)->toBe(hash_hmac('sha256', MobileOtpAuth::PURPOSE.'|code|'.$id.'|'.$row->phone_hash.'|'.otpCode(), str_repeat('h', 32)));
    expect(json_encode($row))->not->toContain('+237677223344');
    expect($row->code_hash)->not->toBe(otpCode());
    expect(property_exists($row, 'code'))->toBeFalse();
    expect($row->status)->toBe('sent');

    $verified = otpVerify($this, $id)->assertOk()->assertJsonPath('data.expires_at', null)
        ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.parent.id', $parent->id);
    expect(PersonalAccessToken::sole()->expires_at)->toBeNull();
    expect(PersonalAccessToken::sole()->name)->toBe('Offline test device');
    expect($parent->refresh()->phone_verified_at)->not->toBeNull();
    expect(DB::table('ec_mobile_otp_challenges')->value('consumed_at'))->not->toBeNull();
    otpBearer($this, $verified->json('data.access_token'))->getJson('/api/mobile/v2/me')->assertOk();
});

it('normalizes exact phone variants without rewriting existing account identities', function (string $stored, string $submitted): void {
    $parent = otpParent(['phone' => $stored]);
    $id = otpRequest($this, $submitted);
    otpVerify($this, $id)->assertOk()->assertJsonPath('data.parent.id', $parent->id);
    expect($parent->refresh()->phone)->toBe($stored);
    expect(ParentAccount::count())->toBe(1);
})->with([
    ['677223344', '+237 677 223 344'],
    ['00237677223344', '677223344'],
    ['+237 (677) 223-344', '237677223344'],
    ['237677223344', '00237 677 223 344'],
    ['+33612345678', '0033 6 12 34 56 78'],
]);

it('rejects malformed ambiguous or concatenated phone input', function (string $phone): void {
    otpParent();
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => $phone])->assertUnprocessable();
    Http::assertNothingSent();
    expect(DB::table('ec_mobile_otp_challenges')->count())->toBe(0);
})->with(['+237677223344/+237699001122', 'abc677223344', '+237677223344x2', '++237677223344', '123', '+2376772233449', '33612345678']);

it('never uses suffix matching or guesses an international country code', function (): void {
    $other = otpParent(['phone' => '+33677223344']);
    $id = otpRequest($this, '677223344');
    Http::assertSent(fn ($request) => $request['recipients'] === ['+237677223344']);
    $response = otpVerify($this, $id)->assertOk();
    expect($response->json('data.parent.id'))->not->toBe($other->id);
    expect(ParentAccount::count())->toBe(2);
    expect($other->refresh()->phone_verified_at)->toBeNull();
});

it('uses identical generic failures for inactive deleted and ambiguous accounts', function (string $kind): void {
    $parent = otpParent(['status' => $kind === 'inactive' ? 'inactive' : 'active']);
    if ($kind === 'deleted') {
        $parent->delete();
    }
    if ($kind === 'duplicate') {
        otpParent(['phone' => '677223344']);
    }
    if ($kind === 'inactive duplicate') {
        otpParent(['phone' => '677223344', 'status' => 'inactive']);
    }
    $id = otpFailedRequest($this);
    Http::assertNothingSent();
    expect(DB::table('ec_mobile_otp_challenges')->value('status'))->toBe('decoy');
    otpVerify($this, $id, '123456')->assertUnprocessable()->assertExactJson([
        'status' => 'error', 'message' => 'This code is invalid or expired. Request a new code.',
    ]);
    expect(PersonalAccessToken::count())->toBe(0);
})->with(['inactive', 'deleted', 'duplicate', 'inactive duplicate']);

it('registers a new phone only after verification without requiring any school record', function (): void {
    $id = otpRequest($this);
    expect(ParentAccount::count())->toBe(0);
    expect(Student::count())->toBe(0);
    expect(DB::table('ec_mobile_otp_challenges')->value('subject_type'))->toBe('phone');
    Http::assertSentCount(1);
    $code = otpCode();
    otpVerify($this, $id, $code === '000000' ? '111111' : '000000')->assertUnprocessable();
    expect(ParentAccount::count())->toBe(0);
    $response = otpVerify($this, $id, $code)->assertOk();
    $parent = ParentAccount::sole();
    expect($parent->phone)->toBe('+237677223344');
    expect($parent->password_hash)->toBeNull();
    expect($parent->phone_verified_at)->not->toBeNull();
    expect(ParentStudentLink::count())->toBe(0);
    otpBearer($this, $response->json('data.access_token'))->getJson('/api/mobile/v2/children')
        ->assertOk()->assertJsonCount(0, 'data');
    $this->flushHeaders();
    $this->travel(60)->seconds();
    $next = otpRequest($this, '677 223 344');
    otpVerify($this, $next)->assertOk()->assertJsonPath('data.parent.id', $parent->id);
    expect(ParentAccount::count())->toBe(1);
});

it('keeps new-phone registration separate from school contacts imported while awaiting verification', function (): void {
    $id = otpRequest($this);
    $student = otpStudent();
    $link = otpLink($student);
    $response = otpVerify($this, $id)->assertOk();
    expect(ParentAccount::count())->toBe(1);
    expect($link->refresh()->parent_account_id)->toBeNull();
    $token = $response->json('data.access_token');
    otpBearer($this, $token)->getJson('/api/mobile/v2/children')->assertOk()->assertJsonCount(0, 'data');
    otpBearer($this, $token)->getJson('/api/mobile/v2/children/'.$student->id)->assertNotFound();
    otpBearer($this, $token)->postJson('/api/mobile/v2/children/link', ['linking_code' => 'WRONG'])->assertUnprocessable();
    otpBearer($this, $token)->postJson('/api/mobile/v2/children/link', ['linking_code' => 'OTP-CHILD-001'])
        ->assertCreated()->assertJsonPath('data.status', 'active');
});

it('does not merge a new-phone challenge into an account created before verification', function (string $state): void {
    $id = otpRequest($this);
    $parent = otpParent(['status' => $state === 'inactive' ? 'inactive' : 'active']);
    if ($state === 'deleted') {
        $parent->delete();
    }
    otpVerify($this, $id)->assertUnprocessable();
    expect(ParentAccount::withTrashed()->count())->toBe(1);
    expect(PersonalAccessToken::count())->toBe(0);
    expect($parent->refresh()->phone_verified_at)->toBeNull();
})->with(['active', 'inactive', 'deleted']);

it('rechecks revoked deleted and bound link guards for a new-phone challenge', function (string $state): void {
    $id = otpRequest($this);
    $student = otpStudent();
    match ($state) {
        'revoked' => otpLink($student, ['status' => 'revoked']),
        'deleted' => otpLink($student)->delete(),
        'bound' => otpLink($student, ['parent_account_id' => otpParent(['phone' => '+237699001122'])->id]),
    };
    otpVerify($this, $id)->assertUnprocessable();
    expect(ParentAccount::count())->toBe($state === 'bound' ? 1 : 0);
    expect(PersonalAccessToken::count())->toBe(0);
})->with(['revoked', 'deleted', 'bound']);

it('rejects an old decoy challenge after opening new-parent registration', function (): void {
    $id = otpRequest($this);
    DB::table('ec_mobile_otp_challenges')->where('id', $id)->update([
        'status' => 'decoy', 'subject_type' => null, 'subject_id' => null, 'sent_at' => null,
    ]);
    otpVerify($this, $id)->assertUnprocessable();
    expect(ParentAccount::count())->toBe(0);
});

it('creates a parent only after verifying an active school contact and leaves every child link untouched', function (string $source): void {
    $student = otpStudent(['parent_phone' => $source === 'student' ? '677223344' : null]);
    $link = $source === 'guardian' ? otpLink($student) : null;
    $id = otpRequest($this);
    expect(ParentAccount::count())->toBe(0);
    $response = otpVerify($this, $id)->assertOk();
    $parent = ParentAccount::sole();
    expect($parent->phone)->toBe('+237677223344');
    expect($parent->password_hash)->toBeNull();
    expect($parent->first_name)->toBe('');
    expect($parent->email)->toBeNull();
    expect($parent->studentLinks()->count())->toBe(0);
    if ($link) {
        expect($link->refresh()->parent_account_id)->toBeNull();
        expect($link->status)->toBe('verified');
    }
    $token = $response->json('data.access_token');
    otpBearer($this, $token)->getJson('/api/mobile/v2/children')->assertOk()->assertJsonCount(0, 'data');
    otpBearer($this, $token)->getJson('/api/mobile/v2/children/'.$student->id)->assertNotFound();
    if ($link) {
        otpBearer($this, $token)->postJson('/api/mobile/v2/children/link', ['linking_code' => 'WRONG'])->assertUnprocessable();
        otpBearer($this, $token)->postJson('/api/mobile/v2/children/link', ['linking_code' => 'OTP-CHILD-001'])
            ->assertCreated()->assertJsonPath('data.status', 'active');
    }
})->with(['student', 'guardian']);

it('does not merge multiple school identities or grant their child access for a shared phone', function (): void {
    otpStudent();
    otpStudent(['parent_name' => 'Different name']);
    $id = otpRequest($this);
    otpVerify($this, $id)->assertOk();
    expect(ParentAccount::count())->toBe(1);
    expect(ParentAccount::sole()->first_name)->toBe('');
    expect(ParentStudentLink::count())->toBe(0);
});

it('allows independent signup without granting access to inactive hidden or unrelated school records', function (string $kind): void {
    $student = otpStudent();
    match ($kind) {
        'student inactive' => $student->update(['status' => 'inactive']),
        'student hidden' => $student->update(['mobile_visible' => false]),
        'student deleted' => $student->delete(),
        'school inactive' => $student->school->update(['status' => 'inactive']),
        'school deleted' => $student->school->delete(),
        'tenant inactive' => $student->tenant->update(['status' => 'inactive']),
        'tenant deleted' => $student->tenant->delete(),
        'emergency only' => $student->update(['parent_phone' => null, 'emergency_contact_phone' => '+237677223344']),
        'non guardian' => [$student->update(['parent_phone' => null]), otpLink($student, ['relationship' => 'driver'])],
        'pending only' => [$student->update(['parent_phone' => null]), otpLink($student, ['status' => 'pending'])],
        'tenant mismatch' => $student->school->update(['tenant_id' => otpStudent(['parent_phone' => null])->tenant_id]),
    };
    $id = otpRequest($this);
    Http::assertSentCount(1);
    $response = otpVerify($this, $id)->assertOk();
    expect(ParentAccount::count())->toBe(1);
    expect(ParentAccount::sole()->studentLinks()->count())->toBe(0);
    $token = $response->json('data.access_token');
    otpBearer($this, $token)->getJson('/api/mobile/v2/children')->assertOk()->assertJsonCount(0, 'data');
    otpBearer($this, $token)->getJson('/api/mobile/v2/children/'.$student->id)->assertNotFound();
})->with([
    'student inactive', 'student hidden', 'student deleted', 'school inactive', 'school deleted',
    'tenant inactive', 'tenant deleted', 'emergency only', 'non guardian', 'pending only', 'tenant mismatch',
]);

it('does not bypass revoked deleted or already bound link guards during signup', function (string $kind): void {
    $student = otpStudent();
    match ($kind) {
        'revoked' => otpLink($student, ['status' => 'revoked']),
        'deleted' => otpLink($student)->delete(),
        'bound' => otpLink($student, ['parent_account_id' => otpParent(['phone' => '+237699001122'])->id]),
    };
    otpFailedRequest($this);
    Http::assertNothingSent();
    expect(PersonalAccessToken::count())->toBe(0);
})->with(['revoked', 'deleted', 'bound']);

it('does not recover an inactive or deleted parent through an otherwise eligible contact', function (bool $deleted): void {
    $parent = otpParent(['status' => $deleted ? 'active' : 'inactive']);
    if ($deleted) {
        $parent->delete();
    }
    otpStudent();
    otpFailedRequest($this);
    Http::assertNothingSent();
    expect(ParentAccount::withTrashed()->count())->toBe(1);
})->with([false, true]);

it('rechecks parent identity status and duplicates when redeeming a code', function (string $change): void {
    $parent = otpParent();
    $id = otpRequest($this);
    match ($change) {
        'disabled' => $parent->update(['status' => 'inactive']),
        'deleted' => $parent->delete(),
        'phone changed' => $parent->update(['phone' => '+237699001122']),
        'duplicate' => otpParent(['phone' => '677223344']),
        'replacement' => [$parent->forceDelete(), otpParent()],
    };
    otpVerify($this, $id)->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
})->with(['disabled', 'deleted', 'phone changed', 'duplicate', 'replacement']);

it('rechecks the same contact proof at redemption and never silently switches identities', function (string $change): void {
    $student = otpStudent(['parent_phone' => null]);
    $link = otpLink($student);
    $id = otpRequest($this);
    match ($change) {
        'revoked' => $link->update(['status' => 'revoked']),
        'phone changed' => $link->update(['parent_phone' => '+237699001122']),
        'student hidden' => $student->update(['mobile_visible' => false]),
        'school disabled' => $student->school->update(['status' => 'inactive']),
        'new parent' => otpParent(),
        'alternate contact' => [$link->delete(), otpStudent()],
    };
    otpVerify($this, $id)->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
    expect(ParentAccount::count())->toBe($change === 'new parent' ? 1 : 0);
})->with(['revoked', 'phone changed', 'student hidden', 'school disabled', 'new parent', 'alternate contact']);

it('accepts at 299 seconds and rejects exactly at the 300 second expiry', function (int $seconds, int $status): void {
    otpParent();
    $id = otpRequest($this);
    $this->travel($seconds)->seconds();
    otpVerify($this, $id)->assertStatus($status);
})->with([[299, 200], [300, 422], [301, 422]]);

it('persists wrong attempts and exhausts a code after five failures', function (): void {
    otpParent();
    $id = otpRequest($this);
    $code = otpCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        otpVerify($this, $id, $wrong)->assertUnprocessable();
        expect(DB::table('ec_mobile_otp_challenges')->value('attempts'))->toBe($attempt);
    }
    otpVerify($this, $id, $code)->assertUnprocessable();
    expect(DB::table('ec_mobile_otp_challenges')->value('status'))->toBe('exhausted');
    expect(PersonalAccessToken::count())->toBe(0);
});

it('allows the correct fifth attempt but cannot consume twice even without cache history', function (): void {
    otpParent();
    $id = otpRequest($this);
    $code = otpCode();
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        otpVerify($this, $id, $code === '000000' ? '111111' : '000000')->assertUnprocessable();
    }
    otpVerify($this, $id, $code)->assertOk();
    Cache::store('array')->flush();
    otpVerify($this, $id, $code)->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(1);
    expect(DB::table('ec_mobile_otp_challenges')->value('attempts'))->toBe(5);
});

it('atomically rolls back account token and consumption if token issuance fails', function (bool $schoolContact): void {
    if ($schoolContact) {
        otpStudent();
    }
    $id = otpRequest($this);
    expect(fn () => app(MobileOtpAuth::class)->verify($id, otpCode(), '127.0.0.1', function ($parent) {
        $parent->createToken('rollback', ['mobile:parent']);
        throw new RuntimeException('simulated failure');
    }))->toThrow(RuntimeException::class);
    expect(ParentAccount::count())->toBe(0);
    expect(PersonalAccessToken::count())->toBe(0);
    expect(DB::table('ec_mobile_otp_challenges')->value('consumed_at'))->toBeNull();
    otpVerify($this, $id)->assertOk();
    expect(ParentAccount::count())->toBe(1);
})->with([true, false]);

it('rejects other purposes and codes copied from other challenges', function (string $change): void {
    otpParent();
    $id = otpRequest($this);
    if ($change === 'purpose') {
        DB::table('ec_mobile_otp_challenges')->where('id', $id)->update(['purpose' => 'password-reset']);
    } elseif ($change === 'challenge') {
        $newId = (string) Str::uuid();
        DB::table('ec_mobile_otp_challenges')->where('id', $id)->update(['id' => $newId]);
        $id = $newId;
    } else {
        DB::table('ec_mobile_otp_challenges')->where('id', $id)->update(['phone_ciphertext' => Crypt::encryptString('+237699001122')]);
    }
    otpVerify($this, $id)->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
})->with(['purpose', 'challenge', 'phone']);

it('enforces durable normalized-phone cooldown and invalidates old codes on resend', function (): void {
    otpParent();
    $oldId = otpRequest($this);
    $oldCode = otpCode();
    Cache::store('array')->flush();
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '677 223 344'])->assertStatus(429)->assertHeader('Retry-After', '60');
    $this->travel(59)->seconds();
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '00237677223344'])->assertStatus(429)->assertHeader('Retry-After', '1');
    $this->travel(1)->seconds();
    $newId = otpRequest($this, '237677223344');
    expect($newId)->not->toBe($oldId);
    otpVerify($this, $oldId, $oldCode)->assertUnprocessable();
    otpVerify($this, $newId)->assertOk();
    Http::assertSentCount(2);
});

it('limits requests per phone across IPs for known and unknown accounts', function (bool $known): void {
    if ($known) {
        otpParent();
    }
    for ($index = 0; $index < 5; $index++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)]);
        otpRequest($this);
        $this->travel(60)->seconds();
    }
    Cache::store('array')->flush();
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '+237677223344'])->assertStatus(429);
    $this->travel(3300)->seconds();
    otpRequest($this);
    Http::assertSentCount(6);
})->with([true, false]);

it('limits request and verify IP traffic independently with shared-cache counters', function (): void {
    config(['mobile_otp.requests_per_ip_window' => 2, 'mobile_otp.verifications_per_ip_window' => 2]);
    otpRequest($this, '+237677223341');
    otpRequest($this, '+237677223342');
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '+237677223343'])->assertStatus(429)->assertHeader('Retry-After', '600');
    $id = (string) Str::uuid();
    otpVerify($this, $id, '123456')->assertUnprocessable();
    otpVerify($this, $id, '123456')->assertUnprocessable();
    otpVerify($this, $id, '123456')->assertStatus(429);
    $this->travel(600)->seconds();
    otpRequest($this, '+237677223343');
    otpVerify($this, $id, '123456')->assertUnprocessable();
});

it('fails closed for either disabled feature flag including redemption of an already sent code', function (string $flag): void {
    otpParent();
    $id = otpRequest($this);
    $code = otpCode();
    otpFake();
    config([$flag => false]);
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '+237677223344'])->assertStatus(503);
    otpVerify($this, $id, $code)->assertStatus(503);
    Http::assertNothingSent();
    expect(PersonalAccessToken::count())->toBe(0);
})->with(['mobile_otp.enabled', 'services.scmc.enabled']);

it('has rollout flags disabled and numeric validity formatting by default', function (): void {
    expect((require config_path('mobile_otp.php'))['enabled'])->toBeFalse();
    expect((require config_path('mobile_otp.php'))['validity_format'])->toBe('{minutes}');
    expect((require config_path('services.php'))['scmc']['enabled'])->toBeFalse();
});

it('keeps provider rejection timeout and malformed replies generic and never issues their tokens', function (string $failure): void {
    otpParent();
    Log::spy();
    $sentCode = null;
    otpFake(function ($request) use ($failure, &$sentCode) {
        $sentCode = $request['variables']['CODE'];
        if ($failure === 'timeout') {
            throw new ConnectionException('Sensitive '.$sentCode.' sk_offline_test');
        }

        return match ($failure) {
            'rejected' => Http::response(['success' => false, 'error' => 'Sensitive '.$sentCode], 500),
            'zero' => Http::response(['success' => true, 'data' => ['sent' => 0]]),
            default => Http::response('Sensitive '.$sentCode),
        };
    });
    $id = otpFailedRequest($this);
    expect(DB::table('ec_mobile_otp_challenges')->value('status'))->toBe('failed');
    otpVerify($this, $id, $sentCode)->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
    expect(DB::table('ec_mobile_otp_challenges')->value('sent_at'))->toBeNull();
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
})->with(['timeout', 'rejected', 'zero', 'malformed']);

it('uses generic failure for missing SCMC credentials without sending or authenticating', function (): void {
    otpParent();
    config(['services.scmc.api_key' => '']);
    $id = otpFailedRequest($this);
    Http::assertNothingSent();
    otpVerify($this, $id, '123456')->assertUnprocessable();
});

it('requires a shared production store and fails closed for a missing HMAC key', function (string $failure): void {
    otpParent();
    if ($failure === 'store') {
        $this->app->instance('env', 'production');
    } else {
        config(['mobile_otp.hmac_key' => '']);
    }
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '+237677223344'])->assertStatus(503);
    Http::assertNothingSent();
})->with(['store', 'key']);

it('rejects requests and verification while the shared phone lock is held', function (): void {
    otpParent();
    $id = otpRequest($this);
    $hash = DB::table('ec_mobile_otp_challenges')->value('phone_hash');
    $lock = Cache::store('array')->lock('mobile-otp:phone:'.$hash, 60);
    expect($lock->get())->toBeTrue();
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => '+237677223344'])->assertStatus(429);
    otpVerify($this, $id)->assertStatus(429);
    $lock->release();
    otpVerify($this, $id)->assertOk();
    expect(PersonalAccessToken::count())->toBe(1);
});

it('does not accept a code during an unconfirmed send even after cache lock expiry', function (): void {
    otpParent();
    otpFake(function ($request) {
        $row = DB::table('ec_mobile_otp_challenges')->first();
        expect($row->status)->toBe('pending');
        Cache::store('array')->lock('mobile-otp:phone:'.$row->phone_hash)->forceRelease();
        otpVerify($this, $row->id, $request['variables']['CODE'])->assertUnprocessable();
        expect(PersonalAccessToken::count())->toBe(0);

        return Http::response(['success' => true, 'data' => ['sent' => 1]]);
    });
    $id = otpRequest($this);
    otpVerify($this, $id)->assertOk();
});

it('cannot reactivate a superseded challenge when a stalled send completes late', function (): void {
    otpParent();
    otpFake(function () {
        DB::table('ec_mobile_otp_challenges')->update(['status' => 'superseded']);

        return Http::response(['success' => true, 'data' => ['sent' => 1]]);
    });
    $id = otpFailedRequest($this);
    expect(DB::table('ec_mobile_otp_challenges')->value('status'))->toBe('superseded');
    otpVerify($this, $id)->assertUnprocessable();
});

it('validates six digits as a string and accepts leading zeroes', function (): void {
    otpParent();
    $id = otpRequest($this);
    $row = DB::table('ec_mobile_otp_challenges')->first();
    DB::table('ec_mobile_otp_challenges')->where('id', $id)->update([
        'code_hash' => hash_hmac('sha256', MobileOtpAuth::PURPOSE.'|code|'.$id.'|'.$row->phone_hash.'|012345', str_repeat('h', 32)),
    ]);
    foreach (['12345', '1234567', 'abc123', "012345\n", 123456] as $code) {
        $this->postJson('/api/mobile/v2/auth/otp/verify', ['challenge_id' => $id, 'code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }
    otpVerify($this, $id, '012345')->assertOk();
});

it('keeps mobile sessions indefinitely but still enforces logout disabled accounts and revocation', function (string $revoke): void {
    config(['sanctum.expiration' => 1]);
    $parent = otpParent();
    $id = otpRequest($this);
    $token = otpVerify($this, $id)->assertOk()->json('data.access_token');
    $this->travel(2)->years();
    otpBearer($this, $token)->getJson('/api/mobile/v2/me')->assertOk();
    match ($revoke) {
        'logout' => otpBearer($this, $token)->postJson('/api/mobile/v2/auth/logout')->assertOk(),
        'disabled' => $parent->update(['status' => 'inactive']),
        'deleted' => $parent->delete(),
        'revoked' => $parent->tokens()->delete(),
    };
    otpBearer($this, $token)->getJson('/api/mobile/v2/me')->assertUnauthorized();
})->with(['logout', 'disabled', 'deleted', 'revoked']);

it('never exempts nonmobile tokens or explicitly expiring mobile tokens from Sanctum limits', function (): void {
    config(['sanctum.expiration' => 1]);
    $parent = otpParent();
    $persistent = $parent->createToken('mobile', ['mobile:parent'])->plainTextToken;
    $other = $parent->createToken('other', ['other'])->plainTextToken;
    $expiring = $parent->createToken('legacy', ['mobile:parent'], now()->addSecond())->plainTextToken;
    $this->travel(2)->minutes();
    otpBearer($this, $other)->getJson('/api/mobile/v2/me')->assertUnauthorized();
    otpBearer($this, $expiring)->getJson('/api/mobile/v2/me')->assertUnauthorized();
    otpBearer($this, $persistent)->getJson('/api/user')->assertUnauthorized();
    otpBearer($this, $persistent)->getJson('/api/mobile/v2/me')->assertOk();
    config(['auth.guards.sanctum.provider' => 'admin_users']);
    otpBearer($this, $persistent)->getJson('/api/mobile/v2/me')->assertUnauthorized();
});

it('does not loosen admin expiration and protects persistent mobile sessions from the standard cleanup command', function (): void {
    config(['sanctum.expiration' => 1]);
    $parent = otpParent();
    $persistent = $parent->createToken('mobile', ['mobile:parent']);
    $parent->createToken('legacy', ['mobile:parent'], now()->addSecond());
    $parent->createToken('other', ['other']);
    $admin = AdminUser::query()->create([
        'name' => 'Admin', 'email' => 'otp-admin@example.test', 'password' => Hash::make('password-secret'),
        'role' => 'super_admin', 'is_active' => true,
    ]);
    $adminToken = $admin->createToken('admin', ['admin:*']);
    $this->travel(2)->days();
    otpBearer($this, $adminToken->plainTextToken)->getJson('/api/admin/v2/auth/me')->assertUnauthorized();
    $this->artisan('sanctum:prune-expired', ['--hours' => 0])->assertSuccessful();
    expect(PersonalAccessToken::count())->toBe(1);
    expect(PersonalAccessToken::sole()->id)->toBe($persistent->accessToken->id);
    otpBearer($this, $persistent->plainTextToken)->getJson('/api/mobile/v2/me')->assertOk();
});

it('keeps password login working while OTP is disabled and does not change existing passwords', function (): void {
    $parent = otpParent(['password_hash' => Hash::make('password-secret')]);
    $original = $parent->password_hash;
    $id = otpRequest($this);
    otpVerify($this, $id)->assertOk();
    expect($parent->refresh()->password_hash)->toBe($original);
    config(['mobile_otp.enabled' => false, 'services.scmc.enabled' => false]);
    $this->postJson('/api/mobile/v2/auth/login', ['phone' => $parent->phone, 'password' => 'password-secret'])
        ->assertOk()->assertJsonPath('data.expires_at', null);
});

it('rejects inactive and deleted parent tokens at Sanctum regardless of token age', function (int $years, string $state): void {
    config(['sanctum.expiration' => 1]);
    $parent = otpParent();
    $issued = $parent->createToken('established', ['mobile:parent']);
    if ($years > 0) {
        $this->travel($years)->years();
    }

    otpBearer($this, $issued->plainTextToken)->getJson('/api/mobile/v2/me')->assertOk();
    $state === 'deleted' ? $parent->delete() : $parent->update(['status' => 'inactive']);

    expect($issued->accessToken->fresh())->not->toBeNull();
    otpBearer($this, $issued->plainTextToken)->getJson('/api/mobile/v2/me')->assertUnauthorized();
    otpBearer($this, $issued->plainTextToken)->getJson('/api/mobile/v2/children')->assertUnauthorized();
})->with([
    'young inactive' => [0, 'inactive'],
    'old inactive' => [2, 'inactive'],
    'young deleted' => [0, 'deleted'],
    'old deleted' => [2, 'deleted'],
]);

it('rejects wrong abilities on otherwise unexpired established tokens', function (): void {
    $parent = otpParent();
    $token = $parent->createToken('established', ['mobile:parent'])->plainTextToken;
    otpBearer($this, $token)->getJson('/api/mobile/v2/me')->assertOk();
    $other = $parent->createToken('wrong ability', ['other'])->plainTextToken;
    otpBearer($this, $other)->getJson('/api/mobile/v2/me')->assertForbidden();
});

it('keeps template configuration server controlled and reports remaining TTL after provider latency', function (): void {
    otpParent();
    config([
        'services.scmc.otp_template_id' => 'd056f9d9-ba98-4ba1-9884-faab98aa169c',
        'mobile_otp.validity_format' => '{minutes}',
    ]);
    otpFake(function ($request) {
        expect($request['template_id'])->toBe('d056f9d9-ba98-4ba1-9884-faab98aa169c');
        expect(array_keys($request['variables']))->toBe(['NOM_APP', 'CODE', 'TEMPS']);
        $this->travel(2)->seconds();

        return Http::response(['success' => true, 'data' => ['sent' => 1]]);
    });
    $response = $this->postJson('/api/mobile/v2/auth/otp/request', [
        'phone' => '+237677223344', 'app_hash' => 'client-controlled', 'template_id' => (string) Str::uuid(),
    ])->assertOk();
    expect($response->json('data.expires_in_seconds'))->toBeBetween(297, 298);
    otpVerify($this, $response->json('data.challenge_id'))->assertOk();
});

it('reverses and reapplies only the OTP schema without changing parent data', function (): void {
    $parent = otpParent();
    $migration = require base_path('database/migrations/2026_10_23_000028_create_ec_mobile_otp_challenges.php');
    $migration->down();
    expect(ParentAccount::sole()->id)->toBe($parent->id);
    $migration->up();
    expect(DB::table('ec_mobile_otp_challenges')->count())->toBe(0);
    otpRequest($this);
    Http::assertSentCount(1);
});

it('serializes concurrent requests and redemption across separate workers using the shared database cache', function (string $mode): void {
    if ($mode === 'new parent') {
        otpStudent();
    } elseif ($mode !== 'new phone') {
        otpParent();
    }
    $id = $mode === 'request' ? null : otpRequest($this);
    $code = $id ? otpCode() : null;
    (require base_path('database/migrations/0001_01_01_000001_create_cache_table.php'))->up();
    File::ensureDirectoryExists(storage_path('framework/testing'));
    $path = storage_path('framework/testing/mobile-otp-race-'.Str::uuid().'.sqlite');
    DB::statement('VACUUM INTO ?', [$path]);
    $workers = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $worker = new Process([PHP_BINARY, base_path('tests/Support/mobile-otp-worker.php')], base_path(), [
                'APP_ENV' => 'testing',
            ], json_encode([
                'database' => $path, 'worker' => $index, 'mode' => $mode === 'request' ? 'request' : 'verify',
                'id' => $id, 'code' => $code, 'now' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR), 30);
            $worker->start();
            $workers[] = $worker;
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $statuses = array_column($results, 'status');
        sort($statuses);
        expect($statuses[0])->toBe(200, json_encode($results, JSON_THROW_ON_ERROR));
        expect($statuses[1])->toBeIn($mode === 'request' ? [429] : [422, 429], json_encode($results, JSON_THROW_ON_ERROR));
        $db = new PDO('sqlite:'.$path);
        expect((int) $db->query('SELECT COUNT(*) FROM ec_parent_accounts')->fetchColumn())->toBe(1);
        expect((int) $db->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn())->toBe($mode === 'request' ? 0 : 1);
        expect((int) $db->query('SELECT COUNT(*) FROM ec_mobile_otp_challenges')->fetchColumn())->toBe(1);
        expect(array_sum(array_column($results, 'sends')))->toBe($mode === 'request' ? 1 : 0);
        $db = null;
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        foreach (['', '.ready.0', '.ready.1', '-journal', '-wal', '-shm'] as $suffix) {
            if (is_file($path.$suffix)) {
                unlink($path.$suffix);
            }
        }
    }
})->with(['request', 'verify', 'new parent', 'new phone']);
