<?php

namespace App\Models\V2\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasSourceIdentity
{
    public function scopeFromSource(Builder $query, string $sourceSystem, int|string $sourceId): Builder
    {
        return $query
            ->where('source_system', $sourceSystem)
            ->where('source_id', (string) $sourceId);
    }

    public function isSyncedFrom(string $sourceSystem): bool
    {
        return $this->source_system === $sourceSystem && filled($this->source_id);
    }
}
