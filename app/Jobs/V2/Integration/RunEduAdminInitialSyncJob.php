<?php

namespace App\Jobs\V2\Integration;

use App\Models\V2\IntegrationConnection;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\EduAdminProvisioningService;
use App\Services\Integration\EduAdminProvisioningStatusNotifier;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunEduAdminInitialSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [15, 60, 300, 900];

    public function __construct(public readonly int $connectionId)
    {
    }

    public function handle(
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync,
        EduAdminProvisioningService $provisioning,
        EduAdminProvisioningStatusNotifier $notifier,
    ): void {
        $connection = IntegrationConnection::query()
            ->whereKey($this->connectionId)
            ->where('provider', 'edu_admin')
            ->where('mode', 'connected')
            ->where('status', 'active')
            ->first();

        if (! $connection) {
            return;
        }

        try {
            $run = $sync->runInitialSync($connection, $connectors->make($connection), [
                'triggered_by_type' => 'automatic_provisioning',
                'metadata' => ['request_id' => $connection->provisioning_request_id],
            ]);
            $provisioning->deactivateSchoolsOutsideScope($connection);
        } catch (Throwable $exception) {
            try {
                $notifier->notify($connection->refresh(), 'error', null, $exception->getMessage());
            } catch (Throwable $callbackException) {
                report($callbackException);
            }

            throw $exception;
        }

        $notifier->notify($connection->refresh(), 'connected', $run);
    }
}
