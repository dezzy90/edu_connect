<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Coalesced durable change signals. No portrait, credential or arbitrary command is stored here. */
final class EnrollmentWork
{
    public static function enabled(): bool
    {
        return config('hikvision_push.enrollment_enabled') === true
            && config('hikvision_push.enrollment_work_enabled') === true
            && Schema::hasTable('ec_hikvision_enrollment_work');
    }

    private static function photos(): Builder
    {
        $connections = config('hikvision_push.enrollment_connection_ids', []);

        return DB::table('ec_hikvision_enrollment_photos')->when($connections !== [], fn ($q) => $q->whereIn('connection_id', $connections));
    }

    public static function request(int $student): void
    {
        if (! self::enabled() || ! self::photos()->where('student_id', $student)->exists()) {
            return;
        }
        DB::transaction(static function () use ($student): void {
            DB::table('ec_hikvision_enrollment_work')->insertOrIgnore(['student_id' => $student,
                'requested_version' => 0, 'processed_version' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('ec_hikvision_enrollment_work')->where('student_id', $student)->lockForUpdate()->sole();
            DB::table('ec_hikvision_enrollment_work')->where('student_id', $student)->update([
                'requested_version' => $row->requested_version + 1, 'available_at' => now(), 'attempts' => 0,
                'error_code' => null, 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function work(int $limit = 20, bool $sweep = false): array
    {
        $stats = ['processed' => 0, 'failed' => 0];
        if (! self::enabled()) {
            return $stats;
        }
        $limit = max(1, min(500, $limit));
        $seen = [];
        for ($i = 0; $i < $limit; $i++) {
            $row = DB::transaction(static function () use ($seen): ?object {
                $row = DB::table('ec_hikvision_enrollment_work')->whereColumn('requested_version', '>', 'processed_version')
                    ->where('available_at', '<=', now())->where('lease_until', '<=', now()->timestamp)
                    ->whereNotIn('student_id', $seen)->whereIn('student_id', self::photos()->select('student_id'))
                    ->orderBy('available_at')->orderBy('student_id')->lockForUpdate()->first();
                if (! $row) {
                    return null;
                }
                $row->lease_token = (string) Str::uuid();
                DB::table('ec_hikvision_enrollment_work')->where('student_id', $row->student_id)->update([
                    'lease_token' => $row->lease_token, 'lease_until' => now()->timestamp + 120, 'updated_at' => now(),
                ]);

                return $row;
            }, 3);
            if (! $row) {
                break;
            }
            $seen[] = (int) $row->student_id;
            try {
                $this->process((int) $row->student_id);
                DB::transaction(static function () use ($row): void {
                    $current = DB::table('ec_hikvision_enrollment_work')->where('student_id', $row->student_id)->lockForUpdate()->sole();
                    if ($current->lease_token !== $row->lease_token) {
                        return;
                    }
                    $photo = self::photos()->where('student_id', $row->student_id)->first();
                    $retry = $photo && $photo->state === 'photo_unavailable' && $photo->attempts < 5 && $photo->retry_at !== null;
                    $newer = $current->requested_version > $row->requested_version;
                    DB::table('ec_hikvision_enrollment_work')->where('student_id', $row->student_id)->update([
                        'processed_version' => $retry && ! $newer ? $current->processed_version : $row->requested_version,
                        'available_at' => $newer ? now() : ($retry ? $photo->retry_at : null),
                        'lease_token' => null, 'lease_until' => 0, 'error_code' => $retry ? 'photo_retry_pending' : null, 'updated_at' => now(),
                    ]);
                }, 3);
                $stats['processed']++;
            } catch (Throwable) {
                DB::table('ec_hikvision_enrollment_work')->where('student_id', $row->student_id)->where('lease_token', $row->lease_token)
                    ->update(['lease_token' => null, 'lease_until' => 0, 'attempts' => DB::raw('attempts + 1'),
                        'available_at' => now()->addSeconds(min(300, 5 * 2 ** min(6, (int) $row->attempts))),
                        'error_code' => 'profile_work_failed', 'updated_at' => now()]);
                $stats['failed']++;
            }
        }
        if ($sweep && count($seen) < $limit) {
            $rows = self::photos()->whereNotIn('student_id', $seen)->orderBy('last_reconciled_at')->orderBy('id')
                ->limit($limit - count($seen))->get(['student_id']);
            foreach ($rows as $row) {
                // The safety sweep uses the same per-student lease and is not a competing preparation path.
                self::request((int) $row->student_id);
            }
            $more = $this->work($limit - count($seen), false);
            foreach ($stats as $key => $_) {
                $stats[$key] += $more[$key];
            }
        }

        return $stats;
    }

    private function process(int $student): void
    {
        try {
            app(EnrollmentRoster::class)->reconcile($student);
            app(EnrollmentPhoto::class)->prepare($student);
        } finally {
            self::photos()->where('student_id', $student)->update(['last_reconciled_at' => now()]);
        }
    }
}
