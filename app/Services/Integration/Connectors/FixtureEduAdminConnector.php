<?php

namespace App\Services\Integration\Connectors;

use App\Contracts\EduAdminConnector;
use RuntimeException;

class FixtureEduAdminConnector implements EduAdminConnector
{
    public function __construct(private readonly string $fixturePath)
    {
    }

    public function bootstrap(): array
    {
        return $this->readJson('bootstrap');
    }

    public function resource(string $resource, ?string $cursor = null, array $filters = []): array
    {
        $payload = $this->readJson($resource);

        if ($cursor !== null && ($payload['cursor'] ?? null) !== $cursor) {
            return [
                'data' => [],
                'next_cursor' => null,
                'has_more' => false,
            ];
        }

        if (!empty($filters['updated_after'])) {
            $updatedAfter = strtotime((string) $filters['updated_after']);
            $payload['data'] = collect($payload['data'] ?? [])
                ->filter(fn (array $record) => strtotime((string) ($record['updated_at'] ?? '')) > $updatedAfter)
                ->values()
                ->all();
        }

        return $payload;
    }

    public function pushAttendanceEvents(array $events): array
    {
        return [
            'accepted' => collect($events)
                ->pluck('event_key')
                ->filter()
                ->values()
                ->all(),
            'duplicates' => [],
            'rejected' => [],
        ];
    }

    private function readJson(string $name): array
    {
        $file = rtrim($this->fixturePath, DIRECTORY_SEPARATOR . '/')
            . DIRECTORY_SEPARATOR
            . $name
            . '.json';

        if (!is_file($file)) {
            throw new RuntimeException("Edu-admin fixture not found: {$file}");
        }

        $json = file_get_contents($file);
        $data = json_decode((string) $json, true);

        if (!is_array($data)) {
            throw new RuntimeException("Edu-admin fixture is invalid JSON: {$file}");
        }

        return $data;
    }
}
