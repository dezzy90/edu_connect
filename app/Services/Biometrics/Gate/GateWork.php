<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use Throwable;

/** Run independently of source sync and portrait downloads; a bad projection cannot block old outbox delivery. */
final class GateWork
{
    public function tick(int $limit = 100): array
    {
        $stats = ['observations' => 0, 'delivery' => ['sent' => 0, 'skipped' => 0, 'retry' => 0, 'held' => 0], 'failures' => []];
        try {
            app(PublicationPolicies::class)->work($limit);
        } catch (Throwable) {
            $stats['failures'][] = 'policy_materialization_failed';
        }
        try {
            $stats['observations'] = app(DayProjector::class)->work($limit);
        } catch (Throwable) {
            $stats['failures'][] = 'projection_failed';
        }
        try {
            $stats['delivery'] = app(OutboxDispatcher::class)->work($limit);
        } catch (Throwable) {
            $stats['failures'][] = 'delivery_failed';
        }

        return $stats;
    }
}
