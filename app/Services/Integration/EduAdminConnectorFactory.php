<?php

namespace App\Services\Integration;

use App\Contracts\EduAdminConnector;
use App\Models\V2\IntegrationConnection;
use App\Services\Integration\Connectors\FixtureEduAdminConnector;
use App\Services\Integration\Connectors\HttpEduAdminConnector;
use RuntimeException;

class EduAdminConnectorFactory
{
    public function make(IntegrationConnection $connection, array $options = []): EduAdminConnector
    {
        $driver = $options['driver']
            ?? config('integrations.providers.edu_admin.driver', 'fixture');

        return match ($driver) {
            'fixture' => new FixtureEduAdminConnector($this->fixturePath($options['fixture_path'] ?? null)),
            'http' => new HttpEduAdminConnector($connection),
            default => throw new RuntimeException("Unsupported Edu-admin connector driver: {$driver}"),
        };
    }

    private function fixturePath(?string $override): string
    {
        if ($override && app()->environment('production')) {
            throw new RuntimeException('Fixture connector paths cannot be overridden in production.');
        }

        $path = $override
            ?? config('integrations.providers.edu_admin.fixture_path')
            ?? base_path('tests/Fixtures/edu_admin_connector');

        $realPath = realpath($path);

        if (!$realPath || !is_dir($realPath)) {
            throw new RuntimeException("Edu-admin fixture path does not exist: {$path}");
        }

        if ($override) {
            $fixtureRoot = realpath(base_path('tests/Fixtures'));

            if (
                !$fixtureRoot
                || ($realPath !== $fixtureRoot && !str_starts_with($realPath, $fixtureRoot . DIRECTORY_SEPARATOR))
            ) {
                throw new RuntimeException('Fixture connector paths must stay inside tests/Fixtures.');
            }
        }

        return $realPath;
    }
}
