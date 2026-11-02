<?php

namespace App\Console\Commands;

use App\Services\Auth\MobileTokenPolicy;
use Laravel\Sanctum\Console\Commands\PruneExpired;
use Laravel\Sanctum\Sanctum;

class PruneExpiredTokens extends PruneExpired
{
    public function handle(): int
    {
        $model = Sanctum::$personalAccessTokenModel;
        $hours = max(0, (int) $this->option('hours'));
        $model::where('expires_at', '<', now()->subHours($hours))->delete();

        if ($expiration = config('sanctum.expiration')) {
            // Sanctum's stock age-based pruning would delete indefinite mobile sessions.
            $model::where('created_at', '<', now()->subMinutes($expiration + ($hours * 60)))
                ->chunkById(200, function ($tokens): void {
                    foreach ($tokens as $token) {
                        if (! MobileTokenPolicy::persistent($token)) {
                            $token->delete();
                        }
                    }
                });
        }

        $this->components->info('Expired tokens pruned; persistent mobile parent sessions retained.');

        return self::SUCCESS;
    }
}
