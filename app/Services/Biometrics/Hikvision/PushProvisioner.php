<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PushProvisioner
{
    public function register(int $deviceId, string $serial, string $firmware, string $profile, string $username, string $password): int
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $serial)
            || strlen($firmware) < 1 || strlen($firmware) > 128
            || ! preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $username)
            || strlen($password) < 20 || strlen($password) > 64) {
            throw new InvalidArgumentException('Valid serial, firmware, username and a 20–64 byte dedicated credential are required.');
        }
        if (! in_array($profile, ['sha256', 'sha1-3.16.1'], true)
            || ($profile === 'sha1-3.16.1' && $firmware !== 'V3.16.1_build250320')) {
            throw new InvalidArgumentException('The SHA-1 profile is restricted to the documented firmware.');
        }

        return DB::transaction(function () use ($deviceId, $serial, $firmware, $profile, $username, $password): int {
            $device = DB::table('ec_biometric_devices')->where('id', $deviceId)->where('status', 'active')->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $device || $device->serial_number !== $serial) {
                throw new InvalidArgumentException('Register an existing biometric device with the matching hardware serial first.');
            }
            $school = DB::table('ec_schools')->where('id', $device->school_id)->where('tenant_id', $device->tenant_id)
                ->where('status', 'active')->whereNull('deleted_at')->first();
            $tenant = DB::table('ec_tenants')->where('id', $device->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists();
            if (! $school || ! $tenant || DB::table('ec_hikvision_push_devices')->where('device_id', $deviceId)->exists()
                || DB::table('ec_hikvision_push_devices')->where('serial', $serial)->exists()) {
                throw new InvalidArgumentException('Explicit matching school/tenant and an unregistered device are required.');
            }

            return DB::table('ec_hikvision_push_devices')->insertGetId([
                'device_id' => $deviceId, 'serial' => $serial, 'tenant_id' => $device->tenant_id,
                'school_id' => $device->school_id, 'deployment_epoch' => (string) Str::uuid(),
                'model' => 'DS-K1T342MFWX-E1', 'firmware' => $firmware, 'auth_profile' => $profile,
                'username' => $username, 'credential_encrypted' => Crypt::encryptString($password),
                'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
