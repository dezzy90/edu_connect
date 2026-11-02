<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ScmcOtpSender
{
    /**
     * Sends an existing challenge; generating, expiring and verifying codes belongs to the auth flow.
     * Keep deliveryId unchanged when retrying the same challenge and payload.
     */
    public function send(
        #[\SensitiveParameter] string $destinationE164,
        #[\SensitiveParameter] string $code,
        string $validityText,
        string $deliveryId,
    ): void {
        if (! config('services.scmc.enabled', false)) {
            throw new RuntimeException('SCMC OTP delivery is disabled.');
        }

        $key = trim((string) config('services.scmc.api_key'));
        $template = trim((string) config('services.scmc.otp_template_id'));
        $appName = trim((string) config('services.scmc.app_name'));

        if ($key === '' || $key === 'sk_VOTRE_CLE_API' || preg_match('/[\r\n]/', $key)
            || ! Str::isUuid($template) || $appName === '' || mb_strlen($appName) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $appName)) {
            throw new RuntimeException('SCMC OTP configuration is incomplete or invalid.');
        }

        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $destinationE164)
            || ! preg_match('/^[0-9]{6}$/D', $code)
            || trim($validityText) === '' || mb_strlen($validityText) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $validityText)
            || ! preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $deliveryId)) {
            throw new InvalidArgumentException('Invalid OTP delivery parameters.');
        }

        // Scope retries to the app and environment without exposing the phone or code in the key.
        $idempotencyKey = 'otp-'.hash('sha256', json_encode([
            config('app.env'), $appName, $deliveryId,
        ], JSON_THROW_ON_ERROR));

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders(['X-API-Key' => $key, 'Idempotency-Key' => $idempotencyKey])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)
                ->timeout(max(1, min(20, (int) config('services.scmc.timeout_seconds', 15))))
                ->post('https://api.scmchost.com/messages/send', [
                    'template_id' => $template,
                    'recipients' => [$destinationE164],
                    'variables' => [
                        'NOM_APP' => $appName,
                        'CODE' => $code,
                        'TEMPS' => $validityText,
                    ],
                ]);
        } catch (ConnectionException|RequestException) {
            // Do not attach HTTP exceptions: they can contain request credentials or an OTP.
            throw new RuntimeException('SCMC OTP delivery could not be confirmed.');
        }

        $payload = $response->json();
        $sent = is_array($payload) && is_array($payload['data'] ?? null)
            ? ($payload['data']['sent'] ?? null)
            : null;

        if (! $response->successful() || ! is_array($payload)
            || ($payload['success'] ?? null) !== true || ! in_array($sent, [1, '1'], true)) {
            // Never expose or log an untrusted provider error body.
            throw new RuntimeException('SCMC did not confirm acceptance of the OTP message.');
        }
    }
}
