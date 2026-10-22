<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminApiAccess
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof AdminUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Admin authentication is required.',
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'This admin account is inactive.',
            ], 403);
        }

        if ($user->test_recovery_only) {
            return response()->json(['message' => 'This account is restricted to test recovery.'], 403);
        }
        if ($user->gate_only && ! $request->routeIs('api.admin.v2.gate.*', 'api.admin.v2.auth.me', 'api.admin.v2.auth.logout')) {
            return response()->json(['message' => 'This account is restricted to the gate workspace.'], 403);
        }

        return $next($request);
    }
}
