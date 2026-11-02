<?php

namespace App\Console\Commands;

use App\Services\Auth\ReviewerDemoPreview;
use App\Services\Auth\ReviewerDemoProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProvisionReviewerDemo extends Command
{
    protected $signature = 'reviewer-demo:provision {--marker=} {--expect-database=} {--preview} {--apply} {--confirm=}';

    protected $description = 'Plan synthetic reviewer data; --preview inserts and rolls back, --apply creates only after a successful preview.';

    public function handle(ReviewerDemoProvisioner $provisioner): int
    {
        $marker = (string) $this->option('marker');
        $write = $this->option('preview') || $this->option('apply');
        if ($write && ($this->option('preview') && $this->option('apply')
            || $this->option('confirm') !== 'synthetic-only'
            || ! $this->option('expect-database') || $this->option('expect-database') !== DB::connection()->getDatabaseName()
            || config('reviewer_demo.enabled'))) {
            $this->error('STOP: disable reviewer access, confirm the exact database and synthetic-only scope, and select one mode.');

            return self::FAILURE;
        }

        $lock = null;
        try {
            if ($write) {
                $lock = Cache::store(config('mobile_otp.cache_store'))->lock('reviewer-demo:provision', 120);
                if (! $lock->get()) {
                    $this->error('STOP: another provisioning operation is running.');

                    return self::FAILURE;
                }
                if (DB::connection()->getDriverName() === 'mysql') {
                    $tables = DB::select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'ec_%'");
                    foreach ($tables as $table) {
                        if (strtolower((string) $table->ENGINE) !== 'innodb') {
                            throw new \RuntimeException('Transactional tables are required.');
                        }
                    }
                } elseif (DB::connection()->getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
                    throw new \RuntimeException('Only verified MySQL/InnoDB or isolated test SQLite is supported.');
                }
            }

            $plan = $provisioner->plan($marker);
            if ($this->option('apply') && $plan['action'] === 'create') {
                // Always perform the exact rollback preview immediately before any durable write.
                try {
                    $provisioner->provision($marker, true);
                } catch (ReviewerDemoPreview) {
                    $this->info('Transactional preview passed and rolled back.');
                }
            }
            try {
                $result = $write ? $provisioner->provision($marker, (bool) $this->option('preview')) : $plan;
            } catch (ReviewerDemoPreview $preview) {
                $result = ['action' => $preview->result['action']];
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            $this->info('No credentials, environment settings, outbound events or real records were changed.');

            return self::SUCCESS;
        } catch (Throwable) {
            // Database exceptions can contain values. Keep all failure output secret-free.
            $this->error('STOP: schema, identity, isolation or transaction check failed. No partial fixture was committed.');

            return self::FAILURE;
        } finally {
            $lock?->release();
        }
    }
}
