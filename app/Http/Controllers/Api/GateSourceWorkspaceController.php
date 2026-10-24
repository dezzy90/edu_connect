<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V2\Admin\GateWorkspaceController;
use App\Http\Controllers\Api\V2\Admin\GateSetupController;
use App\Models\AdminUser;
use App\Services\Biometrics\Gate\SourceWorkspaceContext;
use App\Services\Biometrics\Gate\StaffingPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A bounded, signed application bridge. Browser tokens and role claims are never accepted here. */
final class GateSourceWorkspaceController extends Controller
{
    public const PATH = '/api/integrations/edu-admin/gate-workspace';

    public function __invoke(Request $request)
    {
        abort_unless(config('gate_workspace.enabled'), 404);
        abort_unless($request->isSecure() && strlen($request->getContent()) <= 32768, 400);
        $id = $request->header('X-Gate-Key', '');
        $stamp = $request->header('X-Gate-Time', '');
        $nonce = $request->header('X-Gate-Nonce', '');
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $id) && preg_match('/^[0-9]{10}$/D', $stamp)
            && abs(now()->timestamp - (int) $stamp) <= 60 && preg_match('/^[a-f0-9]{48}$/D', $nonce), 401);
        $bridge = DB::table('ec_gate_staff_bridges')->where('id', $id)->where('enabled', true)->first();
        abort_unless($bridge, 401);
        $signature = hash_hmac('sha256', "POST\n".self::PATH."\n".$stamp."\n".$nonce."\n".hash('sha256', $request->getContent()), Crypt::decryptString($bridge->secret));
        abort_unless(hash_equals($signature, $request->header('X-Gate-Signature', '')), 401);
        // Unique insert is the cross-worker replay barrier; an invalid signature never consumes a nonce.
        abort_unless(DB::table('ec_gate_staff_nonces')->insertOrIgnore(['id' => hash('sha256', $id.'|'.$nonce), 'expires_at' => now()->addMinutes(3)]) === 1, 409, 'This signed request was already used.');
        DB::table('ec_gate_staff_nonces')->where('expires_at', '<', now())->delete();
        $input = $request->validate([
            'complex_id' => ['required', 'integer', 'min:1'], 'operation' => ['required', Rule::in(['status', 'days', 'detail', 'action', 'setup', 'save_setup', 'activate', 'pause'])],
            'actor' => ['required', 'array:id,name,schools'], 'actor.id' => ['required', 'integer', 'min:1'],
            'actor.name' => ['required', 'string', 'max:150'], 'actor.schools' => ['required', 'array', 'min:1', 'max:100'],
            'actor.schools.*' => ['required', 'array:id,roles,class_ids,permissions'], 'actor.schools.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'actor.schools.*.permissions' => ['sometimes', 'array', 'max:2'],
            'actor.schools.*.permissions.*' => [Rule::in(['configure', 'activate'])],
            'actor.schools.*.class_ids' => ['sometimes', 'array', 'min:1', 'max:200'], 'actor.schools.*.class_ids.*' => ['integer', 'min:1'],
            'actor.schools.*.roles' => ['required', 'array', 'min:1', 'max:3'],
            'actor.schools.*.roles.*' => ['required', Rule::in(['secretary', 'discipline_master', 'attendance_viewer'])],
            'school_id' => ['required', 'integer', 'min:1'], 'day' => ['required', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'day_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'array'],
            'setup_body' => ['sometimes', 'array'], 'publication_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        abort_unless((int) $bridge->source_complex_id === $input['complex_id'], 403);
        $connection = DB::table('ec_integration_connections as c')->join('ec_tenants as t', 't.id', '=', 'c.tenant_id')
            ->where('c.id', $bridge->connection_id)->where('c.status', 'active')->where('t.status', 'active')->whereNull('t.deleted_at')->first(['c.*']);
        abort_unless($connection && in_array((int) $connection->tenant_id, StaffingPolicy::secretaryComplexes() ?? [], true), 403);
        $maps = DB::table('ec_integration_mappings as m')->join('ec_schools as s', 's.id', '=', 'm.local_id')
            ->where('m.connection_id', $connection->id)->where('m.external_type', 'school')->where('m.local_type', 'school')->where('s.tenant_id', $connection->tenant_id)
            ->where('s.status', 'active')->whereNull('s.deleted_at')->get(['m.external_id', 's.id', 's.type']);
        $allowed = json_decode($connection->enabled_school_ids ?? '[]', true);
        $scope = []; $classScopes = []; $permissions = [];
        foreach ($input['actor']['schools'] as $grant) {
            $matches = $maps->where('external_id', (string) $grant['id']);
            if ($matches->isEmpty()) continue; // A newly created source school may not be connected yet.
            abort_unless($matches->count() === 1, 403);
            $school = $matches->sole();
            if (! in_array((int) $school->id, $allowed, true) || ! in_array((int) $school->id, config('gate.school_ids', []), true)) continue;
            $roles = array_values(array_unique($grant['roles']));
            abort_if(in_array('discipline_master', $roles, true) && $school->type !== 'secondary', 403);
            $scope[(int) $school->id] = $roles;
            $permissions[(int) $school->id] = array_values(array_unique($grant['permissions'] ?? []));
            if (isset($grant['class_ids'])) {
                abort_unless($roles === ['attendance_viewer'] && $permissions[(int) $school->id] === [], 403);
                $classIds = DB::table('ec_integration_mappings as cm')->join('ec_classes as c', 'c.id', '=', 'cm.local_id')
                    ->where('cm.connection_id', $connection->id)->where('cm.local_type', 'class')->where('cm.external_type', 'class')
                    ->whereIn('cm.external_id', array_map('strval', $grant['class_ids']))->where('c.school_id', $school->id)->where('c.tenant_id', $connection->tenant_id)->whereNull('c.deleted_at')->pluck('c.id');
                $classScopes[(int) $school->id] = DB::table('ec_students')->where('tenant_id', $connection->tenant_id)->where('school_id', $school->id)
                    ->whereIn('class_id', $classIds)->whereNull('deleted_at')->limit(20001)->pluck('id')->map(fn ($v) => (int) $v)->all();
                abort_if(count($classScopes[(int) $school->id]) > 20000, 409);
            }
        }
        $selected = $maps->where('external_id', (string) $input['school_id']);
        abort_unless($selected->count() === 1 && isset($scope[(int) $selected->sole()->id]), 404);
        $schoolId = (int) $selected->sole()->id;
        $actor = $this->principal($bridge, $input['actor'], $scope, (int) $connection->tenant_id, $permissions);
        $context = new SourceWorkspaceContext;
        $context->actorId = (int) $actor->id;
        $context->studentsBySchool = $classScopes;
        $context->permissionsBySchool = $permissions;
        app()->instance(SourceWorkspaceContext::class, $context);
        try {
            if (in_array($input['operation'], ['setup', 'save_setup', 'activate', 'pause'], true)) {
                abort_unless($context->allows($actor, $schoolId, 'configure'), 403);
                if (in_array($input['operation'], ['activate', 'pause'], true)) abort_unless($context->allows($actor, $schoolId, 'activate'), 403);
                $inner = Request::create('/internal-gate-setup', 'POST', [], [], [],
                    ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($input['setup_body'] ?? [], JSON_THROW_ON_ERROR));
                $inner->setUserResolver(fn () => $actor);
                $controller = app(GateSetupController::class);
                if ($input['operation'] === 'setup') {
                    $data = app(\App\Services\Biometrics\Gate\SetupDrafts::class)->read($actor, $schoolId);
                    // Do not expose separate application URLs, source endpoints or paginated student records.
                    unset($data['students'], $data['selectedStudents'], $data['connection']);
                    return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
                }
                return match ($input['operation']) {
                    'save_setup' => $controller->store($inner, $schoolId),
                    'activate' => $controller->activate($inner, $schoolId),
                    'pause' => $controller->pause($inner, $schoolId, $input['publication_id'] ?? 0),
                };
            }
            if ($input['operation'] === 'status') {
                return response()->json(['data' => $this->status($connection, $schoolId, $input['day'])])->header('Cache-Control', 'private, no-store');
            }
            $dayId = $input['day_id'] ?? null;
            if (in_array($input['operation'], ['detail', 'action'], true)) {
                abort_unless($dayId && DB::table('ec_gate_days')->where('id', $dayId)->where('tenant_id', $connection->tenant_id)->where('school_id', $schoolId)->where('day', $input['day'])->exists(), 404);
            }
            $inner = Request::create('/internal-gate', $input['operation'] === 'action' ? 'POST' : 'GET',
                $input['operation'] === 'action' ? [] : ['school_id' => $schoolId, 'day' => $input['day'], 'page' => $input['page'] ?? 1], [], [],
                ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
                $input['operation'] === 'action' ? json_encode($input['action'] ?? [], JSON_THROW_ON_ERROR) : null);
            $inner->setUserResolver(fn () => $actor);
            $controller = app(GateWorkspaceController::class);
            $response = match ($input['operation']) {
                'days' => $controller->index($inner), 'detail' => $controller->show($inner, $dayId),
                'action' => $controller->store($inner, $dayId),
            };
            $data = $response->getData(true);
            $studentMaps = DB::table('ec_integration_mappings')->where('connection_id', $connection->id)
                ->where('local_type', 'student')->where('external_type', 'student')->pluck('external_id', 'local_id');
            if (isset($data['data']['days']['data'])) {
                foreach ($data['data']['days']['data'] as &$row) $row['student_id'] = isset($studentMaps[$row['student_id']]) ? (int) $studentMaps[$row['student_id']] : null;
                unset($row);
            }
            if (isset($data['data']['detail']['student']['id'])) {
                $localId = $data['data']['detail']['student']['id'];
                $data['data']['detail']['student']['id'] = isset($studentMaps[$localId]) ? (int) $studentMaps[$localId] : null;
            }
            // Only the requested school is exposed, never another school's bridge mapping or setup URL.
            unset($data['data']['schools'], $data['data']['setupUrl']);
            return response()->json($data, $response->status())->header('Cache-Control', 'private, no-store');
        } finally {
            app()->forgetInstance(SourceWorkspaceContext::class);
        }
    }

    private function principal(object $bridge, array $actor, array $scope, int $tenant, array $permissions): AdminUser
    {
        return DB::transaction(function () use ($bridge, $actor, $scope, $tenant, $permissions): AdminUser {
            // Serialise creation/refresh of this bridge's identities. Never adopt an existing email account.
            DB::table('ec_gate_staff_bridges')->where('id', $bridge->id)->lockForUpdate()->sole();
            $binding = DB::table('ec_gate_source_actors')->where('bridge_id', $bridge->id)->where('source_user_id', $actor['id'])->first();
            if ($binding) {
                $user = AdminUser::whereKey($binding->admin_id)->lockForUpdate()->first();
                abort_unless($user && $user->gate_source_only && $user->is_active, 403);
                $user->forceFill(['name' => $actor['name']])->save();
                DB::table('ec_gate_source_actors')->where('id', $binding->id)->update(['authority_until' => now()->utc()->addSeconds(60)]);
            } else {
                $email = 'source-'.hash('sha256', $bridge->id.'|'.$actor['id']).'@audit.invalid';
                abort_if(AdminUser::where('email', $email)->exists(), 409, 'Source audit identity collision requires operator review.');
                $user = new AdminUser;
                $user->forceFill(['name' => $actor['name'], 'email' => $email, 'password' => Hash::make(Str::random(96)),
                    'role' => 'school_admin', 'is_active' => true, 'gate_only' => true, 'gate_source_only' => true])->save();
                DB::table('ec_gate_source_actors')->insert(['bridge_id' => $bridge->id, 'source_user_id' => $actor['id'], 'admin_id' => $user->id, 'authority_until' => now()->utc()->addSeconds(60)]);
            }
            DB::table('ec_gate_operator_assignments')->where('admin_id', $user->id)->update(['active' => false]);
            foreach (['ec_gate_setup_assignments', 'ec_gate_activation_assignments'] as $table) {
                DB::table($table)->where('admin_id', $user->id)->update(['active' => false]);
                $permission = $table === 'ec_gate_setup_assignments' ? 'configure' : 'activate';
                foreach ($permissions as $school => $grants) {
                    if (in_array($permission, $grants, true)) DB::table($table)->updateOrInsert(
                        ['admin_id' => $user->id, 'tenant_id' => $tenant, 'school_id' => $school], ['active' => true]);
                }
            }
            foreach ($scope as $school => $roles) {
                foreach ($roles as $role) {
                    DB::table('ec_gate_operator_assignments')->updateOrInsert(['admin_id' => $user->id, 'tenant_id' => $tenant, 'school_id' => $school, 'role' => $role], ['active' => true]);
                }
            }
            return $user;
        }, 3);
    }

    private function status(object $connection, int $school, string $day): array
    {
        $states = DB::table('ec_hikvision_enrollments as e')->join('ec_students as s', 's.id', '=', 'e.student_id')
            ->join('ec_integration_mappings as m', fn ($q) => $q->on('m.local_id', '=', 's.id')->where('m.local_type', 'student')->where('m.external_type', 'student')->where('m.connection_id', $connection->id))
            ->where('e.tenant_id', $connection->tenant_id)->where('e.school_id', $school)->where('s.tenant_id', $connection->tenant_id)->where('s.school_id', $school)
            ->where('e.desired_present', true)->whereNull('s.deleted_at')
            ->when(array_key_exists($school, app(SourceWorkspaceContext::class)->studentsBySchool), fn ($q) => $q->whereIn('s.id', app(SourceWorkspaceContext::class)->studentsBySchool[$school]))
            ->select('m.external_id as source_id', 'e.state')->limit(20001)->get();
        abort_if($states->count() > 20000, 409, 'School roster exceeds workspace limit.');
        $policies = DB::table('ec_gate_policies')->where('tenant_id', $connection->tenant_id)->where('school_id', $school)->where('day', $day)
            ->where('enabled', true)->get(['snapshot']);
        $scheduled = $policies->isEmpty() ? null : $policies->contains(fn ($p) => ! (json_decode($p->snapshot, true)['closed'] ?? true));
        $devices = DB::table('ec_device_school_assignments as a')->join('ec_hikvision_push_devices as p', 'p.device_id', '=', 'a.device_id')
            ->leftJoin('ec_hikvision_push_sessions as s', 's.push_device_id', '=', 'p.id')->where('a.tenant_id', $connection->tenant_id)->where('a.school_id', $school)->where('a.active', true)->where('p.enabled', true)
            ->get(['a.device_id', 's.phase', 's.last_seen_at', 's.expires_at'])->map(fn ($d) => ['device_id' => $d->device_id, 'last_seen_at' => $d->last_seen_at,
                'online' => $d->phase === 'online' && $d->expires_at > now()->timestamp && $d->last_seen_at && \Carbon\CarbonImmutable::parse($d->last_seen_at, 'UTC')->greaterThan(now()->subMinutes(2))])->values();
        return ['checked_at' => now()->utc()->toIso8601String(), 'source_synced_at' => $connection->last_successful_sync_at, 'devices' => $devices,
            'scheduled' => $scheduled, 'publication_active' => DB::table('ec_gate_publications')->where('tenant_id', $connection->tenant_id)->where('school_id', $school)->where('state', 'active')->exists(),
            'enrollments' => $states->map(fn ($s) => ['student_id' => (int) $s->source_id, 'state' => $s->state])->values(),
            'note' => 'Gate attendance and classroom attendance are separate. No scan is not proof of absence.'];
    }
}
