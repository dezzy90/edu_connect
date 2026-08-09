<?php

namespace App\Services\Integration;

use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationMapping;
use Carbon\CarbonInterface;

class MappingService
{
    public function upsert(
        IntegrationConnection $connection,
        string $localType,
        int $localId,
        string $externalType,
        int|string $externalId,
        ?string $checksum = null,
        ?CarbonInterface $externalUpdatedAt = null
    ): IntegrationMapping {
        return IntegrationMapping::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'external_type' => $externalType,
                'external_id' => (string) $externalId,
            ],
            [
                'local_type' => $localType,
                'local_id' => $localId,
                'checksum' => $checksum,
                'external_updated_at' => $externalUpdatedAt,
            ]
        );
    }

    public function findLocalId(
        IntegrationConnection $connection,
        string $externalType,
        int|string $externalId
    ): ?int {
        $mapping = IntegrationMapping::query()
            ->where('connection_id', $connection->id)
            ->where('external_type', $externalType)
            ->where('external_id', (string) $externalId)
            ->first();

        return $mapping?->local_id;
    }

    public function findExternalId(
        IntegrationConnection $connection,
        string $localType,
        int $localId
    ): ?string {
        $mapping = IntegrationMapping::query()
            ->where('connection_id', $connection->id)
            ->where('local_type', $localType)
            ->where('local_id', $localId)
            ->first();

        return $mapping?->external_id;
    }

    public function forgetLocal(
        IntegrationConnection $connection,
        string $localType,
        int $localId
    ): int {
        return IntegrationMapping::query()
            ->where('connection_id', $connection->id)
            ->where('local_type', $localType)
            ->where('local_id', $localId)
            ->delete();
    }
}
