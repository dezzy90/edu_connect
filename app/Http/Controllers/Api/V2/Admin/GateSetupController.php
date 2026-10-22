<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Biometrics\Gate\OperatorAccess;
use App\Services\Biometrics\Gate\SetupAccess;
use App\Services\Biometrics\Gate\SetupDrafts;
use App\Services\Biometrics\Gate\SetupPublications;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GateSetupController extends Controller
{
    public function index(Request $request)
    {
        $access = app(SetupAccess::class);
        $actor = $access->actor($request->routeIs('admin.gate.*') ? $request->user('admin') : $request->user());
        $filters = $request->validate(['school_id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:80'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $schools = $access->schools($actor)->select('a.school_id', 's.name', 's.timezone')->orderBy('a.school_id')->limit(101)->get();
        abort_unless($schools->isNotEmpty() && $schools->count() <= 100, 403, 'An explicit active school-setup assignment is required.');
        $id = (int) ($filters['school_id'] ?? $schools->first()->school_id);
        $data = app(SetupDrafts::class)->read($actor, $id, $filters['q'] ?? '') + ['schools' => $schools, 'search' => $filters['q'] ?? '', 'gateUrl' => null,
            'deviceRegistryUrl' => app(\App\Services\Biometrics\Devices\RegistryAccess::class)->canEnter($actor) ? '/admin/gate/devices' : null];
        try {
            $operator = app(OperatorAccess::class);
            $operator->actor($actor);
            if ($operator->assignments($actor)->where('a.school_id', $id)->exists()) {
                $data['gateUrl'] = '/admin/gate?school_id='.$id;
            }
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 409], true)) {
                throw $error;
            }
        }
        if ($request->routeIs('admin.gate.*') && ! $request->expectsJson()) {
            Inertia::setRootView('gate-operator');

            return Inertia::render('Admin/Gate/Setup', $data + ['actorName' => $actor->name, 'csrfToken' => csrf_token()])
                ->toResponse($request)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $school)
    {
        $actor = app(SetupAccess::class)->actor($request->routeIs('admin.gate.*') ? $request->user('admin') : $request->user());
        abort_unless($request->isJson(), 415, 'JSON draft body required.');
        $body = $request->json()->all();
        abort_if(array_diff(array_keys($body), ['expected_revision', 'idempotency_key', 'configuration']) !== [], 422, 'Only draft settings may be saved; activation and permissions are not supported.');
        $input = validator($body, ['expected_revision' => ['required', 'integer', 'min:0', static function ($attribute, $value, $fail): void {
            if (! is_int($value)) {
                $fail('Use a whole JSON revision number.');
            }
        }], 'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,80}$/D'], 'configuration' => ['present', 'array']])->validate();

        return response()->json(['data' => app(SetupDrafts::class)->save($actor, $school, $input['expected_revision'],
            $input['idempotency_key'], $input['configuration'])])->header('Cache-Control', 'private, no-store');
    }

    public function activate(Request $request, int $school)
    {
        $actor = app(SetupAccess::class)->actor($request->routeIs('admin.gate.*') ? $request->user('admin') : $request->user());
        $body = $this->publicationBody($request, ['expected_revision', 'starts_on', 'idempotency_key', 'confirmed']);
        $input = validator($body, ['expected_revision' => ['required', 'integer', 'min:1', function ($attribute, $value, $fail): void {
            if (! is_int($value)) {
                $fail('Use a whole JSON revision number.');
            }
        }], 'starts_on' => ['required', 'date_format:Y-m-d'], 'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,80}$/D'],
            'confirmed' => ['required', function ($attribute, $value, $fail): void {
                if ($value !== true) {
                    $fail('Explicitly confirm the saved school rules.');
                }
            }]])->validate();

        return response()->json(['data' => app(SetupPublications::class)->publish($actor, $school, $input['expected_revision'],
            $input['starts_on'], $input['idempotency_key'])])->header('Cache-Control', 'private, no-store');
    }

    public function pause(Request $request, int $school, int $publication)
    {
        $actor = app(SetupAccess::class)->actor($request->routeIs('admin.gate.*') ? $request->user('admin') : $request->user());
        $body = $this->publicationBody($request, ['reason_code', 'idempotency_key']);
        $input = validator($body, ['reason_code' => ['required', 'in:planned_pause,configuration_change,incident'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,80}$/D']])->validate();

        return response()->json(['data' => app(SetupPublications::class)->pause($actor, $school, $publication,
            $input['reason_code'], $input['idempotency_key'])])->header('Cache-Control', 'private, no-store');
    }

    private function publicationBody(Request $request, array $allowed): array
    {
        abort_unless($request->isJson(), 415, 'JSON publication body required.');
        $body = $request->json()->all();
        abort_if(array_diff(array_keys($body), $allowed) !== [], 422, 'Only explicit publication action fields are allowed.');

        return $body;
    }
}
