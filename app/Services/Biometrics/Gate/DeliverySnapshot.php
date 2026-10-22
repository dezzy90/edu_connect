<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use Illuminate\Support\Facades\DB;

/** Freeze destination identities, never secrets. Retries must not discover new recipients/mappings. */
final class DeliverySnapshot
{
    public static function mirror(array $event): ?array
    {
        $previous = DB::table('ec_gate_outbox')->where('event_id', $event['event_id'])->where('destination', 'edu_admin')
            ->orderBy('revision')->first();
        if ($previous) {
            return $previous->delivery_snapshot ? json_decode($previous->delivery_snapshot, true, 16, JSON_THROW_ON_ERROR) : null;
        }
        $binding = DB::table('ec_gate_mirror_bindings')->where('tenant_id', $event['tenant_id'])->where('school_id', $event['school_id'])
            ->where('enabled', true)->first();
        if (! $binding || DB::table('ec_gate_mirror_bindings')->where('source_id', $binding->source_id)
            ->where('enabled', true)->where('id', '!=', $binding->id)->exists()) {
            // EduAdmin sources bind exactly one school. Shared hardware does not
            // permit reusing one school's source UUID for another school's events.
            return null;
        }
        $connection = DB::table('ec_integration_connections')->where('id', $binding->connection_id)
            ->where('tenant_id', $event['tenant_id'])->where('provider', 'edu_admin')->where('status', 'active')->first();
        $allowed = $connection ? json_decode($connection->enabled_school_ids ?? '[]', true) : [];
        if (! $connection || ! is_array($allowed) || ! in_array($event['school_id'], $allowed, true)) {
            return null;
        }
        $school = self::mapping((int) $connection->id, 'school', $event['school_id']);
        $student = self::mapping((int) $connection->id, 'student', $event['student_id']);
        if ($school !== (int) $binding->remote_school_id || $student === null) {
            return null;
        }

        return ['binding_id' => (int) $binding->id, 'connection_id' => (int) $connection->id, 'source_id' => $binding->source_id,
            'base_url' => rtrim($connection->base_url, '/'), 'school_id' => $school, 'student_id' => $student];
    }

    public static function parents(array $event): array
    {
        $previous = DB::table('ec_gate_outbox')->where('event_id', $event['event_id'])->where('destination', 'parents')
            ->orderBy('revision')->first();
        if ($previous) {
            return json_decode($previous->delivery_snapshot, true, 16, JSON_THROW_ON_ERROR);
        }
        $ids = DB::table('ec_parent_student_links as l')->join('ec_parent_accounts as p', 'p.id', '=', 'l.parent_account_id')
            ->where('l.student_id', $event['student_id'])->where('l.tenant_id', $event['tenant_id'])->where('l.school_id', $event['school_id'])
            ->where('l.status', 'active')->whereNull('l.deleted_at')->where('p.status', 'active')->whereNull('p.deleted_at')
            ->distinct()->orderBy('p.id')->limit(101)->pluck('p.id')->map(static fn ($id) => (int) $id)->all();
        if (count($ids) > 100) {
            throw new \RuntimeException('Bounded gate recipients required.');
        }

        return ['parent_ids' => $ids];
    }

    private static function mapping(int $connection, string $type, int $local): ?int
    {
        $rows = DB::table('ec_integration_mappings')->where('connection_id', $connection)->where('local_type', $type)
            ->where('external_type', $type)->where('local_id', $local)->limit(2)->get();
        if ($rows->count() !== 1 || ! preg_match('/^[1-9]\d{0,17}$/D', $rows[0]->external_id)) {
            return null;
        }

        return (int) $rows[0]->external_id;
    }
}
