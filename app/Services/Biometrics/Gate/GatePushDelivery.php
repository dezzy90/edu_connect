<?php

namespace App\Services\Biometrics\Gate;

use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationDelivery;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Revalidate family access immediately before push, not just when the gate event was recorded. */
final class GatePushDelivery
{
    public static function allowed(MobileNotification $notification): bool
    {
        $data = $notification->data ?? [];
        if (($data['source'] ?? null) !== 'gate') return true;
        if (config('gate.parent_delivery_enabled') !== true || config('gate.parent_push_enabled') !== true
            || ! in_array((int) $notification->school_id, config('gate.school_ids', []), true)
            || ! in_array(config('educonnect.notifications.push_transport'), ['provider', 'remote', 'fcm', 'apns'], true)) return false;
        $event = $data + ['tenant_id' => (int) $notification->tenant_id];
        if (! isset($event['student_id'], $event['school_id'], $event['gate_event_id'], $event['revision'], $event['movement'], $event['occurred_at'])
            || (int) $event['school_id'] !== (int) $notification->school_id || ! ParentAlertSettings::permits($event)) return false;
        $head = DB::table('ec_gate_movements as m')->join('ec_gate_days as d', 'd.id', '=', 'm.day_id')
            ->where('m.event_id', $event['gate_event_id'])->where('d.tenant_id', $notification->tenant_id)
            ->where('d.school_id', $notification->school_id)->where('d.student_id', $event['student_id'])->first(['m.revision']);
        if (! $head || (int) $head->revision !== (int) $event['revision']) return false;
        $parent = $notification->parentAccount;
        if (! $parent || $parent->status !== 'active' || $parent->deleted_at) return false;
        $preference = DB::table('ec_notification_preferences')->where('parent_account_id', $parent->id)->where('category', 'attendance')->first();
        if ($preference && ! $preference->push_enabled) return false;
        return EnrollmentRoster::eligibleStudents((int) $notification->tenant_id, (int) $notification->school_id)
            ->where('id', $event['student_id'])->where('mobile_visible', true)->exists()
            && DB::table('ec_parent_student_links')->where('parent_account_id', $parent->id)->where('tenant_id', $notification->tenant_id)
                ->where('school_id', $notification->school_id)->where('student_id', $event['student_id'])->where('status', 'active')->whereNull('deleted_at')->exists();
    }

    public static function claim(NotificationDelivery $delivery): ?string
    {
        if (! Schema::hasTable('ec_gate_push_leases')) return null;
        return DB::transaction(function () use ($delivery): ?string {
            $row = DB::table('ec_notification_deliveries')->where('id', $delivery->id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['queued', 'failed'], true)) return null;
            if ((int) $row->attempts >= max(1, (int) config('educonnect.notifications.push_max_attempts', 3))
                || ($row->next_attempt_at && \Carbon\CarbonImmutable::parse($row->next_attempt_at, 'UTC')->isFuture())) return null;
            DB::table('ec_gate_push_leases')->insertOrIgnore(['delivery_id' => $delivery->id, 'token' => (string) Str::uuid(), 'expires_at' => 0]);
            $lease = DB::table('ec_gate_push_leases')->where('delivery_id', $delivery->id)->lockForUpdate()->sole();
            if ($lease->expires_at > now()->timestamp) return null;
            $token = (string) Str::uuid();
            DB::table('ec_gate_push_leases')->where('delivery_id', $delivery->id)->update(['token' => $token, 'expires_at' => now()->timestamp + 120]);
            $delivery->refresh();
            return $token;
        }, 3);
    }

    public static function release(int $id, string $token): void
    {
        DB::table('ec_gate_push_leases')->where('delivery_id', $id)->where('token', $token)->update(['expires_at' => 0]);
    }
}
