<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class HikvisionEnrollmentController extends Controller
{
    public function index(Request $request)
    {
        $admin = $this->admin($request);
        $filters = $request->validate(['page' => 'sometimes|integer|min:1', 'school_id' => 'sometimes|integer|min:1']);
        $enabled = (bool) config('hikvision_push.enrollment_enabled');
        $payload = ['enabled' => $enabled, 'deviceWritesEnabled' => (bool) config('hikvision_push.enrollment_device_writes_enabled'),
            'profiles' => null, 'enrollments' => null];
        if ($enabled) {
            $profiles = DB::table('ec_hikvision_enrollment_photos as p')->join('ec_students as s', 's.id', '=', 'p.student_id');
            $targets = DB::table('ec_hikvision_enrollments as e')->join('ec_students as s', 's.id', '=', 'e.student_id')
                ->join('ec_hikvision_push_devices as d', 'd.id', '=', 'e.push_device_id')
                ->leftJoin('ec_hikvision_push_sessions as session', 'session.push_device_id', '=', 'd.id')
                ->join('ec_biometric_devices as b', 'b.id', '=', 'd.device_id');
            $this->scope($profiles, $admin, 's.school_id', 's.tenant_id');
            $this->scope($targets, $admin, 'e.school_id', 'e.tenant_id');
            if (isset($filters['school_id'])) {
                $profiles->where('s.school_id', $filters['school_id']);
                $targets->where('e.school_id', $filters['school_id']);
            }
            $payload['profiles'] = $profiles->select('p.id', 'p.student_id', 's.school_id', 's.first_name', 's.last_name',
                'p.state', 'p.error_code', 'p.attempts', 'p.retry_at')->orderBy('p.id')->paginate(50, ['*'], 'page');
            $payload['enrollments'] = $targets->select('e.id', 'e.student_id', 'e.school_id', 'e.person_identifier',
                'e.state', 'e.error_code', 'e.desired_present', 'e.last_confirmed_at', 'e.recognition_confirmed_at',
                'e.desired_revision', 'e.confirmed_revision', 'b.name as device_name', 'b.settings', 'session.last_seen_at')
                ->orderBy('e.id')->paginate(50, ['*'], 'page')->through(static function ($row): array {
                    $profile = (json_decode($row->settings ?? '{}', true) ?: [])['hikvision_enrollment'] ?? [];

                    return ['id' => $row->id, 'student_id' => $row->student_id, 'school_id' => $row->school_id,
                        'person_identifier' => $row->person_identifier, 'state' => $row->state, 'error_code' => $row->error_code,
                        'desired_present' => (bool) $row->desired_present, 'device_name' => $row->device_name,
                        'device_approved' => ($profile['verified'] ?? false) === true,
                        'device_last_seen_at' => $row->last_seen_at, 'last_confirmed_at' => $row->last_confirmed_at,
                        'recognition_confirmed_at' => $row->recognition_confirmed_at,
                        'current_version_confirmed' => $row->desired_revision !== null && $row->desired_revision === $row->confirmed_revision];
                });
        }

        if ($request->routeIs('admin.hikvision-enrollments.*')) {
            Inertia::setRootView('gate-operator');
        }

        return $request->routeIs('admin.hikvision-enrollments.*')
            ? Inertia::render('Admin/HikvisionEnrollments/Index', $payload + ['admin' => $admin->load('school'), 'csrfToken' => csrf_token()])
            : response()->json(['data' => $payload])->header('Cache-Control', 'private, no-store');
    }

    public function retryPhoto(Request $request, int $photo)
    {
        $admin = $this->admin($request);
        abort_unless(config('hikvision_push.enrollment_enabled'), 409, 'Enrollment is disabled.');
        $query = DB::table('ec_hikvision_enrollment_photos as p')->join('ec_students as s', 's.id', '=', 'p.student_id')->where('p.id', $photo);
        $this->scope($query, $admin, 's.school_id', 's.tenant_id');
        $row = $query->select('p.id')->first();
        abort_unless($row, 404);
        $changed = DB::table('ec_hikvision_enrollment_photos')->where('id', $row->id)->where('state', 'photo_unavailable')
            ->where('lease_until', '<=', now()->getTimestamp())->update([
                'state' => 'photo_pending', 'attempts' => 0, 'retry_at' => null, 'error_code' => null, 'updated_at' => now(),
            ]);
        abort_unless($changed, 409, 'Only unavailable downloads can be retried. Replace a rejected portrait in EduAdmin.');
        \App\Services\Biometrics\Hikvision\EnrollmentWork::request((int) DB::table('ec_hikvision_enrollment_photos')->where('id', $row->id)->value('student_id'));

        return $this->reply($request);
    }

    public function retryEnrollment(Request $request, int $enrollment)
    {
        $admin = $this->admin($request);
        abort_unless(config('hikvision_push.enrollment_enabled'), 409, 'Enrollment is disabled.');
        $query = DB::table('ec_hikvision_enrollments as e')->where('e.id', $enrollment);
        $this->scope($query, $admin, 'e.school_id', 'e.tenant_id');
        $visible = $query->first();
        abort_unless($visible, 404);
        DB::transaction(function () use ($visible): void {
            // Same lock order as terminal polling: device, then enrollment.
            DB::table('ec_hikvision_push_devices')->where('id', $visible->push_device_id)->lockForUpdate()->sole();
            $target = DB::table('ec_hikvision_enrollments')->where('id', $visible->id)->lockForUpdate()->sole();
            abort_unless($target->desired_present && $target->desired_revision && ! $target->removal_in_progress
                && in_array($target->error_code, ['device_capacity_exceeded', 'device_rejected', 'face_not_confirmed'], true), 409,
                'This enrollment requires review or a replacement portrait; it cannot be retried automatically.');
            $pending = DB::table('ec_hikvision_enrollment_commands as c')->join('ec_hikvision_enrollments as e', 'e.id', '=', 'c.enrollment_id')
                ->where('e.push_device_id', $target->push_device_id)->whereIn('c.state', ['sent', 'obsolete_sent', 'unconfirmed'])->exists();
            abort_if($pending, 409, 'An unconfirmed device operation must be resolved first.');
            DB::table('ec_hikvision_enrollment_commands')->where('enrollment_id', $target->id)->where('state', 'queued')
                ->update(['state' => 'superseded', 'payload_encrypted' => null, 'updated_at' => now()]);
            EnrollmentRoster::queue($target->id, $target->desired_revision, $target->person_owned ? 'person' : 'preflight');
            DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update(['state' => 'queued', 'error_code' => null, 'updated_at' => now()]);
        });

        return $this->reply($request);
    }

    private function admin(Request $request): AdminUser
    {
        $admin = $request->routeIs('admin.hikvision-enrollments.*') ? $request->user('admin') : $request->user();
        abort_unless($admin instanceof AdminUser && $admin->is_active && ! $admin->test_recovery_only
            && in_array($admin->role, ['super_admin', 'school_admin'], true), 403);

        return $admin;
    }

    private function scope(Builder $query, AdminUser $admin, string $school, string $tenant): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }
        // Legacy admin IDs are NOT V2 school IDs. Scope by the explicit bridge,
        // including tenant, never by every school in the same complex.
        $query->whereExists(static function (Builder $sub) use ($admin, $school, $tenant): void {
            $sub->selectRaw('1')->from('ec_schools as authorized_school')->whereColumn('authorized_school.id', $school)
                ->whereColumn('authorized_school.tenant_id', $tenant)->where('authorized_school.source_system', 'legacy')
                ->where('authorized_school.source_id', (string) ($admin->school_id ?: 'NO-SCHOOL'));
        });
    }

    private function reply(Request $request)
    {
        return $request->routeIs('admin.hikvision-enrollments.*') && ! $request->expectsJson() ? back() : response()->json(['status' => 'queued']);
    }
}
