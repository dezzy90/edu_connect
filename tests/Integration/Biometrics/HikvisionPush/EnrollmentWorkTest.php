<?php

use App\Services\Biometrics\Hikvision\EnrollmentPhoto;
use App\Services\Biometrics\Hikvision\EnrollmentWork;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->gate = GateFixture::boot();
    (require base_path('database/migrations/2026_10_19_000021_create_hikvision_enrollments.php'))->up();
    (require base_path('database/migrations/2026_10_21_000026_create_hikvision_enrollment_work.php'))->up();
    Carbon::setTestNow('2026-10-21T06:00:00Z');
    config(['hikvision_push.enrollment_enabled' => true, 'hikvision_push.enrollment_work_enabled' => true,
        'hikvision_push.enrollment_connection_ids' => [$this->gate['connection']->id]]);
    $this->gate['student']->update(['source_system' => 'edu_admin', 'source_id' => '2934', 'device_sync_enabled' => true]);
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

function enrollmentWorkStage(object $test, string $revision = 'a'): void
{
    app(EnrollmentPhoto::class)->stage($test->gate['student'], $test->gate['connection'], ['revision' => str_repeat($revision, 64)]);
}

function enrollmentWorkPhoto(string $revision): \GuzzleHttp\Promise\PromiseInterface
{
    $image = imagecreatetruecolor(300, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 45, 90, 135));
    ob_start();
    imagejpeg($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return Http::response($bytes, 200, ['X-Enrollment-Photo-Revision' => $revision, 'X-Content-SHA256' => hash('sha256', $bytes)]);
}

it('durably coalesces source-photo changes without issuing device writes or downloading inside source import', function (): void {
    enrollmentWorkStage($this);
    enrollmentWorkStage($this); // An identical source refresh must not continuously create work.
    enrollmentWorkStage($this, 'b');
    expect(DB::table('ec_hikvision_enrollment_work')->count())->toBe(1);
    expect((int) DB::table('ec_hikvision_enrollment_work')->value('requested_version'))->toBe(2);
    expect((int) DB::table('ec_hikvision_enrollment_work')->value('processed_version'))->toBe(0);
    Http::assertNothingSent();
});

it('prioritizes a changed profile ahead of a fifteen-hundred-profile reconciliation sweep', function (): void {
    enrollmentWorkStage($this);
    $students = $photos = [];
    for ($i = 10000; $i < 11500; $i++) {
        $students[] = ['id' => $i, 'tenant_id' => $this->gate['tenant']->id, 'school_id' => $this->gate['school']->id,
            'student_number' => 'SYNTHETIC-WORK-'.$i, 'first_name' => 'SYNTHETIC', 'last_name' => 'Not selected', 'status' => 'enrolled'];
        $photos[] = ['student_id' => $i, 'connection_id' => $this->gate['connection']->id, 'state' => 'missing_photo'];
    }
    foreach (array_chunk($students, 100) as $chunk) {
        DB::table('ec_students')->insert($chunk);
    }
    foreach (array_chunk($photos, 100) as $chunk) {
        DB::table('ec_hikvision_enrollment_photos')->insert($chunk);
    }
    Http::fake(['https://eduadmin.test/*' => enrollmentWorkPhoto(str_repeat('a', 64))]);
    expect(app(EnrollmentWork::class)->work(1, true))->toBe(['processed' => 1, 'failed' => 0]);
    expect(DB::table('ec_hikvision_enrollment_photos')->where('student_id', $this->gate['student']->id)->value('state'))->toBe('photo_ready');
    expect(DB::table('ec_hikvision_enrollment_photos')->whereNull('last_reconciled_at')->count())->toBe(1500);
    Http::assertSentCount(1);
});

it('keeps a newer portrait change pending when it arrives during preparation of an older revision', function (): void {
    enrollmentWorkStage($this);
    $test = $this;
    Http::fake(['https://eduadmin.test/*' => function ($request) use ($test) {
        enrollmentWorkStage($test, 'b');

        return enrollmentWorkPhoto(str_repeat('a', 64));
    }]);
    app(EnrollmentWork::class)->work(1);
    $row = DB::table('ec_hikvision_enrollment_work')->sole();
    expect((int) $row->requested_version)->toBe(2)->and((int) $row->processed_version)->toBe(1);
    expect(DB::table('ec_hikvision_enrollment_photos')->sole()->prepared_revision)->toBeNull();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://eduadmin.test/*' => enrollmentWorkPhoto(str_repeat('b', 64))]);
    app(EnrollmentWork::class)->work(1);
    expect(DB::table('ec_hikvision_enrollment_photos')->sole()->prepared_revision)->toBe(str_repeat('b', 64));
    expect((int) DB::table('ec_hikvision_enrollment_work')->value('processed_version'))->toBe(2);
});

it('honours a live lease and recovers abandoned dirty work after lease expiry', function (): void {
    enrollmentWorkStage($this);
    DB::table('ec_hikvision_enrollment_work')->update(['lease_token' => 'SYNTHETIC-abandoned', 'lease_until' => now()->timestamp + 120]);
    expect(app(EnrollmentWork::class)->work()['processed'])->toBe(0);
    Http::assertNothingSent();
    Carbon::setTestNow(now()->addSeconds(121));
    Http::fake(['https://eduadmin.test/*' => enrollmentWorkPhoto(str_repeat('a', 64))]);
    expect(app(EnrollmentWork::class)->work()['processed'])->toBe(1);
    expect(DB::table('ec_hikvision_enrollment_work')->sole()->lease_token)->toBeNull();
});

it('backs off failed downloads without consuming the change signal or retaining private errors', function (): void {
    enrollmentWorkStage($this);
    Http::fake(['https://eduadmin.test/*' => Http::response('PRIVATE-SOURCE-ERROR', 503)]);
    app(EnrollmentWork::class)->work(1);
    $row = DB::table('ec_hikvision_enrollment_work')->sole();
    expect((int) $row->processed_version)->toBe(0)->and($row->error_code)->toBe('photo_retry_pending');
    expect(app(EnrollmentWork::class)->work()['processed'])->toBe(0);
    Http::assertSentCount(1);
    expect(DB::table('ec_hikvision_enrollment_work')->get()->toJson())->not->toContain('PRIVATE-SOURCE-ERROR');
});

it('leaves disabled features and out-of-scope source connections untouched', function (): void {
    config(['hikvision_push.enrollment_work_enabled' => false]);
    enrollmentWorkStage($this);
    expect(DB::table('ec_hikvision_enrollment_work')->count())->toBe(0);
    config(['hikvision_push.enrollment_work_enabled' => true, 'hikvision_push.enrollment_connection_ids' => [999]]);
    EnrollmentWork::request($this->gate['student']->id);
    expect(app(EnrollmentWork::class)->work(20, true)['processed'])->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_work')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rolls a source signal back with the source transaction rather than processing an uncommitted portrait', function (): void {
    DB::beginTransaction();
    enrollmentWorkStage($this);
    expect(DB::table('ec_hikvision_enrollment_work')->count())->toBe(1);
    DB::rollBack();
    expect(DB::table('ec_hikvision_enrollment_work')->count())->toBe(0);
    expect(DB::table('ec_hikvision_enrollment_photos')->count())->toBe(0);
});

it('marks an imported suspension dirty even when the source update omits its portrait field', function (): void {
    enrollmentWorkStage($this);
    app(\App\Services\Integration\SyncCoordinator::class)->importResourceRecords($this->gate['connection'], 'students', [[
        'id' => 2934, 'school_id' => 26, 'student_number' => 'SYNTHETIC-GATE', 'first_name' => 'Synthetic', 'last_name' => 'Adult',
        'status' => 'suspended',
    ]]);
    expect($this->gate['student']->fresh()->status)->toBe('suspended');
    expect((int) DB::table('ec_hikvision_enrollment_work')->value('requested_version'))->toBe(2);
    Http::assertNothingSent();
});
