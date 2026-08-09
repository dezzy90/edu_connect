<?php

namespace App\Services\Notifications\Push;

use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationDelivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ApnsPushTransport implements PushTransport
{
    public function send(NotificationDelivery $delivery): PushDeliveryResult
    {
        $delivery->loadMissing(['notification', 'pushToken']);

        if (!$delivery->notification || !$delivery->pushToken) {
            return PushDeliveryResult::failed('Notification or push token is missing.', invalidToken: true);
        }

        $response = Http::acceptJson()
            ->withToken($this->bearerToken())
            ->withHeaders($this->headers($delivery->notification))
            ->timeout($this->timeout())
            ->post($this->endpoint($delivery->pushToken->token), $this->payload($delivery->notification));

        $providerResponse = $this->providerResponse($response);

        if ($response->failed()) {
            return PushDeliveryResult::failed(
                $this->errorMessage($response),
                $providerResponse,
                $this->isInvalidTokenResponse($response),
            );
        }

        return PushDeliveryResult::sent(
            $response->header('apns-id'),
            $providerResponse,
        );
    }

    private function endpoint(string $token): string
    {
        $host = config('educonnect.notifications.apns.environment') === 'production'
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        return $host . '/3/device/' . $token;
    }

    private function headers(MobileNotification $notification): array
    {
        $bundleId = config('educonnect.notifications.apns.bundle_id');

        if (blank($bundleId)) {
            throw new RuntimeException('APNs bundle ID is missing.');
        }

        return array_filter([
            'apns-topic' => $bundleId,
            'apns-push-type' => 'alert',
            'apns-priority' => $this->isHighPriority($notification) ? '10' : '5',
            'apns-expiration' => $notification->expires_at?->timestamp,
        ], fn ($value) => $value !== null);
    }

    private function payload(MobileNotification $notification): array
    {
        return [
            'aps' => [
                'alert' => [
                    'title' => $notification->title,
                    'body' => $notification->body,
                ],
                'sound' => 'default',
            ],
            'educonnect' => $this->data($notification),
        ];
    }

    private function bearerToken(): string
    {
        $configured = config('educonnect.notifications.apns.bearer_token');

        if (filled($configured)) {
            return (string) $configured;
        }

        $teamId = config('educonnect.notifications.apns.team_id');
        $keyId = config('educonnect.notifications.apns.key_id');
        $privateKey = $this->privateKey();

        if (blank($teamId) || blank($keyId) || blank($privateKey)) {
            throw new RuntimeException('APNs team ID, key ID, and private key are required.');
        }

        $encodedHeader = $this->base64Url(json_encode([
            'alg' => 'ES256',
            'kid' => $keyId,
        ], JSON_THROW_ON_ERROR));

        $encodedClaims = $this->base64Url(json_encode([
            'iss' => $teamId,
            'iat' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        $signingInput = "{$encodedHeader}.{$encodedClaims}";

        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('APNs provider token could not be signed.');
        }

        return $signingInput . '.' . $this->base64Url($this->derToJose($signature));
    }

    private function privateKey(): ?string
    {
        $privateKey = config('educonnect.notifications.apns.private_key');

        if (filled($privateKey)) {
            return str_replace('\\n', "\n", (string) $privateKey);
        }

        $path = config('educonnect.notifications.apns.private_key_path');

        if (filled($path) && is_readable((string) $path)) {
            return (string) file_get_contents((string) $path);
        }

        return null;
    }

    private function derToJose(string $der, int $partLength = 32): string
    {
        $offset = 0;

        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('APNs ECDSA signature is not a DER sequence.');
        }

        $this->readDerLength($der, $offset);

        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('APNs ECDSA signature is missing the R integer.');
        }

        $rLength = $this->readDerLength($der, $offset);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;

        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('APNs ECDSA signature is missing the S integer.');
        }

        $sLength = $this->readDerLength($der, $offset);
        $s = substr($der, $offset, $sLength);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad(substr($r, -$partLength), $partLength, "\x00", STR_PAD_LEFT)
            . str_pad(substr($s, -$partLength), $partLength, "\x00", STR_PAD_LEFT);
    }

    private function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);

        if (($length & 0x80) === 0) {
            return $length;
        }

        $byteCount = $length & 0x7f;
        $length = 0;

        for ($i = 0; $i < $byteCount; $i++) {
            $length = ($length << 8) + ord($der[$offset++]);
        }

        return $length;
    }

    private function data(MobileNotification $notification): array
    {
        return array_filter([
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'tenant_id' => $notification->tenant_id,
            'school_id' => $notification->school_id,
            'priority' => $notification->priority,
            'channel' => $notification->channel,
            'data' => $notification->data ?? [],
        ], fn ($value) => $value !== null);
    }

    private function providerResponse(Response $response): array
    {
        $payload = $response->json();

        if (is_array($payload)) {
            return array_merge(['status' => $response->status()], $payload);
        }

        return ['status' => $response->status(), 'body' => Str::limit($response->body(), 500)];
    }

    private function errorMessage(Response $response): string
    {
        return (string) ($response->json('reason')
            ?: 'APNs request failed with status ' . $response->status() . '.');
    }

    private function isInvalidTokenResponse(Response $response): bool
    {
        $reason = (string) $response->json('reason', '');

        return in_array($response->status(), [400, 410], true)
            && in_array($reason, ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'], true);
    }

    private function isHighPriority(MobileNotification $notification): bool
    {
        return in_array($notification->priority, ['high', 'urgent', 'critical'], true);
    }

    private function timeout(): int
    {
        return max(1, (int) config('educonnect.notifications.push_timeout_seconds', 10));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
