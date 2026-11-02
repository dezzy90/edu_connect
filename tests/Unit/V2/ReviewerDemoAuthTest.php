<?php

use App\Events\V2\MobileRealtimeEvent;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Auth\ReviewerDemoAccess;
use App\Services\Auth\ReviewerDemoProvisioner;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
    (require base_path('database/migrations/2026_10_23_000028_create_ec_mobile_otp_challenges.php'))->up();
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('e', 32)),
        'mobile_otp.enabled' => true, 'mobile_otp.cache_store' => 'array', 'mobile_otp.hmac_key' => str_repeat('h', 32),
        'services.scmc.enabled' => true, 'services.scmc.api_key' => 'offline-test-only',
        'services.scmc.otp_template_id' => 'b045f9d9-ba98-4ba1-9884-faab98aa169c', 'services.scmc.app_name' => 'EduConnect',
        'reviewer_demo.enabled' => false, 'educonnect.notifications.push_dispatch_mode' => 'disabled',
    ]);
    Cache::store('array')->flush();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.scmchost.com/*' => Http::response(['success' => true, 'data' => ['sent' => 1]])]);
    Event::fake([MobileRealtimeEvent::class]);
    Queue::fake();
    $this->freezeTime();
    $this->pin = '000007'; // Offline fixture only, never a deployed credential.
    $this->marker = (string) Str::uuid();
    $this->demo = app(ReviewerDemoProvisioner::class)->provision($this->marker, false);
    config([
        'reviewer_demo.enabled' => true, 'reviewer_demo.phone' => ReviewerDemoAccess::PHONE,
        'reviewer_demo.parent_id' => $this->demo['parent_id'], 'reviewer_demo.tenant_id' => $this->demo['tenant_id'],
        'reviewer_demo.marker' => $this->marker, 'reviewer_demo.pin_hash' => Hash::make($this->pin),
    ]);
});

function reviewerRequest($test, string $phone = ReviewerDemoAccess::PHONE): string
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->postJson('/api/mobile/v2/auth/otp/request', ['phone' => $phone])
        ->assertOk()->json('data.challenge_id');
}

function reviewerVerify($test, string $id, ?string $code = null)
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->postJson('/api/mobile/v2/auth/otp/verify', ['challenge_id' => $id, 'code' => $code ?? $test->pin]);
}

function reviewerBearer($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->withToken($token);
}

function reviewerRealStudent(): Student
{
    $tenant = Tenant::create(['name' => 'Normal fixture tenant', 'slug' => (string) Str::uuid()]);
    $school = School::create(['tenant_id' => $tenant->id, 'name' => 'Normal fixture school', 'slug' => (string) Str::uuid()]);

    return Student::create(['tenant_id' => $tenant->id, 'school_id' => $school->id,
        'student_number' => (string) Str::uuid(), 'first_name' => 'Normal', 'last_name' => 'Fixture', 'mobile_visible' => true]);
}

it('uses the unchanged challenge contract without SMS and permits PIN reuse only in a new challenge', function (): void {
    $id = reviewerRequest($this, '+1 (202) 555-0102');
    expect(Str::isUuid($id))->toBeTrue();
    $row = DB::table('ec_mobile_otp_challenges')->sole();
    expect($row->subject_type)->toBe(ReviewerDemoAccess::SUBJECT);
    expect($row->code_hash)->not->toBe($this->pin)->not->toBe(config('reviewer_demo.pin_hash'));
    expect($row->phone_ciphertext)->not->toBe(ReviewerDemoAccess::PHONE);
    $response = reviewerVerify($this, $id)->assertOk()->assertJsonPath('data.parent.id', $this->demo['parent_id']);
    expect(PersonalAccessToken::sole()->abilities)->toBe(['mobile:parent']);
    reviewerVerify($this, $id)->assertUnprocessable();
    $this->travel(61)->seconds();
    $second = reviewerRequest($this);
    expect($second)->not->toBe($id);
    reviewerVerify($this, $second)->assertOk();
    Http::assertNothingSent();
    reviewerBearer($this, $response->json('data.access_token'))->getJson('/api/admin/v2/dashboard')->assertForbidden();
});

it('preserves expiry attempt exhaustion cooldown and superseding', function (string $case): void {
    $id = reviewerRequest($this);
    if ($case === 'expiry') {
        $this->travel(301)->seconds();
    } elseif ($case === 'attempts') {
        for ($i = 0; $i < 5; $i++) {
            reviewerVerify($this, $id, '999999')->assertUnprocessable();
        }
        expect(DB::table('ec_mobile_otp_challenges')->value('status'))->toBe('exhausted');
    } else {
        $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => ReviewerDemoAccess::PHONE])->assertStatus(429);
        $this->travel(61)->seconds();
        reviewerRequest($this);
    }
    reviewerVerify($this, $id)->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
    Http::assertNothingSent();
})->with(['expiry', 'attempts', 'superseded']);

it('fails closed at request and redemption when identity or configuration changes', function (string $change): void {
    $id = reviewerRequest($this);
    match ($change) {
        'disabled' => config(['reviewer_demo.enabled' => false]),
        'wrong parent' => config(['reviewer_demo.parent_id' => 99999]),
        'wrong phone' => config(['reviewer_demo.phone' => '+237677223344']),
        'wrong tenant' => config(['reviewer_demo.tenant_id' => 99999]),
        'wrong marker' => config(['reviewer_demo.marker' => (string) Str::uuid()]),
        'plaintext' => config(['reviewer_demo.pin_hash' => $this->pin]),
        'inactive' => DB::table('ec_parent_accounts')->where('id', $this->demo['parent_id'])->update(['status' => 'inactive']),
        'deleted' => DB::table('ec_parent_accounts')->where('id', $this->demo['parent_id'])->update(['deleted_at' => now()]),
        'parent marker' => DB::table('ec_parent_accounts')->where('id', $this->demo['parent_id'])->update(['settings' => '{}']),
        'tenant marker' => DB::table('ec_tenants')->where('id', $this->demo['tenant_id'])->update(['settings' => '{}']),
        'membership' => DB::table('ec_parent_student_links')->where('parent_account_id', $this->demo['parent_id'])->update(['status' => 'revoked']),
        'student source' => DB::table('ec_students')->where('tenant_id', $this->demo['tenant_id'])->update(['source_system' => 'edu_admin']),
        'school source' => DB::table('ec_schools')->where('tenant_id', $this->demo['tenant_id'])->update(['source_system' => 'edu_admin']),
        'phone moved' => DB::table('ec_parent_accounts')->where('id', $this->demo['parent_id'])->update(['phone' => '+237677223344']),
        'bound elsewhere' => DB::table('ec_parent_student_links')->where('tenant_id', $this->demo['tenant_id'])
            ->update(['parent_account_id' => ParentAccount::create(['phone' => '+237677223344', 'first_name' => 'Other', 'last_name' => 'Fixture'])->id]),
        'duplicate' => ParentAccount::create(['phone' => '0012025550102', 'first_name' => 'Duplicate', 'last_name' => 'Fixture']),
    };
    reviewerVerify($this, $id)->assertUnprocessable();
    $this->travel(61)->seconds();
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => ReviewerDemoAccess::PHONE])->assertStatus(503);
    expect(PersonalAccessToken::count())->toBe(0);
    Http::assertNothingSent();
})->with(['disabled', 'wrong parent', 'wrong phone', 'wrong tenant', 'wrong marker', 'plaintext', 'inactive', 'deleted', 'parent marker', 'tenant marker', 'membership', 'student source', 'school source', 'phone moved', 'bound elsewhere', 'duplicate']);

it('invalidates old challenges when the hash rotates without sharing their proof', function (): void {
    $id = reviewerRequest($this);
    config(['reviewer_demo.pin_hash' => Hash::make($this->pin)]);
    reviewerVerify($this, $id)->assertUnprocessable();
    $this->travel(61)->seconds();
    reviewerVerify($this, reviewerRequest($this))->assertOk();
});

it('keeps normal SMS signup and account login unchanged with reviewer access on or off', function (bool $enabled, bool $existing): void {
    config(['reviewer_demo.enabled' => $enabled]);
    $phone = '+237677223344';
    if ($existing) {
        ParentAccount::create(['phone' => $phone, 'first_name' => 'Normal', 'last_name' => 'Fixture']);
    }
    $id = reviewerRequest($this, $phone);
    Http::assertSentCount(1);
    $sms = Http::recorded()->last()[0]['variables']['CODE'];
    // In the rare event a random OTP equals the test PIN, choose another wrong value.
    reviewerVerify($this, $id, $sms === $this->pin ? '999999' : $this->pin)->assertUnprocessable();
    reviewerVerify($this, $id, $sms)->assertOk()->assertJsonPath('data.parent.phone', $phone);
    expect(ParentStudentLink::where('parent_account_id', '!=', $this->demo['parent_id'])->count())->toBe(0);
})->with([true, false])->with([true, false]);

it('does not turn a real configured account into a static PIN account or send it a static code', function (): void {
    $parent = ParentAccount::create(['phone' => '+237677223344', 'first_name' => 'Normal', 'last_name' => 'Fixture']);
    config(['reviewer_demo.phone' => $parent->phone, 'reviewer_demo.parent_id' => $parent->id]);
    $this->postJson('/api/mobile/v2/auth/otp/request', ['phone' => $parent->phone])->assertStatus(503);
    Http::assertNothingSent();
    expect(PersonalAccessToken::count())->toBe(0);
});

it('keeps child profiles and ordinary synthetic messaging usable without outbound integration', function (): void {
    $token = reviewerVerify($this, reviewerRequest($this))->assertOk()->json('data.access_token');
    $children = reviewerBearer($this, $token)->getJson('/api/mobile/v2/children')->assertOk()->json('data');
    expect($children)->toHaveCount(3);
    foreach ($children as $child) {
        reviewerBearer($this, $token)->getJson('/api/mobile/v2/children/'.$child['student']['id'].'/profile')
            ->assertOk()->assertJsonPath('data.profile.fees.balance', 20000);
    }
    reviewerBearer($this, $token)->getJson('/api/mobile/v2/conversations')->assertOk();
    $threadId = DB::table('ec_conversation_threads')->where('type', 'direct')->value('id');
    reviewerBearer($this, $token)->postJson('/api/mobile/v2/conversations/'.$threadId.'/messages', ['body' => 'Synthetic parent message'])
        ->assertCreated();
    reviewerBearer($this, $token)->getJson('/api/mobile/v2/conversations/'.$threadId)->assertOk();
    Http::assertNothingSent();
    Queue::assertNotPushed(\App\Jobs\V2\Integration\PushParentConversationMessageJob::class);
});

it('blocks real child linking and cross-tenant messaging at the common boundaries', function (): void {
    $student = reviewerRealStudent();
    $link = ParentStudentLink::create(['tenant_id' => $student->tenant_id, 'school_id' => $student->school_id,
        'student_id' => $student->id, 'parent_phone' => '+237677223344', 'status' => 'verified', 'linking_code' => 'REALFIXTURE']);
    $token = reviewerVerify($this, reviewerRequest($this))->assertOk()->json('data.access_token');
    reviewerBearer($this, $token)->postJson('/api/mobile/v2/children/link', ['linking_code' => 'REALFIXTURE'])->assertForbidden();
    expect($link->refresh()->parent_account_id)->toBeNull();
    reviewerBearer($this, $token)->getJson('/api/mobile/v2/children/'.$student->id.'/profile')->assertNotFound();
    reviewerBearer($this, $token)->postJson('/api/mobile/v2/conversations/direct', ['student_id' => $student->id, 'body' => 'Denied'])->assertForbidden();
    expect(fn () => $link->forceFill(['parent_account_id' => $this->demo['parent_id']])->save())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('revokes effective access for an existing session when links or memberships are tampered', function (string $change): void {
    $token = reviewerVerify($this, reviewerRequest($this))->assertOk()->json('data.access_token');
    $student = reviewerRealStudent();
    if ($change === 'real child') {
        DB::table('ec_parent_student_links')->insert(['tenant_id' => $student->tenant_id, 'school_id' => $student->school_id,
            'student_id' => $student->id, 'parent_account_id' => $this->demo['parent_id'], 'parent_phone' => ReviewerDemoAccess::PHONE,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    } elseif ($change === 'tenant marker') {
        DB::table('ec_tenants')->where('id', $this->demo['tenant_id'])->update(['settings' => '{}']);
    } elseif ($change === 'disabled') {
        config(['reviewer_demo.enabled' => false]);
    } else {
        $thread = DB::table('ec_conversation_threads')->where('tenant_id', $this->demo['tenant_id'])->value('id');
        DB::table('ec_conversation_threads')->where('id', $thread)->update(['tenant_id' => $student->tenant_id, 'school_id' => $student->school_id]);
    }
    foreach (['/me', '/children', '/children/'.$student->id.'/profile', '/messages', '/conversations', '/notifications', '/realtime/config'] as $path) {
        reviewerBearer($this, $token)->getJson('/api/mobile/v2'.$path)->assertForbidden();
    }
    $this->travel(61)->seconds();
    $this->flushHeaders()->postJson('/api/mobile/v2/auth/otp/request', ['phone' => ReviewerDemoAccess::PHONE])->assertStatus(503);
    Http::assertNothingSent();
})->with(['real child', 'tenant marker', 'disabled', 'cross tenant thread']);

it('prevents profile edits from removing server-owned identity while allowing ordinary profile updates', function (): void {
    $token = reviewerVerify($this, reviewerRequest($this))->assertOk()->json('data.access_token');
    reviewerBearer($this, $token)->patchJson('/api/mobile/v2/me', ['first_name' => 'Synthetic'])->assertOk();
    reviewerBearer($this, $token)->patchJson('/api/mobile/v2/me', ['settings' => []])->assertForbidden();
    reviewerBearer($this, $token)->patchJson('/api/mobile/v2/me', ['email' => 'real@example.test'])->assertForbidden();
});

it('allows the normal linking workflow only inside the synthetic tenant and prevents reverse linking', function (): void {
    $link = ParentStudentLink::where('tenant_id', $this->demo['tenant_id'])->firstOrFail();
    DB::table('ec_parent_student_links')->where('id', $link->id)->update(['linking_code' => 'SYNTHETICONLY']);
    $token = reviewerVerify($this, reviewerRequest($this))->assertOk()->json('data.access_token');
    reviewerBearer($this, $token)->postJson('/api/mobile/v2/children/link', ['linking_code' => 'SYNTHETICONLY'])->assertCreated();
    $parent = ParentAccount::create(['phone' => '+237677223344', 'first_name' => 'Normal', 'last_name' => 'Fixture']);
    $normalToken = $parent->createToken('normal', ['mobile:parent'])->plainTextToken;
    reviewerBearer($this, $normalToken)->postJson('/api/mobile/v2/children/link', ['linking_code' => 'SYNTHETICONLY'])->assertForbidden();
    expect($link->refresh()->parent_account_id)->toBe($this->demo['parent_id']);
});

it('rejects challenge subject substitution without issuing a token', function (): void {
    $id = reviewerRequest($this);
    DB::table('ec_mobile_otp_challenges')->where('id', $id)->update(['subject_id' => 99999]);
    reviewerVerify($this, $id)->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
});

it('blocks post-login access when an unrelated parent is inserted into a synthetic conversation', function (): void {
    $token = reviewerVerify($this, reviewerRequest($this))->assertOk()->json('data.access_token');
    $parent = ParentAccount::create(['phone' => '+237677223344', 'first_name' => 'Normal', 'last_name' => 'Fixture']);
    DB::table('ec_conversation_participants')->insert(['thread_id' => DB::table('ec_conversation_threads')->value('id'),
        'participant_type' => 'parent_account', 'participant_id' => $parent->id, 'display_name' => 'Normal Fixture']);
    reviewerBearer($this, $token)->getJson('/api/mobile/v2/conversations')->assertForbidden();
});

it('checks notification and message recipients using the real migrated parent column', function (string $kind): void {
    $token = reviewerVerify($this, reviewerRequest($this))->assertOk()->json('data.access_token');
    $student = reviewerRealStudent();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('ec_mobile_notifications', 'parent_account_id'))->toBeTrue();
    if ($kind === 'notification') {
        DB::table('ec_mobile_notifications')->insert(['parent_account_id' => $this->demo['parent_id'],
            'tenant_id' => $student->tenant_id, 'school_id' => $student->school_id,
            'type' => 'general', 'title' => 'Private fixture notice', 'body' => 'Must not be returned']);
    } else {
        $messageId = DB::table('ec_mobile_messages')->insertGetId(['tenant_id' => $student->tenant_id,
            'school_id' => $student->school_id, 'title' => 'Private fixture message', 'body' => 'Must not be returned',
            'status' => 'published', 'published_at' => now()]);
        DB::table('ec_mobile_message_recipients')->insert(['parent_account_id' => $this->demo['parent_id'], 'message_id' => $messageId]);
    }
    reviewerBearer($this, $token)->getJson('/api/mobile/v2/notifications')->assertForbidden();
    reviewerBearer($this, $token)->getJson('/api/mobile/v2/messages')->assertForbidden();
    $this->travel(61)->seconds();
    $this->flushHeaders()->postJson('/api/mobile/v2/auth/otp/request', ['phone' => ReviewerDemoAccess::PHONE])->assertStatus(503);
    Http::assertNothingSent();
})->with(['notification', 'message']);

it('does not preserve a consumed challenge when token issuance fails', function (): void {
    $id = reviewerRequest($this);
    expect(fn () => app(\App\Services\Auth\MobileOtpAuth::class)->verify($id, $this->pin, '192.0.2.1',
        fn () => throw new RuntimeException('Offline token failure')))->toThrow(RuntimeException::class);
    expect(DB::table('ec_mobile_otp_challenges')->value('status'))->toBe('sent');
    expect(PersonalAccessToken::count())->toBe(0);
    reviewerVerify($this, $id)->assertOk();
});

it('never provisions via public registration', function (): void {
    $this->postJson('/api/mobile/v2/auth/register', ['phone' => ReviewerDemoAccess::PHONE,
        'first_name' => 'New', 'last_name' => 'Account', 'password' => 'offline-test-password'])->assertForbidden();
});

it('is idempotent and refuses to repair drift during provisioning', function (): void {
    $provisioner = app(ReviewerDemoProvisioner::class);
    expect($provisioner->provision($this->marker, false)['action'])->toBe('unchanged');
    expect(Tenant::count())->toBe(1);
    expect(Student::count())->toBe(3);
    DB::table('ec_tenants')->where('id', $this->demo['tenant_id'])->update(['settings' => '{}']);
    expect(fn () => $provisioner->provision($this->marker, false))->toThrow(RuntimeException::class);
    expect(Tenant::count())->toBe(1);
});

it('requires explicit database confirmation and keeps the default command read only', function (): void {
    config(['reviewer_demo.enabled' => false]);
    $this->artisan('reviewer-demo:provision', ['--marker' => $this->marker])->assertSuccessful();
    $this->artisan('reviewer-demo:provision', ['--marker' => $this->marker, '--apply' => true])->assertFailed();
    $this->artisan('reviewer-demo:provision', ['--marker' => $this->marker, '--preview' => true,
        '--expect-database' => 'wrong', '--confirm' => 'synthetic-only'])->assertFailed();
});

it('previews with rollback then creates synthetic data with no events and no credentials', function (): void {
    V2Schema::migrate();
    config(['reviewer_demo.enabled' => false]);
    Event::fake();
    $options = ['--marker' => $this->marker, '--expect-database' => ':memory:', '--confirm' => 'synthetic-only'];
    $this->artisan('reviewer-demo:provision', $options)->assertSuccessful();
    expect(DB::table('ec_tenants')->count())->toBe(0);
    $this->artisan('reviewer-demo:provision', $options + ['--preview' => true])->assertSuccessful();
    expect(DB::table('ec_tenants')->count())->toBe(0);
    expect(DB::table('ec_parent_accounts')->count())->toBe(0);
    $this->artisan('reviewer-demo:provision', $options + ['--apply' => true])->assertSuccessful();
    expect(DB::table('ec_students')->count())->toBe(3);
    expect(DB::table('ec_parent_accounts')->value('password_hash'))->toBeNull();
    expect(DB::table('admin_users')->count())->toBe(0);
    expect(DB::table('ec_integration_connections')->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Event::assertNotDispatched(MobileRealtimeEvent::class);
});

it('rolls back if any fixture insert fails', function (): void {
    V2Schema::migrate();
    DB::statement("CREATE TRIGGER fail_demo_profile BEFORE INSERT ON ec_student_mobile_profiles BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    expect(fn () => app(ReviewerDemoProvisioner::class)->provision($this->marker, false))->toThrow(\Illuminate\Database\QueryException::class);
    expect(DB::table('ec_tenants')->count())->toBe(0);
    expect(DB::table('ec_parent_accounts')->count())->toBe(0);
});
