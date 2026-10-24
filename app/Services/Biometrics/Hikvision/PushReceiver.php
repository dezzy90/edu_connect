<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use App\Services\Biometrics\Devices\DeviceScope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PushReceiver
{
    public function __construct(private readonly PushInbox $inbox, private readonly PushCommands $commands) {}

    /** Return only after the enclosing transaction commits; the controller then emits the ACK. */
    public function handle(string $serial, string $operation, array $body, ?string $customAuth): array
    {
        return DB::transaction(function () use ($serial, $operation, $body, $customAuth): array {
            $device = $this->registeredIdentity($serial);
            if (! $device || ! $this->scopeValid($device)) {
                return self::failure(401);
            }
            $time = now()->getTimestamp();
            if ($device->locked_until > $time) {
                return self::failure(401, ['lockStatus' => 'lock', 'unlockTime' => $device->locked_until - $time, 'retryLoginTime' => 0]);
            }
            $session = DB::table('ec_hikvision_push_sessions')->where('push_device_id', $device->id)->first();
            $ttl = 3 * (int) config('hikvision_push.command_interval');
            if ($operation === 'AuthInfo') {
                $challenge = PushAuthentication::challenge();
                $salt = PushAuthentication::challenge();
                $iterations = (int) config('hikvision_push.login_iterations');
                DB::table('ec_hikvision_push_sessions')->updateOrInsert(['push_device_id' => $device->id], [
                    'phase' => 'authenticating', 'challenge' => $challenge, 'salt' => $salt, 'iterations' => $iterations,
                    'expires_at' => $time + $ttl, 'last_seen_at' => null,
                ]);

                return ['status' => 200, 'body' => ['data' => ['challenge' => $challenge, 'salt' => $salt, 'iterations' => $iterations,
                    // HTTPS is mandatory; this slice does not negotiate proprietary AES-over-HTTP.
                    'isDataEncrypt' => false]]];
            }
            if (! $session || $session->expires_at <= $time) {
                return self::failure(401);
            }
            $password = Crypt::decryptString($device->credential_encrypted);
            if ($operation === 'Login') {
                $data = $body['data'] ?? [];
                if ($session->phase !== 'authenticating') {
                    return self::failure(401);
                }
                // Consume a login challenge even on failure; refresh cannot reset failure count.
                DB::table('ec_hikvision_push_sessions')->where('push_device_id', $device->id)->delete();
                $expected = PushAuthentication::login($device->username, $password, $session->salt, $session->challenge, $session->iterations, $device->auth_profile);
                if (($data['username'] ?? null) !== $device->username || ! is_string($data['loginPassword'] ?? null)
                    || ! hash_equals($expected, $data['loginPassword'])) {
                    $failures = $device->login_failures + 1;
                    $locked = $failures >= 5;
                    DB::table('ec_hikvision_push_devices')->where('id', $device->id)->update([
                        'login_failures' => $locked ? 0 : $failures,
                        'locked_until' => $locked ? $time + (int) config('hikvision_push.lock_seconds') : 0,
                    ]);

                    return self::failure(401, ['lockStatus' => $locked ? 'lock' : 'unlock',
                        'unlockTime' => $locked ? (int) config('hikvision_push.lock_seconds') : 0, 'retryLoginTime' => $locked ? 0 : 5 - $failures]);
                }
                DB::table('ec_hikvision_push_devices')->where('id', $device->id)->update(['login_failures' => 0, 'locked_until' => 0]);
                $challenge = PushAuthentication::challenge();
                DB::table('ec_hikvision_push_sessions')->insert([
                    'push_device_id' => $device->id, 'phase' => 'online', 'challenge' => $challenge,
                    'salt' => $session->salt, 'iterations' => $session->iterations, 'expires_at' => $time + $ttl, 'last_seen_at' => now(),
                ]);

                return ['status' => 200, 'body' => self::success(['data' => [
                    'commandInterval' => (int) config('hikvision_push.command_interval'), 'errorDelay' => (int) config('hikvision_push.error_delay'),
                ]]), 'challenge' => $challenge];
            }
            if ($session->phase !== 'online' || ! is_string($customAuth)
                || ! hash_equals(PushAuthentication::request($device->username, $password, $session->salt, $session->challenge), $customAuth)) {
                return self::failure(401);
            }
            $result = match ($operation) {
                'Event' => array_map(fn (array $entry): array => $this->inbox->capture($device, $entry), self::batch($body, 'eventNum', 'eventList')),
                'CommandRequest' => self::success($this->commands->poll($device)),
                'CommandResult' => self::success($this->commands->results($device, $body)),
                'Logout' => self::success(),
                default => throw new InvalidArgumentException('Unsupported operation.'),
            };
            $challenge = PushAuthentication::challenge();
            if ($operation === 'Logout') {
                DB::table('ec_hikvision_push_sessions')->where('push_device_id', $device->id)->delete();
            } else {
                DB::table('ec_hikvision_push_sessions')->where('push_device_id', $device->id)->update([
                    'challenge' => $challenge, 'expires_at' => $time + $ttl, 'last_seen_at' => now(),
                ]);
            }

            return ['status' => 200, 'body' => $result, 'challenge' => $challenge];
        });
    }

    public static function batch(array $body, string $countKey, string $listKey): array
    {
        $list = $body[$listKey] ?? null;
        $count = $body[$countKey] ?? null;
        if (! is_int($count) || $count < 0 || $count > 20 || ! is_array($list) || ! array_is_list($list) || count($list) !== $count) {
            throw new InvalidArgumentException('Invalid bounded batch.');
        }
        foreach ($list as $entry) {
            if (! is_array($entry) || ! is_string($entry['UUID'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $entry['UUID'])
                || ! is_string($entry['dataFormat'] ?? null) || ! is_string($entry['data'] ?? null)
                || strlen($entry['data']) > (int) config('hikvision_push.max_event_bytes')) {
                throw new InvalidArgumentException('Invalid bounded batch entry.');
            }
        }

        return $list;
    }

    private function registeredIdentity(string $serial): ?object
    {
        // The transport SN may differ from the label/web serial. Only a separately
        // operator-bound exact pair is accepted: never suffix matching or auto-registration.
        // Include disabled candidates too, so a collision cannot shadow another identity.
        $matches = DB::table('ec_hikvision_push_devices as p')
            ->join('ec_biometric_devices as d', 'd.id', '=', 'p.device_id')
            ->where(fn ($q) => $q->where('p.serial', $serial)
                ->orWhere('d.settings->hikvision_push_identity->transport_serial', $serial))
            ->limit(2)->lockForUpdate()->get(['p.*', 'd.settings as identity_settings']);
        if ($matches->count() !== 1) {
            return null;
        }
        $device = $matches->first();
        if (! $device->enabled) {
            return null;
        }
        $binding = json_decode($device->identity_settings ?? '{}', true)['hikvision_push_identity'] ?? null;
        if ($device->serial !== $serial && (! is_array($binding)
            || ($binding['canonical_serial'] ?? null) !== $device->serial
            || ($binding['transport_serial'] ?? null) !== $serial)) {
            return null;
        }

        return $device;
    }

    private function scopeValid(object $device): bool
    {
        if (DeviceScope::shared($device)) {
            return DeviceScope::schools($device) !== [] && DB::table('ec_biometric_devices as d')
                ->join('ec_tenants as t', 't.id', '=', 'd.tenant_id')->where('d.id', $device->device_id)
                ->where('d.tenant_id', $device->tenant_id)->where('d.school_id', $device->school_id)
                ->where('d.serial_number', $device->serial)->where('d.status', 'active')->whereNull('d.deleted_at')
                ->where('t.status', 'active')->whereNull('t.deleted_at')->exists();
        }

        return DB::table('ec_biometric_devices as d')->join('ec_schools as s', 's.id', '=', 'd.school_id')
            ->join('ec_tenants as t', 't.id', '=', 'd.tenant_id')
            ->where('d.id', $device->device_id)->where('d.tenant_id', $device->tenant_id)->where('d.school_id', $device->school_id)
            ->where('d.serial_number', $device->serial)->where('d.status', 'active')->whereNull('d.deleted_at')
            ->where('s.tenant_id', $device->tenant_id)->where('s.status', 'active')->whereNull('s.deleted_at')
            ->where('t.status', 'active')->whereNull('t.deleted_at')->exists();
    }

    public static function success(array $extra = []): array
    {
        return ['status' => 200, 'code' => '0x00000000', 'errorMsg' => 'Succeeded.'] + $extra;
    }

    private static function failure(int $status, array $data = []): array
    {
        return ['status' => $status, 'body' => ['status' => $status, 'code' => '0x0020000f', 'errorMsg' => 'Invalid SessionID.', 'data' => $data]];
    }
}
