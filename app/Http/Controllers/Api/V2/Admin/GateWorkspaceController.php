<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Biometrics\Gate\OperatorAccess;
use App\Services\Biometrics\Gate\OperatorActions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class GateWorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $access = app(OperatorAccess::class);
        $actor = $access->actor($this->actor($request));
        $filters = $request->validate(['school_id' => ['sometimes', 'integer', 'min:1'], 'day' => ['sometimes', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $schools = $access->assignments($actor)->select('a.tenant_id', 'a.school_id', 's.name', 's.timezone')->distinct()->orderBy('a.school_id')->limit(101)->get();
        abort_unless($schools->isNotEmpty() && $schools->count() <= 100, 403, 'An explicit active gate assignment is required.');
        $school = $schools->firstWhere('school_id', $filters['school_id'] ?? $schools->first()->school_id);
        abort_unless($school, 404);
        $date = $filters['day'] ?? CarbonImmutable::now($school->timezone)->format('Y-m-d');
        $roles = $access->roles($actor, $school);
        $days = DB::table('ec_gate_days as d')->join('ec_students as s', function ($q): void {
            $q->on('s.id', '=', 'd.student_id')->on('s.tenant_id', '=', 'd.tenant_id');
        })->where('d.tenant_id', $school->tenant_id)->where('d.school_id', $school->school_id)->where('d.day', $date)
            ->select('d.id', 'd.student_id', 'd.day', 'd.state', 'd.revision', 's.first_name', 's.last_name', 's.student_number')
            ->when($actor->gate_source_only && array_key_exists((int) $school->school_id, app(\App\Services\Biometrics\Gate\SourceWorkspaceContext::class)->studentsBySchool),
                fn ($q) => $q->whereIn('d.student_id', app(\App\Services\Biometrics\Gate\SourceWorkspaceContext::class)->studentsBySchool[(int) $school->school_id]))
            ->orderBy('s.last_name')->orderBy('d.id')->paginate(50)->withQueryString();

        return $this->reply($request, ['schools' => $schools, 'selectedSchool' => $school, 'selectedDay' => $date,
            'roles' => $roles, 'days' => $days, 'detail' => null]);
    }

    public function show(Request $request, int $day)
    {
        $access = app(OperatorAccess::class);
        $actor = $access->actor($this->actor($request));
        $row = DB::table('ec_gate_days')->where('id', $day)->first();
        abort_unless($row, 404);
        $roles = $access->roles($actor, $row);
        $request->validate(['scans_page' => ['sometimes', 'integer', 'min:1']]);
        $decisions = json_decode($row->decisions ?? '{}', true, 16, JSON_THROW_ON_ERROR);
        $observations = DB::table('ec_gate_observation_links as l')->join('ec_hikvision_observations as o', 'o.id', '=', 'l.observation_id')
            ->where('l.day_id', $day)->orderByDesc('o.id')->paginate(50, ['o.id', 'o.event_key', 'o.received_at', 'o.metadata', 'l.device_uid', 'l.policy_snapshot'], 'scans_page')
            ->through(static function ($o) use ($decisions): array {
                $m = json_decode($o->metadata, true, 16, JSON_THROW_ON_ERROR);
                $policy = json_decode($o->policy_snapshot, true, 16, JSON_THROW_ON_ERROR);

                return ['id' => $o->id, 'occurred_at' => $m['occurred_at'], 'received_at' => $o->received_at, 'device_uid' => $o->device_uid,
                    'decision' => $decisions[$o->event_key] ?? null, 'operator_rules' => $policy['operator_rules'] ?? null];
            })->withQueryString();
        $movements = DB::table('ec_gate_movements')->where('day_id', $day)->orderByDesc('created_at')->limit(201)->get()
            ->map(static function ($m): array {
                $p = json_decode($m->payload, true, 16, JSON_THROW_ON_ERROR);

                return ['event_id' => $m->event_id, 'observation_id' => $m->observation_id, 'revision' => $m->revision,
                    'state' => $m->state, 'movement' => $m->movement, 'occurred_at' => $p['occurred_at'], 'decision_source' => $p['decision_source']];
            });
        $actions = DB::table('ec_gate_actions')->where('day_id', $day)->orderByDesc('created_at')->orderByDesc('id')->limit(101)->get()
            ->map(static function ($a) use ($row): array {
                $p = json_decode($a->parameters, true, 16, JSON_THROW_ON_ERROR);

                return ['id' => $a->id, 'kind' => $a->kind, 'role' => $a->role, 'actor_id' => $a->actor_id,
                    'reason_code' => $a->reason_code, 'note' => $p['note'] ?? '', 'status' => $a->status,
                    'movement_id' => $a->movement_id, 'reference_action_id' => $a->reference_action_id,
                    'basis_revision' => $a->basis_revision, 'created_at' => $a->created_at, 'expires_at' => $a->expires_at,
                    'stale' => $a->kind === 'correction_request' && $a->status === 'pending' && (int) $a->basis_revision !== (int) $row->revision,
                    'expired' => $a->expires_at !== null && CarbonImmutable::parse($a->expires_at, 'UTC')->isPast(),
                    'consumed_observation_id' => $a->consumed_observation_id];
            });
        $school = DB::table('ec_schools')->where('id', $row->school_id)->where('tenant_id', $row->tenant_id)->sole(['name', 'timezone', 'id as school_id']);
        $student = DB::table('ec_students')->where('id', $row->student_id)->where('tenant_id', $row->tenant_id)->first(['id', 'first_name', 'last_name', 'student_number']);

        return $this->reply($request, ['schools' => [], 'selectedSchool' => $school, 'selectedDay' => $row->day,
            'roles' => $roles, 'days' => null, 'detail' => ['id' => $row->id, 'student' => $student, 'day' => $row->day,
                'state' => $row->state, 'revision' => $row->revision, 'observations' => $observations,
                'early_exit' => app(OperatorActions::class)->preview($row),
                'movements' => $movements->take(200)->values(), 'movements_truncated' => $movements->count() > 200,
                'actions' => $actions->take(100)->values(), 'actions_truncated' => $actions->count() > 100]]);
    }

    public function store(Request $request, int $day)
    {
        $actor = app(OperatorAccess::class)->actor($this->actor($request));
        $body = $request->json()->all();
        abort_unless($request->isJson(), 415, 'JSON action body required.');
        $operation = $body['operation'] ?? '';
        $extra = match ($operation) {
            'resolve' => ['observation_id', 'movement'],
            'correct', 'request_correction' => ['movement_id'],
            'approve_correction', 'cancel_exit' => ['action_id'],
            'permit_exit' => ['checks', 'return_allowed'],
            default => [],
        };
        abort_if(array_diff(array_keys($body), ['operation', 'idempotency_key', 'expected_revision', 'reason_code', 'note', ...$extra]) !== [],
            422, 'Unexpected gate action fields.');
        $validated = validator($body, [
            'operation' => ['required', Rule::in(['resolve', 'permit_exit', 'cancel_exit', 'request_correction', 'correct', 'approve_correction'])],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,80}$/D'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', 'string', 'regex:/^[a-z0-9_]{1,64}$/D'], 'note' => ['sometimes', 'string', 'max:500'],
            'movement_id' => ['required_if:operation,correct,request_correction', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'action_id' => ['required_if:operation,approve_correction,cancel_exit', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'observation_id' => ['required_if:operation,resolve', 'integer', 'min:1'],
            'movement' => ['required_if:operation,resolve', Rule::in(['check_in', 'check_out'])],
            'return_allowed' => ['required_if:operation,permit_exit', 'boolean'],
            'checks' => ['required_if:operation,permit_exit', 'array', 'max:50'],
            'checks.*' => ['string', 'distinct', 'regex:/^[a-z0-9_]{1,64}$/D'],
        ])->validate();
        $validated['expected_revision'] = (int) $validated['expected_revision'];
        if (isset($validated['observation_id'])) {
            $validated['observation_id'] = (int) $validated['observation_id'];
        }
        if (isset($validated['return_allowed'])) {
            $validated['return_allowed'] = (bool) $validated['return_allowed'];
        }
        if (isset($validated['checks'])) {
            sort($validated['checks']);
        }
        $validated['note'] ??= '';
        ksort($validated);

        return response()->json(['data' => app(OperatorActions::class)->submit($actor, $day, $validated)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function actor(Request $request): mixed
    {
        return $request->routeIs('admin.gate.*') ? $request->user('admin') : $request->user();
    }

    private function reply(Request $request, array $data)
    {
        $setup = app(\App\Services\Biometrics\Gate\SetupAccess::class);
        $actor = $this->actor($request);
        $data['setupUrl'] = $setup->canEnter($actor) ? '/admin/gate/setup?school_id='.$data['selectedSchool']->school_id : null;
        if ($data['setupUrl'] && ! $setup->schools($actor)->where('a.school_id', $data['selectedSchool']->school_id)->exists()) {
            $data['setupUrl'] = null;
        }
        if ($request->routeIs('admin.gate.*') && ! $request->expectsJson()) {
            Inertia::setRootView('gate-operator');
            return Inertia::render('Admin/Gate/Index', $data + ['actorName' => $this->actor($request)->name,
                'actorId' => $this->actor($request)->id, 'csrfToken' => csrf_token()])
                ->toResponse($request)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
