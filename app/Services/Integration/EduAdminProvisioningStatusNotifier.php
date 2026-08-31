<?php

namespace App\Services\Integration;

use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationSyncRun;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EduAdminProvisioningStatusNotifier
{
    public function notify(IntegrationConnection $connection, string $status, ?IntegrationSyncRun $run = null, ?string $error = null): void
    {
        $payload = [
            'status' => $status,
            'connection_id' => $connection->id,
            'tenant_id' => $connection->tenant_id,
            'sync_run_id' => $run?->id,
            'records_read' => $run?->records_read,
            'records_created' => $run?->records_created,
            'records_updated' => $run?->records_updated,
            'records_failed' => $run?->records_failed,
            'error' => $error,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;

        $response = Http::acceptJson()
            ->withToken($this->decrypt($connection->encrypted_access_token, 'access token'))
            ->withHeaders([
                'X-Edu-Connect-Timestamp' => $timestamp,
                'X-Edu-Connect-Signature' => 'sha256='.hash_hmac(
                    'sha256',
                    $timestamp.'.'.$body,
                    $this->decrypt($connection->webhook_secret, 'webhook secret'),
                ),
            ])
            ->withBody($body, 'application/json')
            ->timeout((int) config('integrations.providers.edu_admin.timeout_seconds', 15))
            ->retry((int) config('integrations.providers.edu_admin.retry_attempts', 3), 250, null, false)
            ->post(rtrim((string) $connection->base_url, '/').'/api/v1/integrations/edu-connect/provisioning-status');

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'EduAdmin provisioning callback failed with status %d: %s',
                $response->status(),
                str($response->body())->limit(500)->toString(),
            ));
        }
    }

    private function decrypt(?string $encrypted, string $label): string
    {
        if (blank($encrypted)) {
            throw new RuntimeException("EduAdmin connector {$label} is missing.");
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException $exception) {
            throw new RuntimeException("EduAdmin connector {$label} could not be decrypted.", previous: $exception);
        }
    }
}
