<?php

namespace Tests\Support\Biometrics;

use App\Models\V2\BiometricDevice;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\ParentAccount;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Biometrics\Hikvision\PushObservation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\V2Schema;

final class GateFixture
{
    public static function boot(): array
    {
        V2Schema::migrate();
        (require base_path('database/migrations/2026_10_10_000020_create_hikvision_push_inbox.php'))->up();
        (require base_path('database/migrations/2026_10_20_000022_create_gate_workflow.php'))->up();
        $tenant = Tenant::create(['name' => 'SYNTHETIC gate tenant', 'slug' => 'gate-fixture', 'status' => 'active']);
        $school = School::create(['tenant_id' => $tenant->id, 'name' => 'SYNTHETIC school', 'slug' => 'gate', 'type' => 'secondary', 'status' => 'active']);
        $student = Student::create(['tenant_id' => $tenant->id, 'school_id' => $school->id, 'student_number' => 'SYNTHETIC-GATE',
            'first_name' => 'Synthetic', 'last_name' => 'Adult', 'status' => 'enrolled', 'mobile_visible' => true]);
        $device = BiometricDevice::create(['tenant_id' => $tenant->id, 'school_id' => $school->id, 'name' => 'SYNTHETIC terminal',
            'device_uid' => 'SYNTHETIC-GATE', 'device_type' => 'hikvision', 'status' => 'active']);
        $push = (object) ['id' => 1, 'device_id' => $device->id, 'tenant_id' => $tenant->id,
            'school_id' => $school->id, 'deployment_epoch' => '71b8a962-1020-4026-a000-000000000004'];
        DB::table('ec_hikvision_push_devices')->insert((array) $push + ['serial' => 'SYNTHETIC-GATE', 'model' => 'SYNTHETIC',
            'firmware' => 'SYNTHETIC', 'auth_profile' => 'sha256', 'username' => 'synthetic', 'credential_encrypted' => 'unused',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ec_device_personnel')->insert(['tenant_id' => $tenant->id, 'school_id' => $school->id, 'device_id' => $device->id,
            'student_id' => $student->id, 'person_identifier' => 'SYNTHETIC1', 'sync_status' => 'acknowledged', 'created_at' => now(), 'updated_at' => now()]);
        $policy = ['tenant_id' => $tenant->id, 'school_id' => $school->id, 'version' => 'SYNTHETIC-v1', 'day' => '2026-10-20',
            'timezone' => 'Africa/Douala', 'closed' => false, 'late_at_cutoff' => false, 'arrival_start' => '07:00:00',
            'arrival_end' => '08:00:00', 'late_cutoff' => '08:00:00', 'departure_start' => '15:00:00', 'departure_end' => '16:00:00',
            'repeat_seconds' => 5, 'reversal_seconds' => 30, 'future_tolerance_seconds' => 300,
            'return_rules' => ['min_seconds' => 60, 'max_seconds' => 3600, 'checkout_delay_seconds' => 60]];
        DB::table('ec_gate_policies')->insert(['id' => hash('sha256', 'SYNTHETIC gate policy'), 'tenant_id' => $tenant->id,
            'school_id' => $school->id, 'day' => $policy['day'], 'timezone' => $policy['timezone'], 'version' => $policy['version'],
            'snapshot' => json_encode($policy), 'student_ids' => json_encode([$student->id]), 'device_ids' => json_encode([$device->id]),
            'effective_from' => '2026-10-20 00:00:00', 'enabled' => true, 'confirmed_by' => 1]);
        $connection = IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'edu_admin', 'mode' => 'connected',
            'status' => 'active', 'base_url' => 'https://eduadmin.test', 'enabled_school_ids' => [$school->id],
            'encrypted_access_token' => Crypt::encryptString('SYNTHETIC-TOKEN'), 'webhook_secret' => Crypt::encryptString('SYNTHETIC-SECRET')]);
        foreach (['student' => [$student->id, '2934'], 'school' => [$school->id, '26']] as $type => [$id, $remote]) {
            DB::table('ec_integration_mappings')->insert(['connection_id' => $connection->id, 'local_type' => $type, 'local_id' => $id,
                'external_type' => $type, 'external_id' => $remote, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('ec_gate_mirror_bindings')->insert(['tenant_id' => $tenant->id, 'school_id' => $school->id,
            'connection_id' => $connection->id, 'source_id' => '71b8a962-1020-4026-a000-000000000004', 'remote_school_id' => 26, 'enabled' => true]);
        config(['gate.enabled' => true, 'gate.school_ids' => [$school->id], 'gate.mirror_delivery_enabled' => true, 'gate.parent_delivery_enabled' => true]);

        return compact('tenant', 'school', 'student', 'device', 'push', 'policy', 'connection');
    }

    public static function scan(array $fixture, int $serial, string $time): int
    {
        $normal = PushObservation::normalize($fixture['push'], ['majorEventType' => 5, 'subEventType' => 75,
            'serialNo' => $serial, 'employeeNoString' => 'SYNTHETIC1', 'time' => $fixture['policy']['day'].'T'.$time.'+01:00']);

        return DB::table('ec_hikvision_observations')->insertGetId(['push_device_id' => 1, 'tenant_id' => $fixture['tenant']->id,
            'school_id' => $fixture['school']->id, 'event_key' => $normal['event_key'], 'fingerprint' => $normal['fingerprint'],
            'metadata' => json_encode($normal['metadata']), 'status' => 'recognition_candidate', 'received_at' => now()]);
    }

    public static function parent(array $fixture, string $suffix = '1'): ParentAccount
    {
        $parent = ParentAccount::create(['phone' => 'SYNTHETIC-PARENT-'.$suffix, 'first_name' => 'Synthetic', 'last_name' => 'Parent', 'status' => 'active']);
        DB::table('ec_parent_student_links')->insert(['tenant_id' => $fixture['tenant']->id, 'school_id' => $fixture['school']->id,
            'student_id' => $fixture['student']->id, 'parent_account_id' => $parent->id, 'parent_phone' => $parent->phone,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $parent;
    }
}
