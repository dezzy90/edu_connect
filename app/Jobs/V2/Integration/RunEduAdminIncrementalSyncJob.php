<?php

namespace App\Jobs\V2\Integration;

use App\Models\V2\IntegrationConnection;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunEduAdminIncrementalSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $connectionId,
        public readonly ?string $driver = null,
        public readonly ?string $fixturePath = null,
        public readonly ?string $updatedAfter = null,
        public readonly array $resources = [],
    ) {
    }

    public function handle(EduAdminConnectorFactory $connectors, SyncCoordinator $sync): void
    {
        $connection = $this->eligibleConnection();

        if (!$connection) {
            return;
        }

        $connector = $connectors->make($connection, [
            'driver' => $this->driver,
            'fixture_path' => $this->fixturePath,
        ]);

        $sync->runIncrementalSync($connection, $connector, [
            'triggered_by_type' => 'scheduler',
            'updated_after' => $this->updatedAfter,
            'resources' => $this->resources ?: null,
            'metadata' => [
                'job' => self::class,
                'driver' => $this->driver ?? config('integrations.providers.edu_admin.driver', 'fixture'),
                'resources' => $this->resources ?: null,
            ],
        ]);
    }

    private function eligibleConnection(): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->whereKey($this->connectionId)
            ->where('provider', 'edu_admin')
            ->where('mode', 'connected')
            ->where('status', 'active')
            ->first();
    }
}
