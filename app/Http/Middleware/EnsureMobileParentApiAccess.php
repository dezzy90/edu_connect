<?php

namespace App\Http\Middleware;

use App\Models\V2\ParentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileParentApiAccess
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof ParentAccount) {
            return response()->json([
                'status' => 'error',
                'message' => 'Parent authentication is required.',
            ], 403);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'This parent account is not active.',
            ], 403);
        }

        return $next($request);
    }
}
