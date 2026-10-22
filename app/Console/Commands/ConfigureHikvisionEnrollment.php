<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ConfigureHikvisionEnrollment extends Command
{
    protected $signature = 'hikvision:enrollment-profile {device_id} {--capacity=} {--fdid=1} {--unmanaged-count=0} {--approve : Confirm this exact firmware capability profile for a controlled test} {--disable : Remove the assignment and queue managed-identity removals}';

    protected $description = 'Configure a local/test enrollment profile; never changes terminal credentials or door rights';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('hikvision_push.enrollment_enabled')) {
            $this->error('Enabled local/test enrollment only. Production activation requires separate approval.');

            return self::FAILURE;
        }
        $capacity = filter_var($this->option('capacity'), FILTER_VALIDATE_INT);
        $unmanaged = filter_var($this->option('unmanaged-count'), FILTER_VALIDATE_INT);
        $fdid = (string) $this->option('fdid');
        if (! $this->option('disable') && ($capacity === false || $capacity < 1 || $capacity > 100000 || $unmanaged === false || $unmanaged < 0
            || $unmanaged > $capacity || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $fdid))) {
            $this->error('Supply a verified positive capacity, valid FDID, and bounded unmanaged count.');

            return self::FAILURE;
        }
        $saved = DB::transaction(function () use ($capacity, $unmanaged, $fdid): bool {
            $push = DB::table('ec_hikvision_push_devices')->where('device_id', (int) $this->argument('device_id'))->lockForUpdate()->first();
            if (! $push || $push->model !== 'DS-K1T342MFWX-E1') {
                return false;
            }
            $device = DB::table('ec_biometric_devices')->where('id', $push->device_id)->lockForUpdate()->sole();
            $settings = json_decode($device->settings ?? '{}', true, 32, JSON_THROW_ON_ERROR);
            if ($this->option('disable')) {
                if (! isset($settings['hikvision_enrollment'])) {
                    return false;
                }
                // Keep the approved protocol profile available for scoped cleanup.
                $settings['hikvision_enrollment']['enabled'] = false;
            } else {
                $settings['hikvision_enrollment'] = ['enabled' => true, 'verified' => (bool) $this->option('approve'),
                    'capacity' => $capacity, 'fdid' => $fdid, 'unmanaged_count' => $unmanaged,
                    'model' => $push->model, 'firmware' => $push->firmware];
            }
            DB::table('ec_biometric_devices')->where('id', $device->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return true;
        });
        if (! $saved) {
            $this->error('An existing registered DS-K1T342MFWX-E1 is required.');

            return self::FAILURE;
        }
        $this->info('Profile saved. Run the enrollment worker to reconcile assignments; no device request was sent by this command.');

        return self::SUCCESS;
    }
}
