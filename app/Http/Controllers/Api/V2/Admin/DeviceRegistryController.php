<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Biometrics\Devices\DeviceRegistry;
use App\Services\Biometrics\Devices\RegistryAccess;
use App\Services\Biometrics\Devices\RegistryRules;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class DeviceRegistryController extends Controller
{
    private function actor(Request $request)
    {
        return app(RegistryAccess::class)->actor($request->routeIs('admin.gate.*') ? $request->user('admin') : $request->user());
    }

    public function index(Request $request, ?int $device = null)
    {
        $actor = $this->actor($request);
        $registry = app(DeviceRegistry::class);
        $schools = $registry->schools($actor);
        abort_unless(count($schools) > 0 && count($schools) <= 200, 403, 'An explicit device-administrator grant for an active complex is required.');
        $filters = $request->validate(['tenant_id' => ['sometimes', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:80'],
            'state' => ['sometimes', 'in:all,active,shared,paused,retired'], 'page' => ['sometimes', 'integer', 'min:1'], 'audit_page' => ['sometimes', 'integer', 'min:1']]);
        if (isset($filters['tenant_id'])) {
            abort_unless(in_array((int) $filters['tenant_id'], array_map(fn ($s) => (int) $s->tenant_id, $schools), true), 404);
        }
        $data = $device === null ? $registry->index($actor, $filters) : ['devices' => null, 'stats' => null, 'filters' => [], 'detail' => $registry->detail($actor, $device)];
        $data += ['schools' => $schools, 'readOnly' => config('device_registry.read_only') !== false,
            'registrationEnabled' => config('device_registry.read_only') === false && config('device_registry.registration_enabled') === true,
            'sharedGateEnabled' => config('device_registry.shared_gate_enabled') === true, 'serverConnection' => RegistryRules::connection(),
            'setupUrl' => app(\App\Services\Biometrics\Gate\SetupAccess::class)->canEnter($actor) ? '/admin/gate/setup' : null];
        if ($request->routeIs('admin.gate.*') && ! $request->expectsJson()) {
            Inertia::setRootView('gate-operator');

            return Inertia::render('Admin/Gate/Devices', $data + ['actorName' => $actor->name, 'csrfToken' => csrf_token()])
                ->toResponse($request)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, ?int $device = null)
    {
        $actor = $this->actor($request);
        abort_unless($request->isJson(), 415, 'JSON device operation required.');
        $input = RegistryRules::input($request->json()->all(), $device === null);

        return response()->json(['data' => app(DeviceRegistry::class)->save($actor, $device, $input)])
            ->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache')->header('Referrer-Policy', 'same-origin');
    }
}
