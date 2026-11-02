<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\V2\IntegrationConnection;
use App\Services\Integration\IntegrationAuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit finite operator approval; never invoked by a worker, retry or HTTP route. */
final class MirrorRecovery
{
    public function recover(int $tenantId, int $connectionId, array $approvals): int
    {
        if (! array_is_list($approvals) || $approvals === [] || count($approvals) > 500
            || count(array_unique(array_column($approvals, 'outbox_id'))) !== count($approvals)) {
            throw new RuntimeException('Approve a bounded unique set of held mirror intents.');
        }

        return DB::transaction(function () use ($tenantId, $connectionId, $approvals): int {
            $connection = IntegrationConnection::where('id', $connectionId)->where('tenant_id', $tenantId)
                ->where('provider', 'edu_admin')->where('status', 'active')->lockForUpdate()->sole();
            $changed = 0;
            foreach ($approvals as $approval) {
                $row = DB::table('ec_gate_outbox')->where('id', $approval['outbox_id'])->lockForUpdate()->sole();
                $day = DB::table('ec_gate_days')->where('id', $row->day_id)->where('tenant_id', $tenantId)->sole();
                $event = json_decode($row->payload, true, 16, JSON_THROW_ON_ERROR);
                $snapshot = $approval['snapshot'];
                if ($row->destination !== 'edu_admin' || (int) $row->revision !== 1
                    || ($event['event_id'] ?? null) !== $row->event_id
                    || ! hash_equals($approval['payload_sha256'], hash('sha256', $row->payload))
                    || ($event['tenant_id'] ?? null) !== $tenantId || ($event['school_id'] ?? null) !== (int) $day->school_id
                    || ($event['student_id'] ?? null) !== (int) $day->student_id
                    || ($snapshot['connection_id'] ?? null) !== $connectionId) {
                    throw new RuntimeException('The approved mirror identity or payload changed.');
                }
                // An exact replay may observe normal worker progress; it never resets delivery state.
                if ($row->delivery_snapshot !== null) {
                    if (json_decode($row->delivery_snapshot, true, 16, JSON_THROW_ON_ERROR) !== $snapshot) {
                        throw new RuntimeException('An existing frozen mirror destination cannot be replaced.');
                    }
                    continue;
                }
                $student = DB::table('ec_students')->where('id', $day->student_id)->where('tenant_id', $tenantId)
                    ->where('school_id', $day->school_id)->where('source_system', 'edu_admin')->sole();
                $head = DB::table('ec_gate_movements')->where('event_id', $row->event_id)->where('day_id', $day->id)->sole();
                if ($row->status !== 'held_mapping' || (int) $row->attempts !== 0
                    || (int) $head->revision !== 1 || $head->state !== 'active' || $head->payload !== $row->payload
                    || (string) $student->source_id !== (string) ($snapshot['student_id'] ?? '')
                    || DeliverySnapshot::currentMirror($event) !== $snapshot) {
                    throw new RuntimeException('Recovery requires the exact reviewed source mapping and an unsent held intent.');
                }
                DB::table('ec_gate_outbox')->where('id', $row->id)->update([
                    'delivery_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'status' => 'pending',
                    'available_at' => now(), 'last_error' => null,
                ]);
                $changed++;
            }
            if ($changed > 0) {
                app(IntegrationAuditLogger::class)->record($connection, 'gate', 'gate.mirror_mapping.recovered',
                    'Approved held gate attendance for normal EduAdmin delivery.',
                    ['outbox_ids' => array_column($approvals, 'outbox_id'), 'recovered' => $changed,
                        'approval_sha256' => hash('sha256', json_encode($approvals, JSON_THROW_ON_ERROR))], status: 'completed');
            }

            return $changed;
        });
    }
}
