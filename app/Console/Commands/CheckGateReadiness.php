<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Services\Biometrics\Gate\SetupDrafts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Read-only deployment preflight. No grants, migrations, enrollment or terminal commands. */
final class CheckGateReadiness extends Command
{
    protected $signature = 'gate:readiness {--admin= : Explicit setup coordinator ID} {--school= : Explicit local EduConnect school ID} {--json}';

    protected $description = 'Inspect the saved school activation checklist without changing any state';

    public function handle(): int
    {
        if (! preg_match('/^[1-9]\d{0,9}$/D', (string) $this->option('admin'))
            || ! preg_match('/^[1-9]\d{0,9}$/D', (string) $this->option('school'))) {
            return $this->finish(['ok' => false, 'error' => 'explicit_admin_and_local_school_required'], self::INVALID);
        }
        try {
            $missing = array_values(array_filter(['ec_gate_setup_assignments', 'ec_gate_setup_drafts', 'ec_gate_setup_revisions',
                'ec_gate_activation_assignments', 'ec_gate_publications', 'ec_gate_publication_actions', 'ec_gate_policy_publications',
                'ec_gate_policies', 'ec_gate_operator_assignments', 'ec_gate_mirror_bindings', 'ec_hikvision_push_devices',
                'ec_hikvision_push_sessions', 'ec_hikvision_enrollments', 'ec_hikvision_enrollment_work'], static fn ($table) => ! Schema::hasTable($table)));
            if ($missing !== []) {
                return $this->finish(['ok' => false, 'error' => 'required_schema_missing', 'missing_tables' => $missing], self::FAILURE);
            }
            $actor = AdminUser::find((int) $this->option('admin'));
            if (! $actor) {
                return $this->finish(['ok' => false, 'error' => 'readiness_scope_denied'], self::FAILURE);
            }
            $data = app(SetupDrafts::class)->read($actor, (int) $this->option('school'));
            $status = ['ok' => $data['activation']['can_activate'], 'school_id' => (int) $this->option('school'),
                'draft_revision' => $data['draft']['revision'], 'configuration_complete' => $data['readiness']['configuration_complete'],
                'missing_rules' => $data['readiness']['missing'], 'activation' => $data['activation']];

            return $this->finish($status, $status['ok'] ? self::SUCCESS : self::FAILURE);
        } catch (HttpException) {
            return $this->finish(['ok' => false, 'error' => 'readiness_scope_denied'], self::FAILURE);
        } catch (Throwable) {
            return $this->finish(['ok' => false, 'error' => 'readiness_check_failed'], self::FAILURE);
        }
    }

    private function finish(array $status, int $exit): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($status['ok'] ? 'School activation checklist is ready.' : 'School activation is held. Use --json for the read-only checklist.');
        }

        return $exit;
    }
}
