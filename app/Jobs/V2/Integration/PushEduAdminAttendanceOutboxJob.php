<?php

namespace App\Jobs\V2\Integration;

use App\Models\V2\IntegrationConnection;
use App\Services\Integration\AttendanceOutboxDispatcher;
use App\Services\Integration\EduAdminConnectorFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushEduAdminAttendanceOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $connectionId,
        public readonly int $limit = 50,
        public readonly ?string $driver = null,
        public readonly ?string $fixturePath = null,
    ) {
    }

    public function handle(EduAdminConnectorFactory $connectors, AttendanceOutboxDispatcher $dispatcher): void
    {
        $connection = $this->eligibleConnection();

        if (!$connection) {
            return;
        }

        $connector = $connectors->make($connection, [
            'driver' => $this->driver,
            'fixture_path' => $this->fixturePath,
        ]);

        $limit = max(1, $this->limit);

        $dispatcher->enqueuePending($connection, $limit);
        $dispatcher->dispatchPending($connection, $connector, $limit);
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
