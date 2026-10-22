<?php

namespace App\Console\Commands;

use App\Services\Biometrics\Gate\GateWork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Independent gate projection/delivery; never pulls portraits or calls the legacy attendance outbox. */
final class WorkGateAttendance extends Command
{
    protected $signature = 'gate:work
        {--limit=10 : Maximum observations and delivery attempts per pass (1-500)}
        {--seconds=0 : Repeat-pass time budget (0-55); checked between passes, not an HTTP cancellation deadline}
        {--interval-ms=2000 : Delay between passes (250-5000 milliseconds)}
        {--json : Print bounded aggregate status without personal data}';

    protected $description = 'Process configured school gate observations and dedicated EduAdmin/parent delivery independently';

    public function handle(GateWork $work): int
    {
        $limit = $this->boundedInteger('limit', 1, 500);
        $seconds = $this->boundedInteger('seconds', 0, 55);
        $interval = $this->boundedInteger('interval-ms', 250, 5000);
        if ($limit === null || $seconds === null || $interval === null) {
            return $this->finish(['ok' => false, 'error' => 'invalid_worker_options'], self::INVALID);
        }
        if (config('gate.enabled') === false) {
            return $this->finish(['ok' => true, 'enabled' => false, 'passes' => 0], self::SUCCESS);
        }
        $schools = config('gate.school_ids');
        if (config('gate.enabled') !== true || ! is_array($schools) || ! array_is_list($schools) || $schools === []
            || array_filter($schools, static fn ($id) => ! is_int($id) || $id < 1)
            || count($schools) !== count(array_unique($schools))) {
            return $this->finish(['ok' => false, 'error' => 'gate_scope_not_configured'], self::FAILURE);
        }
        $lock = null;
        $acquired = false;
        try {
            // A shared cache is required across hosts; database day locks/outbox leases still protect correctness.
            $lock = Cache::lock('biometrics:gate:work', 120);
            if (! $lock->get()) {
                return $this->finish(['ok' => true, 'enabled' => true, 'skipped' => 'worker_already_running', 'passes' => 0], self::SUCCESS);
            }
            $acquired = true;
            $deadline = hrtime(true) + $seconds * 1000000000;
            $stats = ['ok' => true, 'enabled' => true, 'school_count' => count($schools), 'passes' => 0, 'observations' => 0,
                'delivery' => ['sent' => 0, 'skipped' => 0, 'retry' => 0, 'held' => 0], 'failures' => []];
            do {
                if (config('gate.enabled') !== true || config('gate.school_ids') !== $schools) {
                    $stats['failures'][] = 'gate_scope_changed';
                    break;
                }
                $pass = $work->tick($limit);
                $stats['passes']++;
                $stats['observations'] += $pass['observations'];
                foreach (array_keys($stats['delivery']) as $key) {
                    $stats['delivery'][$key] += $pass['delivery'][$key];
                }
                foreach ($pass['failures'] as $failure) {
                    $stats['failures'][] = in_array($failure, ['policy_materialization_failed', 'projection_failed', 'delivery_failed'], true) ? $failure : 'gate_pass_failed';
                }
                if ($seconds === 0 || hrtime(true) + $interval * 1000000 >= $deadline) {
                    break;
                }
                usleep($interval * 1000);
            } while (hrtime(true) < $deadline);
            $stats['failures'] = array_values(array_unique($stats['failures']));
            $stats['ok'] = $stats['failures'] === [];

            return $this->finish($stats, $stats['ok'] ? self::SUCCESS : self::FAILURE);
        } catch (Throwable) {
            // Cache/database/transport exceptions can contain sensitive values. Emit fixed metadata only.
            return $this->finish(['ok' => false, 'error' => 'gate_worker_failed'], self::FAILURE);
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The lease expires; no exception, token or payload is printed.
                }
            }
        }
    }

    private function boundedInteger(string $option, int $min, int $max): ?int
    {
        $value = $this->option($option);
        if (! is_string($value) || preg_match('/^(?:0|[1-9]\d{0,5})$/D', $value) !== 1) {
            return null;
        }
        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }

    private function finish(array $status, int $exit): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $status['ok']) {
            $this->error('Gate worker failed. Use --json for bounded failure metadata.');
        } elseif (($status['enabled'] ?? null) === false) {
            $this->info('Gate processing is disabled; no work performed.');
        } elseif (isset($status['skipped'])) {
            $this->info('Another gate worker holds the processing lease; no work performed.');
        } else {
            $this->info('Gate worker finished: passes='.$status['passes'].'; observations='.$status['observations'].'; delivered='.$status['delivery']['sent'].'.');
        }

        return $exit;
    }
}
