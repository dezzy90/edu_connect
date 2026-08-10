<?php

namespace App\Http\Middleware;

use App\Models\V2\IntegrationConnection;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class EnsureEduAdminWebhookSignature
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $connection = $this->connection($request);

        if (! $connection instanceof IntegrationConnection) {
            return response()->json([
                'status' => 'error',
                'message' => 'Edu-admin webhook connection was not found.',
            ], 404);
        }

        $timestamp = (string) $request->header('X-Edu-Admin-Timestamp', '');
        $signature = (string) $request->header('X-Edu-Admin-Signature', '');

        if ($timestamp === '' || $signature === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing Edu-admin webhook signature.',
            ], 401);
        }

        if (! ctype_digit($timestamp)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Edu-admin webhook signature timestamp.',
            ], 401);
        }

        $tolerance = max(1, (int) config('integrations.webhooks.edu_admin.signature_tolerance_seconds', 300));

        if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            return response()->json([
                'status' => 'error',
                'message' => 'Expired Edu-admin webhook signature.',
            ], 401);
        }

        $secret = $this->webhookSecret($connection);

        if ($secret === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Edu-admin webhook signature verification is not configured.',
            ], 503);
        }

        $expected = 'sha256=' . hash_hmac(
            'sha256',
            $timestamp . '.' . $request->getContent(),
            $secret
        );

        if (! hash_equals($expected, $this->normalizeSignature($signature))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Edu-admin webhook signature.',
            ], 401);
        }

        $request->attributes->set('edu_admin_integration_connection', $connection);

        return $next($request);
    }

    private function connection(Request $request): ?IntegrationConnection
    {
        $complexId = trim((string) ($request->input('complex_id') ?: $request->header('X-Edu-Admin-Complex-Id', '')));

        $query = IntegrationConnection::query()
            ->where('provider', 'edu_admin')
            ->where('mode', 'connected')
            ->where('status', 'active');

        if ($complexId !== '') {
            $query->where('remote_tenant_id', $complexId);
        }

        $connections = $query->limit(2)->get();

        return $connections->count() === 1 ? $connections->first() : null;
    }

    private function webhookSecret(IntegrationConnection $connection): string
    {
        if (blank($connection->webhook_secret)) {
            return '';
        }

        try {
            return Crypt::decryptString($connection->webhook_secret);
        } catch (DecryptException) {
            return '';
        }
    }

    private function normalizeSignature(string $signature): string
    {
        return str_starts_with($signature, 'sha256=')
            ? $signature
            : 'sha256=' . $signature;
    }
}
