<?php

use App\Models\AdminUser;
use App\Models\V2\BiometricDevice;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Biometrics\Hikvision\EnrollmentPhoto;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use App\Services\Biometrics\Hikvision\PushProvisioner;
use App\Services\Integration\MappingService;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\Hikvision\PushTerminalSimulator;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
    (require base_path('database/migrations/2026_10_10_000020_create_hikvision_push_inbox.php'))->up();
    (require base_path('database/migrations/2026_10_19_000021_create_hikvision_enrollments.php'))->up();
    (require base_path('database/migrations/2026_10_21_000026_create_hikvision_enrollment_work.php'))->up();
    Carbon::setTestNow('2026-10-19T12:00:00Z');
    config(['hikvision_push.enabled' => true, 'hikvision_push.enrollment_enabled' => true,
        'hikvision_push.enrollment_device_writes_enabled' => true, 'hikvision_push.enrollment_connection_ids' => [], 'hikvision_push.allow_loopback_http' => true]);
    Http::preventStrayRequests();
    $tenant = Tenant::create(['name' => 'Synthetic tenant', 'slug' => 'enrollment-test', 'status' => 'active']);
    $school = School::create(['tenant_id' => $tenant->id, 'name' => 'Synthetic school', 'slug' => 'school', 'type' => 'secondary', 'status' => 'active']);
    $this->student = Student::create(['tenant_id' => $tenant->id, 'school_id' => $school->id, 'student_number' => 'TEST-1',
        'first_name' => 'Synthetic', 'last_name' => 'Portrait', 'status' => 'enrolled', 'device_sync_enabled' => true,
        'source_system' => 'edu_admin', 'source_id' => '42']);
    $this->device = BiometricDevice::create(['tenant_id' => $tenant->id, 'school_id' => $school->id, 'name' => 'Synthetic device',
        'device_uid' => 'ENROLLMENT-DEVICE', 'serial_number' => 'ENROLLMENT-DEVICE', 'device_type' => 'hikvision', 'status' => 'active',
        'settings' => ['hikvision_enrollment' => ['enabled' => true, 'verified' => true, 'capacity' => 20, 'fdid' => '1',
            'model' => 'DS-K1T342MFWX-E1', 'firmware' => 'SYNTHETIC']]]);
    $this->connection = IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'edu_admin', 'mode' => 'connected',
        'status' => 'active', 'base_url' => 'https://eduadmin.test', 'encrypted_access_token' => Crypt::encryptString('SYNTHETIC-TOKEN')]);
    app(MappingService::class)->upsert($this->connection, 'student', $this->student->id, 'student', '42');
    $this->pushId = app(PushProvisioner::class)->register($this->device->id, 'ENROLLMENT-DEVICE', 'SYNTHETIC', 'sha256', 'synthetic-user', 'SYNTHETIC-password-for-enrollment');
    $this->terminal = new PushTerminalSimulator(fn ($op, $body, $headers) => $this->postJson(
        '/iot/ENROLLMENT-DEVICE/global/0-global/model/service/operate/PUSH/'.$op, $body, $headers),
        'synthetic-user', 'SYNTHETIC-password-for-enrollment');
    $this->terminal->connect()->assertOk();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('downloads an authenticated application portrait provisions it and confirms a separately mapped live scan', function (): void {
    enrollStagePhoto($this);
    $photo = DB::table('ec_hikvision_enrollment_photos')->sole();
    expect($photo->state)->toBe('photo_ready');
    expect($photo->photo_encrypted)->not->toContain(base64_encode(enrollSyntheticJpeg()));
    Http::assertSent(fn ($r) => $r->url() === 'https://eduadmin.test/api/v1/integrations/edu-connect/students/42/enrollment-photo?revision='.str_repeat('a', 64)
        && $r->hasHeader('Authorization', 'Bearer SYNTHETIC-TOKEN'));
    enrollComplete($this);
    $target = DB::table('ec_hikvision_enrollments')->sole();
    expect($target->state)->toBe('device_ready');
    expect($target->confirmed_revision)->toBe($target->desired_revision);
    $scan = PushTerminalSimulator::scan(person: $target->person_identifier);
    $event = json_decode(base64_decode($scan['eventList'][0]['data']), true);
    $event['dateTime'] = now()->toIso8601String();
    $scan['eventList'][0]['data'] = base64_encode(json_encode($event));
    $this->terminal->send('Event', $scan)->assertOk();
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('recognition_confirmed');
    expect(DB::table('ec_hikvision_observations')->value('delivery_state'))->toBe('held_hardware_acceptance');
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
});

it('keeps missing portraits pending without silently enrolling people without faces', function (): void {
    app(EnrollmentPhoto::class)->stage($this->student, $this->connection, null);
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('missing_photo');
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
});

it('limits a controlled terminal profile to explicitly selected students', function (): void {
    $settings = $this->device->settings;
    $settings['hikvision_enrollment']['student_ids'] = [$this->student->id + 1];
    $this->device->update(['settings' => $settings]);
    enrollStagePhoto($this);
    expect(DB::table('ec_hikvision_enrollments')->count())->toBe(0);
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
});

it('rechecks the selected student scope at poll time before any person or face write', function (): void {
    enrollStagePhoto($this);
    $settings = $this->device->settings;
    $settings['hikvision_enrollment']['student_ids'] = [];
    $this->device->update(['settings' => $settings]);
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_commands')->sole()->state)->toBe('superseded');
});

it('cleans up only its owned identity when a pilot student is removed from the selected roster', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    $settings = $this->device->settings;
    $settings['hikvision_enrollment']['student_ids'] = [];
    $this->device->update(['settings' => $settings]);
    app(EnrollmentRoster::class)->reconcile($this->student->id);
    $command = enrollPoll($this);
    expect($command['URL'])->toBe('PUT /ISAPI/AccessControl/UserInfoDetail/Delete?format=json');
    $body = json_decode(base64_decode($command['data']), true);
    expect($body['UserInfoDetail'])->toBe(['mode' => 'byEmployeeNo', 'EmployeeNoList' => [['employeeNo' => 'EC'.$this->student->id]]]);
});

it('fails closed for malformed explicit per-terminal student selections', function (mixed $selection): void {
    expect(EnrollmentRoster::profileIncludes(1, ['student_ids' => $selection]))->toBeFalse();
})->with([null, '1', [['1']], [[0]], [[-1]], [[1, 1]], [['key' => 1]]]);

it('keeps ordinary school rosters unchanged when no pilot selection is supplied', function (): void {
    expect(EnrollmentRoster::profileIncludes(1, []))->toBeTrue();
    expect(EnrollmentRoster::profileIncludes(1, ['student_ids' => [1]]))->toBeTrue();
});

it('holds photo rejections and never declares a face ready from a person acknowledgement', function (): void {
    enrollStagePhoto($this);
    enrollReply($this, enrollPoll($this), enrollSearch());
    enrollReply($this, enrollPoll($this), ['statusCode' => 1]);
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->not->toBe('device_ready');
    $face = enrollPoll($this);
    expect($face['dataFormat'])->toBe('boundaryData');
    expect(base64_decode($face['data']))->toContain('name="faceURL"', 'name="img"', 'Content-Type: image/jpeg');
    enrollReply($this, $face, ['statusCode' => 4, 'errorMsg' => 'DO-NOT-STORE-SENSITIVE-DEVICE-ERROR']);
    expect(DB::table('ec_hikvision_enrollments')->value('error_code'))->toBe('photo_rejected_by_device');
    expect(json_encode(DB::table('ec_hikvision_enrollment_commands')->get()))->not->toContain('DO-NOT-STORE');
    expect(DB::table('ec_device_personnel')->count())->toBe(0);
});

it('includes explicit validity fields without granting local administration or door permissions', function (): void {
    enrollStagePhoto($this);
    enrollReply($this, enrollPoll($this), enrollSearch());
    $command = enrollPoll($this);
    expect($command['URL'])->toBe('POST /ISAPI/AccessControl/UserInfo/Record?format=json');
    $person = json_decode(base64_decode($command['data']), true)['UserInfo'];
    expect($person['Valid'])->toBe(['enable' => false, 'beginTime' => '2000-01-01T00:00:00',
        'endTime' => '2037-12-31T23:59:59', 'timeType' => 'local']);
    expect($person['localUIRight'])->toBeFalse();
    expect($person['onlyVerify'])->toBeTrue();
    expect($person['userType'])->toBe('normal');
    expect($person)->not->toHaveKey('doorRight')->not->toHaveKey('RightPlan')->not->toHaveKey('password');
});

it('holds an older policy revision until reconciliation queues attendance-only provisioning', function (): void {
    enrollStagePhoto($this);
    $target = DB::table('ec_hikvision_enrollments')->sole();
    $old = str_repeat('c', 64);
    DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update(['desired_revision' => $old]);
    DB::table('ec_hikvision_enrollment_commands')->where('enrollment_id', $target->id)->update(['revision' => $old]);
    expect($this->terminal->send('CommandRequest')->assertOk()->json('commandNum'))->toBe(0);
    app(EnrollmentRoster::class)->reconcile($this->student->id);
    enrollReply($this, enrollPoll($this), enrollSearch());
    $person = enrollPoll($this);
    expect(json_decode(base64_decode($person['data']), true)['UserInfo']['onlyVerify'])->toBeTrue();
    expect(DB::table('ec_hikvision_enrollment_commands')->where('revision', $old)->value('state'))->toBe('superseded');
});

it('includes attendance-only mode on updates and requires its readback before readiness', function (mixed $readback): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    enrollStagePhoto($this, 'b', 25);
    $person = enrollPoll($this);
    expect($person['URL'])->toBe('PUT /ISAPI/AccessControl/UserInfo/SetUp?format=json');
    expect(json_decode(base64_decode($person['data']), true)['UserInfo']['onlyVerify'])->toBeTrue();
    enrollReply($this, $person, ['statusCode' => 1]);
    enrollReply($this, enrollPoll($this), ['statusCode' => 1]);
    $response = enrollSearch(DB::table('ec_hikvision_enrollments')->value('person_identifier'));
    if ($readback === null) {
        unset($response['UserInfoSearch']['UserInfo'][0]['onlyVerify']);
    } else {
        $response['UserInfoSearch']['UserInfo'][0]['onlyVerify'] = $readback;
    }
    enrollReply($this, enrollPoll($this), $response);
    $target = DB::table('ec_hikvision_enrollments')->sole();
    expect($target->state)->toBe('needs_review');
    expect($target->error_code)->toBe('attendance_only_not_confirmed');
    expect($target->confirmed_revision)->not->toBe($target->desired_revision);
})->with([false, null, 'true', 1]);

it('does not confirm enrollment or publish attendance from a no-permission scan', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    $target = DB::table('ec_hikvision_enrollments')->sole();
    $scan = PushTerminalSimulator::scan(person: $target->person_identifier, overrides: ['subEventType' => 6]);
    $this->terminal->send('Event', $scan)->assertOk();
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('device_ready');
    expect(DB::table('ec_hikvision_enrollments')->value('recognition_confirmed_at'))->toBeNull();
    expect(DB::table('ec_hikvision_observations')->value('status'))->toBe('held_unverified_code');
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
});

it('does not overwrite an existing unowned device person', function (): void {
    enrollStagePhoto($this);
    $target = DB::table('ec_hikvision_enrollments')->sole();
    enrollReply($this, enrollPoll($this), enrollSearch($target->person_identifier));
    expect(DB::table('ec_hikvision_enrollments')->value('error_code'))->toBe('unowned_device_person_conflict');
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
});

it('retries the identical encrypted command snapshot without duplicating the queue record', function (): void {
    enrollStagePhoto($this);
    $first = enrollPoll($this);
    Carbon::setTestNow(now()->addSeconds(31));
    $this->terminal->connect()->assertOk();
    expect(enrollPoll($this))->toBe($first);
    expect(DB::table('ec_hikvision_enrollment_commands')->count())->toBe(1);
    expect(DB::table('ec_hikvision_enrollment_commands')->value('attempts'))->toBe(2);
});

it('waits for obsolete in-flight photos and applies the newer version without replaying the old one', function (): void {
    enrollStagePhoto($this);
    enrollReply($this, enrollPoll($this), enrollSearch());
    enrollReply($this, enrollPoll($this), ['statusCode' => 1]);
    $oldFace = enrollPoll($this);
    enrollStagePhoto($this, 'b', 80);
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_commands')->where('command_uuid', $oldFace['UUID'])->value('state'))->toBe('obsolete_sent');
    enrollReply($this, $oldFace, ['statusCode' => 1]);
    $person = enrollPoll($this);
    expect($person['URL'])->toBe('PUT /ISAPI/AccessControl/UserInfo/SetUp?format=json');
    enrollReply($this, $person, ['statusCode' => 1]);
    $newFace = enrollPoll($this);
    expect($newFace['UUID'])->not->toBe($oldFace['UUID']);
    expect($newFace['URL'])->toBe('PUT /ISAPI/Intelligent/FDLib/FDSetUp?format=json');
    expect($newFace['data'])->not->toBe($oldFace['data']);
    expect(DB::table('ec_hikvision_enrollments')->value('confirmed_revision'))->toBeNull();
});

it('removes only the managed student and waits for asynchronous deletion plus absence confirmation', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    $target = DB::table('ec_hikvision_enrollments')->sole();
    $this->student->update(['status' => 'withdrawn']);
    app(EnrollmentRoster::class)->reconcile($this->student->id);
    $remove = enrollPoll($this);
    expect(json_decode(base64_decode($remove['data']), true))->toBe(['UserInfoDetail' => ['mode' => 'byEmployeeNo',
        'EmployeeNoList' => [['employeeNo' => $target->person_identifier]]]]);
    enrollReply($this, $remove, ['statusCode' => 1]);
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('removal_pending');
    enrollReply($this, enrollPoll($this), ['UserInfoDetailDeleteProcess' => ['status' => 'processing']]);
    enrollReply($this, enrollPoll($this), ['UserInfoDetailDeleteProcess' => ['status' => 'success']]);
    enrollReply($this, enrollPoll($this), enrollSearch());
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('removed');
    expect(DB::table('ec_device_personnel')->count())->toBe(0);
    expect(DB::table('ec_students')->count())->toBe(1);
});

it('blocks redirects corrupt portraits and untrusted download paths without exposing secrets', function (string $variant): void {
    $revision = str_repeat('a', 64);
    app(EnrollmentPhoto::class)->stage($this->student, $this->connection, ['revision' => $revision, 'download_path' => 'http://127.0.0.1/SECRET']);
    Http::fake(['https://eduadmin.test/*' => Http::response($variant === 'corrupt' ? 'NOT-AN-IMAGE' : enrollSyntheticJpeg(),
        $variant === 'redirect' ? 302 : 200, ['Location' => 'http://127.0.0.1/SECRET', 'X-Enrollment-Photo-Revision' => $revision,
            'X-Content-SHA256' => $variant === 'hash' ? str_repeat('0', 64) : hash('sha256', $variant === 'corrupt' ? 'NOT-AN-IMAGE' : enrollSyntheticJpeg())])]);
    app(EnrollmentPhoto::class)->prepare($this->student->id);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('state'))->not->toBe('photo_ready');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
})->with(['redirect', 'corrupt', 'hash']);

it('does not send enrollment commands when globally disabled or per-device capability approval is missing', function (): void {
    enrollStagePhoto($this);
    config(['hikvision_push.enrollment_enabled' => false]);
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
    config(['hikvision_push.enrollment_enabled' => true]);
    $settings = $this->device->settings;
    $settings['hikvision_enrollment']['verified'] = false;
    $this->device->update(['settings' => $settings]);
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
});

it('revokes a managed identity after its authoritative photo is explicitly removed', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    app(EnrollmentPhoto::class)->stage($this->student->refresh(), $this->connection, null);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('photo_encrypted'))->toBeNull();
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('removal_pending');
    expect(enrollPoll($this)['URL'])->toContain('UserInfoDetail/Delete?');
});

it('reconciles source deletion tombstones without deleting attendance history', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    $stats = app(SyncCoordinator::class)->applyResourceDeletion($this->connection, 'students', ['42']);
    expect($stats['deleted'])->toBe(1);
    expect($this->student->fresh()->trashed())->toBeTrue();
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('removal_pending');
    expect(enrollPoll($this)['URL'])->toContain('UserInfoDetail/Delete?');
});

it('finishes deletion before restarting a reactivated identity from preflight', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    $this->student->update(['status' => 'withdrawn']);
    app(EnrollmentRoster::class)->reconcile($this->student->id);
    $remove = enrollPoll($this);
    $this->student->update(['status' => 'enrolled']);
    app(EnrollmentRoster::class)->reconcile($this->student->id);
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
    enrollReply($this, $remove, ['statusCode' => 1]);
    enrollReply($this, enrollPoll($this), ['UserInfoDetailDeleteProcess' => ['status' => 'success']]);
    enrollReply($this, enrollPoll($this), enrollSearch());
    $preflight = enrollPoll($this);
    expect($preflight['URL'])->toContain('UserInfo/Search');
    enrollReply($this, $preflight, enrollSearch());
    expect(enrollPoll($this)['URL'])->toBe('POST /ISAPI/AccessControl/UserInfo/Record?format=json');
});

it('queues old-school removal after transfer and never sends new-school face data to the old device', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    $school = School::create(['tenant_id' => $this->student->tenant_id, 'name' => 'Other school', 'slug' => 'other', 'type' => 'secondary', 'status' => 'active']);
    $this->student->update(['school_id' => $school->id]);
    app(EnrollmentRoster::class)->reconcile($this->student->id);
    expect(enrollPoll($this)['URL'])->toContain('UserInfoDetail/Delete?');
    expect(DB::table('ec_hikvision_enrollments')->value('desired_present'))->toBe(0);
});

it('does not send photos after integration authorization or identity mapping is revoked', function (string $change): void {
    enrollStagePhoto($this);
    if ($change === 'connection') {
        $this->connection->update(['status' => 'inactive']);
    } else {
        DB::table('ec_integration_mappings')->where('local_type', 'student')->delete();
    }
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
})->with(['connection', 'mapping']);

it('holds over-capacity assignments and never leaks photo command payloads in status APIs', function (): void {
    enrollStagePhoto($this);
    $settings = $this->device->settings;
    $settings['hikvision_enrollment']['unmanaged_count'] = 20;
    $settings['PRIVATE-SECRET'] = 'DO-NOT-RETURN';
    $this->device->update(['settings' => $settings]);
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
    expect(DB::table('ec_hikvision_enrollments')->value('error_code'))->toBe('device_capacity_exceeded');
    $admin = AdminUser::create(['name' => 'Synthetic admin', 'email' => 'enrollment@example.test', 'password' => 'test-only', 'role' => 'super_admin', 'is_active' => true]);
    Sanctum::actingAs($admin);
    $response = $this->getJson('/api/admin/v2/hikvision-enrollments')->assertOk();
    expect($response->json('data.enrollments.data.0.error_code'))->toBe('device_capacity_exceeded');
    expect($response->getContent())->not->toContain('photo_encrypted', 'payload_encrypted', 'SYNTHETIC-TOKEN', 'DO-NOT-RETURN');
    $this->postJson('/api/admin/v2/hikvision-enrollments/'.DB::table('ec_hikvision_enrollments')->value('id').'/retry')->assertOk();
    expect(DB::table('ec_hikvision_enrollments')->value('state'))->toBe('queued');
});

it('scopes operators to their explicit school bridge rather than the whole tenant', function (): void {
    enrollStagePhoto($this);
    $school = School::findOrFail($this->student->school_id);
    $school->update(['source_system' => 'legacy', 'source_id' => '765']);
    $admin = AdminUser::create(['name' => 'Synthetic school admin', 'email' => 'scope@example.test', 'password' => 'test-only',
        'role' => 'school_admin', 'school_id' => 765, 'is_active' => true]);
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/v2/hikvision-enrollments')->assertOk()->assertJsonPath('data.profiles.total', 1);
    $school->update(['source_id' => '766']);
    $this->getJson('/api/admin/v2/hikvision-enrollments')->assertOk()->assertJsonPath('data.profiles.total', 0)->assertJsonPath('data.enrollments.total', 0);
    $this->postJson('/api/admin/v2/hikvision-enrollment-photos/'.DB::table('ec_hikvision_enrollment_photos')->value('id').'/retry')->assertNotFound();
    $this->postJson('/api/admin/v2/hikvision-enrollments/'.DB::table('ec_hikvision_enrollments')->value('id').'/retry')->assertNotFound();
});

it('does not release an unconfirmed device barrier through an operator retry', function (): void {
    enrollStagePhoto($this);
    $command = enrollPoll($this);
    DB::table('ec_hikvision_enrollment_commands')->where('command_uuid', $command['UUID'])->update(['attempts' => 5]);
    Carbon::setTestNow(now()->addSeconds(31));
    $this->terminal->connect()->assertOk();
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_commands')->value('state'))->toBe('unconfirmed');
    Sanctum::actingAs(AdminUser::create(['name' => 'Synthetic admin', 'email' => 'barrier@example.test', 'password' => 'test-only', 'role' => 'super_admin', 'is_active' => true]));
    $this->postJson('/api/admin/v2/hikvision-enrollments/'.DB::table('ec_hikvision_enrollments')->value('id').'/retry')->assertStatus(409);
    expect(DB::table('ec_hikvision_enrollment_commands')->value('state'))->toBe('unconfirmed');
});

it('leases photo work bounds download retries and supports a scoped explicit retry', function (): void {
    app(EnrollmentPhoto::class)->stage($this->student, $this->connection, ['revision' => str_repeat('a', 64)]);
    Http::fake(['https://eduadmin.test/*' => Http::response('PRIVATE-ERROR-BODY', 503)]);
    $this->artisan('hikvision:enrollments-work', ['--limit' => 1])->assertSuccessful();
    expect(DB::table('ec_hikvision_enrollment_photos')->value('attempts'))->toBe(1);
    $this->artisan('hikvision:enrollments-work')->assertSuccessful();
    Http::assertSentCount(1);
    DB::table('ec_hikvision_enrollment_photos')->update(['retry_at' => null, 'lease_until' => now()->getTimestamp() + 120]);
    app(EnrollmentPhoto::class)->prepare($this->student->id);
    Http::assertSentCount(1);
    DB::table('ec_hikvision_enrollment_photos')->update(['lease_until' => 0, 'attempts' => 5]);
    app(EnrollmentPhoto::class)->prepare($this->student->id);
    Http::assertSentCount(1);
    Sanctum::actingAs(AdminUser::create(['name' => 'Synthetic admin', 'email' => 'retry@example.test', 'password' => 'test-only', 'role' => 'super_admin', 'is_active' => true]));
    $this->postJson('/api/admin/v2/hikvision-enrollment-photos/'.DB::table('ec_hikvision_enrollment_photos')->value('id').'/retry')->assertOk();
    expect(DB::table('ec_hikvision_enrollment_photos')->value('attempts'))->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('state'))->toBe('photo_pending');
    expect(json_encode(DB::table('ec_hikvision_enrollment_photos')->get()))->not->toContain('PRIVATE-ERROR-BODY');
});

it('discards a photo fetch that loses a race to a newer application upload', function (): void {
    $oldRevision = str_repeat('a', 64);
    app(EnrollmentPhoto::class)->stage($this->student, $this->connection, ['revision' => $oldRevision]);
    Http::fake(function () use ($oldRevision) {
        app(EnrollmentPhoto::class)->stage($this->student->refresh(), $this->connection, ['revision' => str_repeat('b', 64)]);
        $bytes = enrollSyntheticJpeg();

        return Http::response($bytes, 200, ['X-Enrollment-Photo-Revision' => $oldRevision, 'X-Content-SHA256' => hash('sha256', $bytes)]);
    });
    app(EnrollmentPhoto::class)->prepare($this->student->id);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('source_revision'))->toBe(str_repeat('b', 64));
    expect(DB::table('ec_hikvision_enrollment_photos')->value('photo_encrypted'))->toBeNull();
    expect(DB::table('ec_hikvision_enrollment_photos')->value('state'))->toBe('photo_pending');
});

it('serves the operator page without activating device writes', function (): void {
    config(['hikvision_push.enrollment_enabled' => false]);
    $admin = AdminUser::create(['name' => 'Synthetic admin', 'email' => 'page@example.test', 'password' => 'test-only', 'role' => 'super_admin', 'is_active' => true]);
    $this->actingAs($admin, 'admin')->get('/admin/hikvision-enrollments')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/HikvisionEnrollments/Index')->where('enabled', false));
    expect(DB::table('ec_hikvision_enrollment_commands')->count())->toBe(0);
});

it('reconciles application roster updates and distinguishes omitted photo fields from explicit deletion', function (): void {
    app(MappingService::class)->upsert($this->connection, 'school', $this->student->school_id, 'school', '7');
    $record = ['id' => '42', 'school_id' => '7', 'student_number' => 'TEST-1', 'first_name' => 'Synthetic', 'last_name' => 'Portrait',
        'status' => 'enrolled', 'enrollment_photo' => ['revision' => str_repeat('a', 64)]];
    $stats = app(SyncCoordinator::class)->importResourceRecords($this->connection, 'students', [$record]);
    expect($stats['failed'])->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('state'))->toBe('photo_pending');
    unset($record['enrollment_photo']);
    app(SyncCoordinator::class)->importResourceRecords($this->connection, 'students', [$record]);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('source_revision'))->toBe(str_repeat('a', 64));
    $record['enrollment_photo'] = ['not_a_revision' => true];
    $invalid = app(SyncCoordinator::class)->importResourceRecords($this->connection, 'students', [$record]);
    expect($invalid['failed'])->toBe(1);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('source_revision'))->toBe(str_repeat('a', 64));
    $record['enrollment_photo'] = null;
    app(SyncCoordinator::class)->importResourceRecords($this->connection, 'students', [$record]);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('source_revision'))->toBeNull();
});

it('binds profile approval to the exact firmware and keeps cleanup working when an assignment is disabled', function (): void {
    enrollStagePhoto($this);
    enrollComplete($this);
    $this->artisan('hikvision:enrollment-profile', ['device_id' => $this->device->id, '--disable' => true])->assertSuccessful();
    app(EnrollmentRoster::class)->reconcile($this->student->id);
    expect(enrollPoll($this)['URL'])->toContain('UserInfoDetail/Delete?');
    DB::table('ec_hikvision_push_devices')->where('id', $this->pushId)->update(['firmware' => 'NEW-FIRMWARE']);
    Carbon::setTestNow(now()->addSeconds(31));
    $this->terminal->connect()->assertOk();
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
});

it('normalizes orientation and strips metadata from a synthetic jpeg', function (): void {
    // Synthetic EXIF IFD0 Orientation=6 (90 degrees clockwise), not a real portrait.
    $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
    $exif = "Exif\0\0".$tiff;
    $jpeg = enrollSyntheticJpeg();
    $input = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $output = EnrollmentPhoto::jpeg($input);
    $dimensions = getimagesizefromstring($output);
    expect([$dimensions[0], $dimensions[1]])->toBe([400, 320]);
    expect($output)->not->toContain('Exif');
});

it('rejects unknown or conflicting replies without advancing enrollment readiness', function (): void {
    enrollStagePhoto($this);
    $command = enrollPoll($this);
    $reply = ['commandNum' => 1, 'commandList' => [['UUID' => '00000000-0000-4000-8000-000000000000',
        'data' => base64_encode(json_encode(enrollSearch()))]]];
    $this->terminal->send('CommandResult', $reply)->assertStatus(400);
    $this->terminal->connect()->assertOk();
    enrollReply($this, $command, enrollSearch());
    $this->terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [['UUID' => $command['UUID'],
        'data' => base64_encode(json_encode(enrollSearch('SOMEONE-ELSE')))]]])->assertStatus(400);
    expect(DB::table('ec_hikvision_enrollments')->value('confirmed_revision'))->toBeNull();
});

it('requires active non-recovery admin authentication for enrollment status', function (): void {
    $this->getJson('/api/admin/v2/hikvision-enrollments')->assertUnauthorized();
    $admin = AdminUser::create(['name' => 'Synthetic admin', 'email' => 'access@example.test', 'password' => 'test-only', 'role' => 'super_admin', 'is_active' => false]);
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/v2/hikvision-enrollments')->assertForbidden();
    $admin->forceFill(['is_active' => true, 'test_recovery_only' => true])->save();
    Sanctum::actingAs($admin->refresh());
    $this->getJson('/api/admin/v2/hikvision-enrollments')->assertForbidden();
});

it('prepares software-side enrollment while an independent switch prohibits all terminal writes', function (): void {
    config(['hikvision_push.enrollment_device_writes_enabled' => false]);
    enrollStagePhoto($this);
    expect(DB::table('ec_hikvision_enrollment_photos')->value('state'))->toBe('photo_ready');
    expect($this->terminal->send('CommandRequest')->json('commandNum'))->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_commands')->where('state', 'sent')->count())->toBe(0);
    config(['hikvision_push.enrollment_device_writes_enabled' => true]);
    expect(enrollPoll($this)['URL'])->toContain('UserInfo/Search');
});

it('does not stage or fetch a portrait outside the configured TEST connection allowlist', function (): void {
    config(['hikvision_push.enrollment_connection_ids' => [0]]);
    app(EnrollmentPhoto::class)->stage($this->student, $this->connection, ['revision' => str_repeat('a', 64)]);
    expect(DB::table('ec_hikvision_enrollment_photos')->count())->toBe(0);
    config(['hikvision_push.enrollment_connection_ids' => []]);
    app(EnrollmentPhoto::class)->stage($this->student, $this->connection, ['revision' => str_repeat('a', 64)]);
    config(['hikvision_push.enrollment_connection_ids' => [0]]);
    app(EnrollmentPhoto::class)->prepare($this->student->id);
    Http::assertNothingSent();
});

it('compiles required inbox timestamps with explicit MySQL defaults instead of zero dates', function (): void {
    $connection = DB::connection();
    $oldGrammar = $connection->getSchemaGrammar();
    $oldBuilder = \Illuminate\Support\Facades\Schema::getFacadeRoot();
    try {
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\MySqlGrammar($connection));
        \Illuminate\Support\Facades\Schema::swap(new \Illuminate\Database\Schema\MySqlBuilder($connection));
        $queries = $connection->pretend(fn () => (require base_path('database/migrations/2026_10_10_000020_create_hikvision_push_inbox.php'))->up());
        $create = collect($queries)->first(fn ($query) => str_starts_with($query['query'], 'create table `ec_hikvision_push_commands`'));
        expect($create['query'])->toContain('`created_at` timestamp not null default CURRENT_TIMESTAMP');
        expect($create['query'])->not->toContain('0000-00-00');
    } finally {
        $connection->setSchemaGrammar($oldGrammar);
        \Illuminate\Support\Facades\Schema::swap($oldBuilder);
    }
});

function enrollSyntheticJpeg(int $shade = 10): string
{
    $image = imagecreatetruecolor(320, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, $shade, 100, 140));
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function enrollStagePhoto($test, string $revisionChar = 'a', int $shade = 10): void
{
    $revision = str_repeat($revisionChar, 64);
    $bytes = enrollSyntheticJpeg($shade);
    app(EnrollmentPhoto::class)->stage($test->student->refresh(), $test->connection, ['revision' => $revision]);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://eduadmin.test/*' => Http::response($bytes, 200,
        ['X-Enrollment-Photo-Revision' => $revision, 'X-Content-SHA256' => hash('sha256', $bytes)])]);
    app(EnrollmentPhoto::class)->prepare($test->student->id);
}

function enrollPoll($test): array
{
    $response = $test->terminal->send('CommandRequest')->assertOk();
    expect($response->json('commandNum'))->toBe(1);

    return $response->json('commandList.0');
}

function enrollReply($test, array $command, array $body): void
{
    $test->terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [
        ['UUID' => $command['UUID'], 'data' => base64_encode(json_encode($body, JSON_THROW_ON_ERROR))],
    ]])->assertOk();
}

function enrollSearch(?string $person = null): array
{
    return ['UserInfoSearch' => ['numOfMatches' => $person ? 1 : 0, 'UserInfo' => $person ? [['employeeNo' => $person, 'numOfFace' => 1, 'onlyVerify' => true]] : []]];
}

function enrollComplete($test): void
{
    enrollReply($test, enrollPoll($test), enrollSearch());
    enrollReply($test, enrollPoll($test), ['statusCode' => 1]);
    $face = enrollPoll($test);
    expect($face['dataFormat'])->toBe('boundaryData');
    enrollReply($test, $face, ['statusCode' => 1]);
    enrollReply($test, enrollPoll($test), enrollSearch(DB::table('ec_hikvision_enrollments')->value('person_identifier')));
}
