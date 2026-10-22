<?php

namespace App\Console\Commands;

use App\Services\Biometrics\Hikvision\EnrollmentPhoto;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WorkHikvisionEnrollments extends Command
{
    protected $signature = 'hikvision:enrollments-work {--limit=20 : Maximum student profiles per run}';

    protected $description = 'Prepare authoritative EduAdmin portraits and reconcile school-device enrollments';

    public function handle(EnrollmentPhoto $photos, EnrollmentRoster $roster): int
    {
        if (! config('hikvision_push.enrollment_enabled')) {
            $this->info('Hikvision enrollment is disabled; no profiles processed.');

            return self::SUCCESS;
        }
        if (\App\Services\Biometrics\Hikvision\EnrollmentWork::enabled()) {
            $stats = app(\App\Services\Biometrics\Hikvision\EnrollmentWork::class)->work((int) $this->option('limit'), true);
            $this->info('Profiles processed: '.$stats['processed'].'; failures: '.$stats['failed'].'. Device writes await terminal polling.');

            return $stats['failed'] ? self::FAILURE : self::SUCCESS;
        }
        // Rotate through every profile, including ready ones, so assignment/lifecycle
        // changes are picked up without starving a large roster behind the first page.
        $connections = config('hikvision_push.enrollment_connection_ids', []);
        $rows = DB::table('ec_hikvision_enrollment_photos')->when($connections !== [], fn ($q) => $q->whereIn('connection_id', $connections))
            ->orderBy('last_reconciled_at')->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))->get(['id', 'student_id']);
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $roster->reconcile($row->student_id);
                $photos->prepare($row->student_id);
            } catch (\Throwable) {
                // Never print a transport exception: it can contain a token or portrait.
                $failed++;
            } finally {
                DB::table('ec_hikvision_enrollment_photos')->where('id', $row->id)->update(['last_reconciled_at' => now()]);
            }
        }
        $this->info('Profiles processed: '.$rows->count().'; failures: '.$failed.'. Device writes await terminal polling.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
