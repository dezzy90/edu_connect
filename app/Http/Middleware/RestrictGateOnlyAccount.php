<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class RestrictGateOnlyAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();
        abort_if($admin?->gate_source_only, 403, 'Use your existing EduAdmin login.');
        // Durable even when gate features or the assignment have been disabled.
        if ($admin?->gate_only) {
            if ($request->isMethod('post') && $request->is('admin/logout')) {
                return $next($request);
            }
            abort_unless($admin->is_active && ! $admin->test_recovery_only, 403);
            if (($request->isMethod('get') && $request->is('admin', 'admin/login', 'admin/dashboard'))
                || ($request->isMethod('post') && $request->is('admin/login'))) {
                $destination = app(\App\Services\Biometrics\Gate\GateLanding::class)->path($admin);
                return $request->header('X-Inertia')
                    ? Inertia::location($destination)
                    : redirect($destination, $request->isMethod('post') ? 303 : 302);
            }
            abort_unless($request->routeIs('admin.gate.*'), 403, 'This account is restricted to the gate workspace.');
        }

        return $next($request);
    }
}
