<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Biometrics\Hikvision\PushReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class HikvisionPushController extends Controller
{
    public function __invoke(Request $request, string $serial, string $operation, PushReceiver $receiver): JsonResponse
    {
        if (! config('hikvision_push.enabled')) {
            return $this->error(503, 'Receiver is disabled.');
        }
        $loopback = app()->environment(['local', 'testing']) && config('hikvision_push.allow_loopback_http')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true);
        if (! $request->isSecure() && ! $loopback) {
            return $this->error(426, 'HTTPS is required.');
        }
        if ($request->query->count() !== 0) {
            return $this->error(400, 'Unsupported protocol query parameters.');
        }
        if (strlen($request->getContent()) > (int) config('hikvision_push.max_body_bytes')) {
            return $this->error(413, 'Request too large.');
        }
        if (in_array($operation, ['AuthInfo', 'Login'], true)) {
            $key = 'hikvision-push:auth:'.hash('sha256', (string) $request->ip().'|'.$serial);
            if (RateLimiter::tooManyAttempts($key, 30)) {
                return $this->error(429, 'Too many authentication requests.');
            }
            RateLimiter::hit($key, 60);
        }
        try {
            $content = $request->getContent();
            if ($content !== '' && ! $request->isJson()) {
                return $this->error(415, 'JSON envelope is required.');
            }
            $body = $content === '' ? [] : json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($body) || ($body !== [] && array_is_list($body))) {
                throw new InvalidArgumentException('JSON object required.');
            }
            $result = $receiver->handle($serial, $operation, $body, $request->header('My-Custom-Auth'));
            $response = response()->json($result['body'], $result['status'])->header('Cache-Control', 'no-store');
            if (isset($result['challenge'])) {
                $response->header('My-Custom-Challenge', $result['challenge']);
            }

            return $response;
        } catch (InvalidArgumentException|JsonException $error) {
            return $this->error(400, 'Invalid protocol payload.');
        } catch (Throwable $error) {
            // Never echo or log request bodies, credentials or database exception details.
            // Failure rolls back the ACK/challenge transaction; terminal must login and retry.
            return $this->error(503, 'Receiver temporarily unavailable.');
        }
    }

    private function error(int $status, string $message): JsonResponse
    {
        return response()->json(['status' => $status, 'code' => '0x00100001', 'errorMsg' => $message], $status)
            ->header('Cache-Control', 'no-store');
    }
}
