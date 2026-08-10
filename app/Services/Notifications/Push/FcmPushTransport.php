<?php

namespace App\Services\Notifications\Push;

use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationDelivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FcmPushTransport implements PushTransport
{
    private ?array $serviceAccount = null;

    public function send(NotificationDelivery $delivery): PushDeliveryResult
    {
        $delivery->loadMissing(['notification', 'pushToken']);

        if (!$delivery->notification || !$delivery->pushToken) {
            return PushDeliveryResult::failed('Notification or push token is missing.', invalidToken: true);
        }

        $response = Http::acceptJson()
            ->withToken($this->accessToken())
            ->timeout($this->timeout())
            ->post($this->endpoint(), $this->payload($delivery));

        $providerResponse = $this->providerResponse($response);

        if ($response->failed()) {
            return PushDeliveryResult::failed(
                $this->errorMessage($response),
                $providerResponse,
                $this->isInvalidTokenResponse($response),
            );
        }

        return PushDeliveryResult::sent(
            $response->json('name'),
            $providerResponse,
        );
    }

    private function endpoint(): string
    {
        $projectId = config('educonnect.notifications.fcm.project_id')
            ?: ($this->serviceAccount()['project_id'] ?? null);

        if (blank($projectId)) {
            throw new RuntimeException('Firebase Cloud Messaging project ID is missing.');
        }

        return 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
    }

    private function payload(NotificationDelivery $delivery): array
    {
        /** @var MobileNotification $notification */
        $notification = $delivery->notification;

        return [
            'message' => [
                'token' => $delivery->pushToken->token,
                'notification' => [
                    'title' => $notification->title,
                    'body' => $notification->body,
                ],
                'data' => $this->stringData($notification),
                'android' => [
                    'priority' => $this->isHighPriority($notification) ? 'HIGH' : 'NORMAL',
                    'notification' => [
                        'channel_id' => 'educonnect_' . $notification->type,
                        'sound' => 'default',
                        'visibility' => 'PUBLIC',
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => $this->isHighPriority($notification) ? '10' : '5',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function accessToken(): string
    {
        $configured = config('educonnect.notifications.fcm.access_token');

        if (filled($configured)) {
            return (string) $configured;
        }

        $serviceAccount = $this->serviceAccount();

        if (empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
            throw new RuntimeException('Firebase Cloud Messaging access token or service account credentials are missing.');
        }

        $tokenUri = $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = now()->timestamp;
        $assertion = $this->jwt(
            [
                'alg' => 'RS256',
                'typ' => 'JWT',
            ],
            [
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ],
            $serviceAccount['private_key'],
            OPENSSL_ALGO_SHA256,
        );

        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException(sprintf(
                'Firebase Cloud Messaging OAuth token request failed with status %d: %s',
                $response->status(),
                Str::limit($response->body(), 500),
            ));
        }

        return (string) $response->json('access_token');
    }

    private function serviceAccount(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $json = config('educonnect.notifications.fcm.credentials_json');
        $path = config('educonnect.notifications.fcm.credentials_path');

        if (filled($json)) {
            $decoded = json_decode((string) $json, true);

            if (!is_array($decoded)) {
                $decoded = json_decode((string) base64_decode((string) $json, true), true);
            }

            return $this->serviceAccount = is_array($decoded) ? $decoded : [];
        }

        if (filled($path) && is_readable((string) $path)) {
            $decoded = json_decode((string) file_get_contents((string) $path), true);

            return $this->serviceAccount = is_array($decoded) ? $decoded : [];
        }

        return $this->serviceAccount = [];
    }

    private function jwt(array $header, array $claims, string $privateKey, int $algorithm): string
    {
        $encodedHeader = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = "{$encodedHeader}.{$encodedClaims}";

        if (!openssl_sign($signingInput, $signature, $privateKey, $algorithm)) {
            throw new RuntimeException('Firebase Cloud Messaging service account JWT could not be signed.');
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    private function stringData(MobileNotification $notification): array
    {
        $data = [
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'tenant_id' => $notification->tenant_id,
            'school_id' => $notification->school_id,
            'priority' => $notification->priority,
            'channel' => $notification->channel,
        ];

        foreach (($notification->data ?? []) as $key => $value) {
            $data[(string) $key] = $value;
        }

        return collect($data)
            ->reject(fn ($value) => $value === null)
            ->map(fn ($value) => is_scalar($value)
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->all();
    }

    private function providerResponse(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload)
            ? $payload
            : ['status' => $response->status(), 'body' => Str::limit($response->body(), 500)];
    }

    private function errorMessage(Response $response): string
    {
        return (string) ($response->json('error.message')
            ?: 'Firebase Cloud Messaging request failed with status ' . $response->status() . '.');
    }

    private function isInvalidTokenResponse(Response $response): bool
    {
        $status = (string) $response->json('error.status', '');
        $message = strtolower((string) $response->json('error.message', ''));
        $details = collect((array) $response->json('error.details', []));

        return $status === 'NOT_FOUND'
            || str_contains($message, 'registration token')
            || $details->contains(fn ($detail) => is_array($detail)
                && ($detail['errorCode'] ?? null) === 'UNREGISTERED');
    }

    private function isHighPriority(MobileNotification $notification): bool
    {
        return in_array($notification->priority, ['high', 'urgent', 'critical'], true)
            || in_array($notification->type, ['attendance', 'messages'], true);
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
