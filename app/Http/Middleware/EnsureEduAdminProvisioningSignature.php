<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureEduAdminProvisioningSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('integrations.provisioning.edu_admin.secret');
        $timestamp = (string) $request->header('X-Edu-Admin-Timestamp', '');
        $signature = (string) $request->header('X-Edu-Admin-Signature', '');
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if (strlen($secret) < 32) {
            return response()->json(['status' => 'error', 'message' => 'Edu-admin provisioning is not configured.'], 503);
        }

        if (! ctype_digit($timestamp) || $signature === '' || $idempotencyKey === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing Edu-admin provisioning signature.'], 401);
        }

        $tolerance = max(1, (int) config('integrations.provisioning.edu_admin.signature_tolerance_seconds', 300));

        if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            return response()->json(['status' => 'error', 'message' => 'Expired Edu-admin provisioning signature.'], 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        $normalized = str_starts_with($signature, 'sha256=') ? $signature : 'sha256='.$signature;

        if (! hash_equals($expected, $normalized)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid Edu-admin provisioning signature.'], 401);
        }

        $replayKey = 'educonnect:provisioning:'.hash('sha256', $idempotencyKey.'|'.$signature);

        if (! Cache::add($replayKey, true, now()->addSeconds($tolerance))) {
            $request->attributes->set('edu_admin_provisioning_replay', true);
        }

        return $next($request);
    }
}
