<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Devices;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A terminal has one complex owner but may serve explicit schools inside it. No tenant-wide fallback. */
final class DeviceScope
{
    public static function schoolDevices(int $tenant, int $school): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('ec_biometric_devices')->where('tenant_id', $tenant)->where('status', 'active')->whereNull('deleted_at');
        if (! Schema::hasTable('ec_device_registry')) {
            return $query->where('school_id', $school);
        }

        return $query->where(function ($q) use ($tenant, $school): void {
            $q->where(function ($single) use ($school): void {
                $single->where('school_id', $school)->whereNotExists(fn ($r) => $r->selectRaw('1')->from('ec_device_registry')
                    ->whereColumn('device_id', 'ec_biometric_devices.id')->where('scope_mode', 'shared_gate'));
            })->orWhere(function ($shared) use ($tenant, $school): void {
                $shared->whereExists(fn ($r) => $r->selectRaw('1')->from('ec_device_registry')
                    ->whereColumn('device_id', 'ec_biometric_devices.id')->where('scope_mode', 'shared_gate'))
                    ->whereExists(fn ($r) => $r->selectRaw('1')->from('ec_device_school_assignments')
                        ->whereColumn('device_id', 'ec_biometric_devices.id')->where('tenant_id', $tenant)->where('school_id', $school)
                        ->where('active', true)->whereNull('revoked_at')->where('authorized_from', '<=', now()->utc()));
            });
        });
    }

    public static function shared(object $push): bool
    {
        return Schema::hasTable('ec_device_registry') && DB::table('ec_device_registry')->where('device_id', $push->device_id)
            ->where('scope_mode', 'shared_gate')->exists();
    }

    public static function schools(object $push): array
    {
        if (! self::shared($push)) {
            return [(int) $push->school_id];
        }
        if (config('device_registry.shared_gate_enabled') !== true) {
            return [];
        }
        $rows = self::assignments($push)->get(['a.school_id']);
        $ids = $rows->pluck('school_id')->map(static fn ($id) => (int) $id)->all();

        return count($ids) >= 1 && count($ids) <= 20 && count($ids) === count(array_unique($ids)) ? $ids : [];
    }

    public static function allows(object $push, int $tenant, int $school): bool
    {
        return $tenant === (int) $push->tenant_id && in_array($school, self::schools($push), true);
    }

    public static function historicalSchool(object $push, int $tenant, int $school): bool
    {
        return $tenant === (int) $push->tenant_id && Schema::hasTable('ec_device_school_assignments')
            && DB::table('ec_device_school_assignments')->where('device_id', $push->device_id)->where('tenant_id', $tenant)
                ->where('school_id', $school)->exists();
    }

    private static function assignments(object $push): \Illuminate\Database\Query\Builder
    {
        return DB::table('ec_device_school_assignments as a')->join('ec_schools as s', function ($q): void {
            $q->on('s.id', '=', 'a.school_id')->on('s.tenant_id', '=', 'a.tenant_id');
        })->join('ec_tenants as t', 't.id', '=', 'a.tenant_id')->where('a.device_id', $push->device_id)
            ->where('a.tenant_id', $push->tenant_id)->where('a.active', true)->whereNull('a.revoked_at')
            ->where('a.authorized_from', '<=', now()->utc())->where('s.status', 'active')->whereNull('s.deleted_at')
            ->where('t.status', 'active')->whereNull('t.deleted_at');
    }

    /** Returns a backend-generated proof, never a school claimed by a terminal payload. */
    public static function route(object $push, array $metadata): ?array
    {
        if (! self::shared($push)) {
            return null;
        }
        $schools = self::schools($push);
        if ($schools === []) {
            return null;
        }
        $people = DB::table('ec_device_personnel as p')->join('ec_students as s', function ($q): void {
            $q->on('s.id', '=', 'p.student_id')->on('s.tenant_id', '=', 'p.tenant_id')->on('s.school_id', '=', 'p.school_id');
        })->where('p.device_id', $push->device_id)->where('p.tenant_id', $push->tenant_id)
            ->where('p.person_identifier', $metadata['employee_no'])->where('p.sync_status', 'acknowledged')
            ->whereIn('p.school_id', $schools)->whereNotNull('p.school_bound_at')
            ->whereIn('s.status', ['active', 'enrolled'])->whereNull('s.deleted_at')->limit(2)->get(['p.school_id', 'p.school_bound_at']);
        if ($people->count() !== 1) {
            return null;
        }
        $person = $people->sole();
        $occurred = CarbonImmutable::parse($metadata['occurred_at'])->utc();
        if (CarbonImmutable::parse($person->school_bound_at, 'UTC')->gt($occurred)) {
            return null; // An offline scan must not migrate to a newly assigned student school.
        }
        $rows = self::assignments($push)->where('a.school_id', $person->school_id)->get(['a.id', 'a.epoch', 'a.authorized_from']);
        if ($rows->count() !== 1 || CarbonImmutable::parse($rows->sole()->authorized_from, 'UTC')->gt($occurred)) {
            return null;
        }

        return ['school_id' => (int) $person->school_id, 'assignment_id' => (int) $rows->sole()->id, 'epoch' => $rows->sole()->epoch,
            'device_owner_school_id' => (int) $push->school_id];
    }

    public static function receiptValid(object $push, object $observation, array $metadata): bool
    {
        $proof = $metadata['shared_gate_scope'] ?? null;
        if (! is_array($proof) || ! isset($proof['assignment_id'], $proof['epoch'], $proof['school_id'], $proof['device_owner_school_id'])
            || $proof['school_id'] !== (int) $observation->school_id || $proof['device_owner_school_id'] !== (int) $push->school_id
            || (int) $observation->tenant_id !== (int) $push->tenant_id || ! Schema::hasTable('ec_device_school_assignments')) {
            return false;
        }
        $assignment = DB::table('ec_device_school_assignments')->where('id', $proof['assignment_id'])->where('epoch', $proof['epoch'])
            ->where('device_id', $push->device_id)->where('tenant_id', $push->tenant_id)->where('school_id', $observation->school_id)->first();
        if (! $assignment) {
            return false;
        }
        $received = CarbonImmutable::parse($observation->received_at, 'UTC');

        return CarbonImmutable::parse($assignment->authorized_from, 'UTC')->lte(CarbonImmutable::parse($metadata['occurred_at'])->utc())
            && ((bool) $assignment->active || $assignment->revoked_at !== null)
            && ($assignment->revoked_at === null || CarbonImmutable::parse($assignment->revoked_at, 'UTC')->gt($received));
    }
}
