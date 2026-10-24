<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use App\Services\Biometrics\Devices\DeviceScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;

/** Must be called inside the receiver's device-locked transaction. Never publishes attendance. */
class PushInbox
{
    public function capture(object $device, array $entry): array
    {
        $uuid = $entry['UUID'];
        $payloadHash = hash('sha256', json_encode([$entry['dataFormat'] ?? null, $entry['data'] ?? null], JSON_THROW_ON_ERROR));
        $delivery = DB::table('ec_hikvision_push_deliveries')->where('push_device_id', $device->id)->where('upload_uuid', $uuid)->first();
        if ($delivery) {
            if (! hash_equals($delivery->payload_hash, $payloadHash)) {
                DB::table('ec_hikvision_push_conflicts')->insert([
                    'push_device_id' => $device->id, 'upload_uuid' => $uuid,
                    'original_hash' => $delivery->payload_hash, 'conflicting_hash' => $payloadHash, 'received_at' => now(),
                ]);
                DB::table('ec_hikvision_push_deliveries')->where('id', $delivery->id)->update(['status' => 'held_content_conflict']);
                if ($delivery->observation_id !== null) {
                    DB::table('ec_hikvision_observations')->where('id', $delivery->observation_id)->update(['status' => 'held_content_conflict']);
                }
            }

            return self::ack($uuid, $delivery->status === 'held_content_conflict' || ! hash_equals($delivery->payload_hash, $payloadHash) ? 409 : 200);
        }

        $observationId = null;
        $status = 'held_invalid_payload';
        try {
            $observation = PushObservation::normalize($device, PushPayload::event($entry, $this->allowOpenEnd($device)));
            $existing = DB::table('ec_hikvision_observations')->where('event_key', $observation['event_key'])->first();
            if ($existing) {
                $observationId = $existing->id;
                $status = $existing->status;
                $savedMetadata = json_decode($existing->metadata, true, 16, JSON_THROW_ON_ERROR);
                $sourceFingerprint = $savedMetadata['scope_source_fingerprint'] ?? $existing->fingerprint;
                if (! is_string($sourceFingerprint) || ! hash_equals($sourceFingerprint, $observation['fingerprint'])) {
                    $status = 'held_content_conflict';
                    DB::table('ec_hikvision_observations')->where('id', $existing->id)->update(['status' => $status]);
                    DB::table('ec_hikvision_push_conflicts')->insert([
                        'push_device_id' => $device->id, 'upload_uuid' => $uuid, 'original_hash' => $existing->fingerprint,
                        'conflicting_hash' => $observation['fingerprint'], 'received_at' => now(),
                    ]);
                }
            } else {
                $status = $observation['status'];
                $schoolId = (int) $device->school_id;
                if (DeviceScope::shared($device) && $status === 'recognition_candidate') {
                    $proof = DeviceScope::route($device, $observation['metadata']);
                    if ($proof === null) {
                        $status = 'held_person_mapping';
                    } else {
                        // Physical event identity stays stable across roster/assignment edits.
                        $observation['metadata']['scope_source_fingerprint'] = $observation['fingerprint'];
                        $observation['metadata']['shared_gate_scope'] = $proof;
                        $observation['metadata']['school_id'] = $schoolId = $proof['school_id'];
                        $observation['fingerprint'] = hash('sha256', json_encode($observation['metadata'], JSON_THROW_ON_ERROR));
                    }
                }
                if (strtotime($observation['metadata']['occurred_at']) > now()->getTimestamp() + (int) config('hikvision_push.future_tolerance_seconds')) {
                    $status = 'held_future_clock';
                } elseif ($status === 'recognition_candidate') {
                    $mappingCount = DB::table('ec_device_personnel as p')->join('ec_students as s', 's.id', '=', 'p.student_id')
                        ->where('p.device_id', $device->device_id)->where('p.tenant_id', $device->tenant_id)->where('p.school_id', $schoolId)
                        ->where('p.person_identifier', $observation['metadata']['employee_no'])
                        ->where('s.tenant_id', $device->tenant_id)->where('s.school_id', $schoolId)->whereNull('s.deleted_at')
                        ->whereIn('s.status', ['active', 'enrolled'])->count();
                    if ($mappingCount !== 1) {
                        $status = 'held_person_mapping';
                    }
                }
                $observationId = DB::table('ec_hikvision_observations')->insertGetId([
                    'push_device_id' => $device->id, 'tenant_id' => $device->tenant_id, 'school_id' => $schoolId,
                    'event_key' => $observation['event_key'], 'fingerprint' => $observation['fingerprint'],
                    'metadata' => json_encode($observation['metadata'], JSON_THROW_ON_ERROR), 'status' => $status, 'received_at' => now(),
                ]);
                if ($status === 'recognition_candidate' && config('hikvision_push.enrollment_enabled')
                    && Schema::hasTable('ec_hikvision_enrollments')) {
                    $instant = \Carbon\CarbonImmutable::parse($observation['metadata']['occurred_at'])->utc()->format('Y-m-d H:i:s');
                    DB::table('ec_hikvision_enrollments')->where('push_device_id', $device->id)
                        ->where('tenant_id', $device->tenant_id)->where('school_id', $schoolId)
                        ->where('person_identifier', $observation['metadata']['employee_no'])->where('desired_present', true)
                        ->where('state', 'device_ready')->whereColumn('confirmed_revision', 'desired_revision')
                        ->where('last_confirmed_at', '<=', $instant)
                        ->update(['state' => 'recognition_confirmed', 'recognition_confirmed_at' => now(), 'updated_at' => now()]);
                }
            }
        } catch (InvalidArgumentException|JsonException $error) {
            // Durable hash-only receipt; invalid/unimplemented media never becomes attendance.
        }
        DB::table('ec_hikvision_push_deliveries')->insert([
            'push_device_id' => $device->id, 'upload_uuid' => $uuid, 'payload_hash' => $payloadHash,
            'observation_id' => $observationId, 'status' => $status, 'received_at' => now(),
        ]);

        return self::ack($uuid, $status === 'held_content_conflict' ? 409 : 200);
    }

    private function allowOpenEnd(object $device): bool
    {
        // An operator-approved compatibility profile is not an enrollment or door-right grant.
        // Keep the global parser strict and fail closed on a model/firmware change.
        if ($device->model !== 'DS-K1T342MFWX-E1' || $device->firmware !== 'V4.48.0_build260417') {
            return false;
        }
        $settings = json_decode(DB::table('ec_biometric_devices')->where('id', $device->device_id)->value('settings') ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        $profile = is_array($settings) ? ($settings['hikvision_event_compat'] ?? null) : null;

        return is_array($profile) && ($profile['allow_open_multipart_end'] ?? null) === true
            && ($profile['model'] ?? null) === $device->model && ($profile['firmware'] ?? null) === $device->firmware;
    }

    private static function ack(string $uuid, int $status): array
    {
        return ['UUID' => $uuid, 'status' => $status,
            'code' => $status === 200 ? '0x00000000' : '0x00100001',
            'errorMsg' => $status === 200 ? 'Succeeded.' : 'Conflicting event.'];
    }
}
