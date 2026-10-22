<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use App\Services\Biometrics\Devices\DeviceScope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Dedicated generated commands only. Called inside the receiver's device-locked transaction. */
final class EnrollmentCommands
{
    public function poll(object $device): array
    {
        if (! config('hikvision_push.enrollment_enabled') || ! config('hikvision_push.enrollment_device_writes_enabled')) {
            return [];
        }
        $physical = DB::table('ec_biometric_devices')->where('id', $device->device_id)->first();
        $settings = json_decode($physical->settings ?? '{}', true) ?: [];
        $profile = $settings['hikvision_enrollment'] ?? [];
        if (($profile['verified'] ?? false) !== true
            || ($profile['model'] ?? null) !== $device->model
            || ($profile['firmware'] ?? null) !== $device->firmware
            || ! is_int($profile['capacity'] ?? null) || $profile['capacity'] < 1
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string) ($profile['fdid'] ?? ''))) {
            return [];
        }
        $targets = DB::table('ec_hikvision_enrollments')->where('push_device_id', $device->id)->get();
        $ids = $targets->pluck('id')->all();
        // One in-flight enrollment operation per terminal. An obsolete issued operation
        // is a barrier until its result arrives, not a reason to replay old photo bytes.
        $sent = DB::table('ec_hikvision_enrollment_commands')->whereIn('enrollment_id', $ids)
            ->whereIn('state', ['sent', 'obsolete_sent', 'unconfirmed'])->orderBy('id')->first();
        if ($sent) {
            if (in_array($sent->state, ['obsolete_sent', 'unconfirmed'], true) || strtotime($sent->sent_at) > now()->getTimestamp() - 30) {
                return [];
            }
            if ($sent->attempts >= 5) {
                $this->fail($sent, 'command_unconfirmed', false);

                return [];
            }
            $command = $sent;
        } else {
            $command = DB::table('ec_hikvision_enrollment_commands')->whereIn('enrollment_id', $ids)->where('state', 'queued')
                ->orderByRaw("case when operation in ('remove_status','verify_removed') then 0 else 1 end")
                ->orderBy('id')->first();
        }
        if (! $command) {
            return [];
        }
        $target = DB::table('ec_hikvision_enrollments')->where('id', $command->enrollment_id)->lockForUpdate()->sole();
        $ownedCleanup = in_array($command->operation, ['remove', 'remove_status', 'verify_removed'], true)
            && ($target->person_owned || $target->removal_in_progress)
            && DeviceScope::historicalSchool($device, (int) $target->tenant_id, (int) $target->school_id);
        if ((int) $target->tenant_id !== (int) $device->tenant_id
            || (! DeviceScope::allows($device, (int) $target->tenant_id, (int) $target->school_id) && ! $ownedCleanup)) {
            $this->fail($command, 'device_scope_changed');

            return [];
        }
        $student = DB::table('ec_students')->where('id', $target->student_id)->first();
        $photo = DB::table('ec_hikvision_enrollment_photos')->where('student_id', $target->student_id)->first();
        $cleanup = in_array($command->operation, ['remove_status', 'verify_removed'], true);
        $presentNow = $student && EnrollmentRoster::eligible($student)
            && (int) $student->tenant_id === (int) $target->tenant_id && (int) $student->school_id === (int) $target->school_id
            && DeviceScope::allows($device, (int) $student->tenant_id, (int) $student->school_id)
            && ! ($photo && $photo->source_revision === null && $target->person_owned)
            && ($profile['enabled'] ?? false) === true
            && EnrollmentRoster::profileIncludes((int) $target->student_id, $profile);
        if (! $cleanup && ($command->revision !== $target->desired_revision || (bool) $target->desired_present !== $presentNow)) {
            DB::table('ec_hikvision_enrollment_commands')->where('id', $command->id)->update([
                'state' => $command->state === 'sent' ? 'obsolete_sent' : 'superseded', 'updated_at' => now(),
            ]);

            return [];
        }
        if ($target->removal_in_progress && ! $cleanup && $command->operation !== 'remove') {
            return [];
        }
        if ($target->desired_present && ! $cleanup) {
            if (! $photo || $photo->state !== 'photo_ready' || $photo->source_revision !== $photo->prepared_revision) {
                return [];
            }
            // Let reconciliation version the attendance policy before issuing/retrying
            // an older command snapshot. Never replay a pre-policy person write.
            if ($target->desired_revision !== EnrollmentRoster::revision(true, $target, $student, $photo)) {
                return [];
            }
            $source = DB::table('ec_integration_connections')->where('id', $photo->connection_id)->first();
            $schools = json_decode($source->enabled_school_ids ?? 'null', true);
            $connections = config('hikvision_push.enrollment_connection_ids', []);
            if (! $source || $source->status !== 'active' || $source->provider !== 'edu_admin'
                || ($connections !== [] && ! in_array((int) $source->id, $connections, true))
                || (int) $source->tenant_id !== (int) $target->tenant_id
                || ($schools && ! in_array((int) $target->school_id, array_map('intval', $schools), true))
                || ! DB::table('ec_integration_mappings')->where('connection_id', $photo->connection_id)->where('local_type', 'student')
                    ->where('local_id', $student->id)->where('external_type', 'student')->where('external_id', (string) $student->source_id)->exists()) {
                return [];
            }
            $position = DB::table('ec_hikvision_enrollments')->where('push_device_id', $device->id)
                ->where('desired_present', true)->where('id', '<=', $target->id)->count();
            if ($position + max(0, (int) ($profile['unmanaged_count'] ?? 0)) > $profile['capacity']) {
                $this->fail($command, 'device_capacity_exceeded');

                return [];
            }
            $mapping = DB::table('ec_device_personnel')->where('device_id', $device->device_id)
                ->where(fn ($q) => $q->where('student_id', $target->student_id)->orWhere('person_identifier', $target->person_identifier))->get();
            if ($mapping->contains(fn ($m) => (int) $m->student_id !== (int) $target->student_id
                || $m->person_identifier !== $target->person_identifier || (int) $m->tenant_id !== (int) $target->tenant_id
                || (int) $m->school_id !== (int) $target->school_id)) {
                $this->fail($command, 'person_mapping_conflict');

                return [];
            }
        }
        $wire = $command->payload_encrypted ? json_decode(Crypt::decryptString($command->payload_encrypted), true, 16, JSON_THROW_ON_ERROR)
            : $this->wire($command, $target, $student, $photo, $profile);
        DB::table('ec_hikvision_enrollment_commands')->where('id', $command->id)->update([
            'state' => 'sent', 'attempts' => $command->attempts + 1, 'sent_at' => now(),
            'payload_encrypted' => $command->payload_encrypted ?: Crypt::encryptString(json_encode($wire, JSON_THROW_ON_ERROR)), 'updated_at' => now(),
        ]);
        DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update([
            'state' => $cleanup || $command->operation === 'remove' ? 'removal_pending' : 'syncing',
            'removal_in_progress' => (bool) $target->removal_in_progress || $command->operation === 'remove', 'updated_at' => now(),
        ]);

        return [$wire];
    }

    public function handles(object $device, array $entry, string $text): bool
    {
        if (! Schema::hasTable('ec_hikvision_enrollment_commands')) {
            return false;
        }
        $command = DB::table('ec_hikvision_enrollment_commands as c')->join('ec_hikvision_enrollments as e', 'e.id', '=', 'c.enrollment_id')
            ->where('e.push_device_id', $device->id)->where('c.command_uuid', $entry['UUID'])->select('c.*')->first();
        if (! $command) {
            return false;
        }
        if (! in_array($command->state, ['sent', 'obsolete_sent', 'completed', 'failed', 'unconfirmed'], true)) {
            throw new InvalidArgumentException('Unsent enrollment result.');
        }
        $hash = hash('sha256', $text);
        if ($command->result_hash !== null) {
            if (! hash_equals($command->result_hash, $hash)) {
                throw new InvalidArgumentException('Conflicting enrollment result.');
            }

            return true;
        }
        $target = DB::table('ec_hikvision_enrollments')->where('id', $command->enrollment_id)->lockForUpdate()->sole();
        if (($entry['dataFormat'] ?? null) === 'xmlData') {
            $xpath = PushPayload::xml($text);
            $data = ['statusCode' => $xpath->evaluate('string(//*[local-name()="statusCode"])'),
                'FPID' => $xpath->evaluate('string(//*[local-name()="FPID"])')];
        } elseif (($entry['dataFormat'] ?? null) === 'jsonData') {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } else {
            throw new InvalidArgumentException('Unsupported enrollment result.');
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Enrollment result object required.');
        }
        DB::table('ec_hikvision_enrollment_commands')->where('id', $command->id)->update([
            'result_hash' => $hash, 'state' => 'completed', 'payload_encrypted' => null, 'completed_at' => now(), 'updated_at' => now(),
        ]);
        $searchOperation = in_array($command->operation, ['preflight', 'verify', 'verify_removed'], true);
        $writeOk = in_array($data['statusCode'] ?? null, [1, '1'], true);
        $obsolete = $command->revision !== $target->desired_revision;
        if ($command->operation === 'person' && $writeOk) {
            DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update(['person_owned' => true]);
        }
        if ($command->operation === 'face' && $writeOk && (empty($data['FPID']) || $data['FPID'] === $target->person_identifier)) {
            DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update(['face_owned' => true]);
        }
        if ($obsolete && ! in_array($command->operation, ['remove', 'remove_status', 'verify_removed'], true)) {
            if ($command->operation === 'person' && $writeOk && ! $target->desired_present && $target->desired_revision) {
                DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update(['state' => 'removal_pending']);
                EnrollmentRoster::queue($target->id, $target->desired_revision, 'remove');
            }

            return true;
        }
        if ($searchOperation) {
            $search = $data['UserInfoSearch'] ?? null;
            $matches = is_array($search) ? ($search['numOfMatches'] ?? null) : null;
            $people = $search['UserInfo'] ?? [];
            if (! is_int($matches) || ! is_array($people) || ! array_is_list($people) || count($people) !== $matches || $matches > 1
                || ($matches === 1 && ($people[0]['employeeNo'] ?? null) !== $target->person_identifier)) {
                $this->fail($command, 'device_result_unverified');

                return true;
            }
            if ($command->operation === 'preflight') {
                if ($matches === 1 && ! $target->person_owned) {
                    $this->fail($command, 'unowned_device_person_conflict');
                } else {
                    EnrollmentRoster::queue($target->id, $target->desired_revision, 'person');
                }
            } elseif ($command->operation === 'verify_removed') {
                if ($matches === 0) {
                    // Reactivation may have queued a new apply while deletion was
                    // running. Its assumptions about existing person/face ownership
                    // are no longer valid; restart from an exact-ID preflight.
                    DB::table('ec_hikvision_enrollment_commands')->where('enrollment_id', $target->id)
                        ->where('state', 'queued')->update(['state' => 'superseded', 'payload_encrypted' => null, 'updated_at' => now()]);
                    DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update([
                        'person_owned' => false, 'face_owned' => false, 'removal_in_progress' => false, 'confirmed_revision' => null,
                        'state' => $target->desired_present ? ($target->desired_revision ? 'queued' : 'photo_pending') : 'removed', 'last_confirmed_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('ec_device_personnel')->where('device_id', $device->device_id)->where('student_id', $target->student_id)
                        ->where('person_identifier', $target->person_identifier)->delete();
                    if ($target->desired_present && $target->desired_revision) {
                        EnrollmentRoster::queue($target->id, $target->desired_revision, 'preflight');
                    }
                } else {
                    $this->retryCleanup($command, $target, 'verify_removed');
                }
            } elseif ($matches === 1 && ($people[0]['onlyVerify'] ?? null) !== true) {
                $this->fail($command, 'attendance_only_not_confirmed');
            } elseif ($matches === 1 && is_int($people[0]['numOfFace'] ?? null) && $people[0]['numOfFace'] >= 1) {
                if (! DeviceScope::allows($device, (int) $target->tenant_id, (int) $target->school_id)) {
                    $this->fail($command, 'school_assignment_changed');

                    return true;
                }
                DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update([
                    'confirmed_revision' => $target->desired_revision, 'state' => 'device_ready', 'error_code' => null,
                    'last_confirmed_at' => now(), 'updated_at' => now(),
                ]);
                $schoolBinding = [];
                if (DeviceScope::shared($device)) {
                    $previous = DB::table('ec_device_personnel')->where('device_id', $device->device_id)->where('student_id', $target->student_id)->first();
                    $sameSchool = $previous && (int) $previous->tenant_id === (int) $target->tenant_id && (int) $previous->school_id === (int) $target->school_id;
                    $schoolBinding['school_bound_at'] = $sameSchool && $previous->school_bound_at ? $previous->school_bound_at : now()->utc();
                }
                DB::table('ec_device_personnel')->updateOrInsert(['device_id' => $device->device_id, 'student_id' => $target->student_id], $schoolBinding + [
                    'tenant_id' => $target->tenant_id, 'school_id' => $target->school_id, 'person_identifier' => $target->person_identifier,
                    'payload_hash' => $target->desired_revision, 'sync_status' => 'acknowledged', 'last_synced_at' => now(), 'last_ack_at' => now(),
                ]);
            } else {
                $this->fail($command, 'face_not_confirmed');
            }
        } elseif ($command->operation === 'remove_status') {
            $status = $data['UserInfoDetailDeleteProcess']['status'] ?? null;
            if ($status === 'success') {
                EnrollmentRoster::queue($target->id, $command->revision, 'verify_removed');
            } elseif ($status === 'processing') {
                $this->retryCleanup($command, $target, 'remove_status');
            } else {
                $this->fail($command, 'removal_unconfirmed');
            }
        } elseif (! $writeOk) {
            $this->fail($command, $command->operation === 'face' ? 'photo_rejected_by_device' : 'device_rejected');
        } elseif ($command->operation === 'remove') {
            EnrollmentRoster::queue($target->id, $command->revision, 'remove_status');
        } elseif ($command->operation === 'person') {
            EnrollmentRoster::queue($target->id, $command->revision, 'face');
        } elseif ($command->operation === 'face') {
            if (! empty($data['FPID']) && $data['FPID'] !== $target->person_identifier) {
                $this->fail($command, 'device_result_unverified');
            } else {
                EnrollmentRoster::queue($target->id, $command->revision, 'verify');
            }
        }

        return true;
    }

    private function wire(object $command, object $target, ?object $student, ?object $photo, array $profile): array
    {
        $url = match ($command->operation) {
            'preflight', 'verify', 'verify_removed' => 'POST /ISAPI/AccessControl/UserInfo/Search?format=json',
            'person' => ($target->person_owned ? 'PUT /ISAPI/AccessControl/UserInfo/SetUp?format=json' : 'POST /ISAPI/AccessControl/UserInfo/Record?format=json'),
            'face' => ($target->face_owned ? 'PUT /ISAPI/Intelligent/FDLib/FDSetUp?format=json' : 'POST /ISAPI/Intelligent/FDLib/FaceDataRecord?format=json'),
            'remove' => 'PUT /ISAPI/AccessControl/UserInfoDetail/Delete?format=json',
            'remove_status' => 'GET /ISAPI/AccessControl/UserInfoDetail/DeleteProcess?format=json',
            default => throw new InvalidArgumentException('Unsupported enrollment operation.'),
        };
        $body = match ($command->operation) {
            'preflight', 'verify', 'verify_removed' => ['UserInfoSearchCond' => ['searchID' => $command->command_uuid,
                'searchResultPosition' => 0, 'maxResults' => 1, 'EmployeeNoList' => [['employeeNo' => $target->person_identifier]]]],
            'person' => ['UserInfo' => ['employeeNo' => $target->person_identifier, 'name' => EnrollmentRoster::name($student),
                'userType' => 'normal', 'localUIRight' => false, 'onlyVerify' => true,
                // Complete documented validity fields for application-managed,
                // non-expiring eligibility. This adds no door permissions.
                'Valid' => ['enable' => false, 'beginTime' => '2000-01-01T00:00:00',
                    'endTime' => '2037-12-31T23:59:59', 'timeType' => 'local']]],
            'remove' => ['UserInfoDetail' => ['mode' => 'byEmployeeNo', 'EmployeeNoList' => [['employeeNo' => $target->person_identifier]]]],
            default => [],
        };
        if ($command->operation === 'face') {
            $jpeg = base64_decode(Crypt::decryptString($photo->photo_encrypted), true);
            if ($jpeg === false || ! hash_equals($photo->photo_hash, hash('sha256', $jpeg))) {
                throw new InvalidArgumentException('Enrollment photo integrity mismatch.');
            }
            $boundary = 'EC'.str_replace('-', '', $command->command_uuid);
            $metadata = json_encode(['faceLibType' => 'blackFD', 'FDID' => $profile['fdid'],
                'FPID' => $target->person_identifier, 'name' => EnrollmentRoster::name($student)], JSON_THROW_ON_ERROR);
            // The supplied Value Series protocol pp367–368 names these form units faceURL and img.
            $multipart = 'Content-Type: multipart/form-data; boundary='.$boundary."\r\n\r\n";
            foreach ([['faceURL', 'application/json', $metadata, null], ['img', 'image/jpeg', $jpeg, 'portrait.jpg']] as [$name, $type, $bytes, $file]) {
                $multipart .= '--'.$boundary."\r\n";
                $multipart .= 'Content-Disposition: form-data; name="'.$name.'"'.($file ? '; filename="'.$file.'"' : '')
                    ."\r\nContent-Type: ".$type."\r\nContent-Length: ".strlen($bytes)."\r\n\r\n".$bytes."\r\n";
            }
            $multipart .= '--'.$boundary."--\r\n";

            return ['UUID' => $command->command_uuid, 'URL' => $url, 'dataFormat' => 'boundaryData', 'data' => base64_encode($multipart)];
        }

        return ['UUID' => $command->command_uuid, 'URL' => $url, 'dataFormat' => $body === [] ? 'noData' : 'jsonData',
            'data' => $body === [] ? '' : base64_encode(json_encode($body, JSON_THROW_ON_ERROR))];
    }

    private function retryCleanup(object $command, object $target, string $operation): void
    {
        $count = DB::table('ec_hikvision_enrollment_commands')->where('enrollment_id', $target->id)
            ->where('revision', $command->revision)->where('operation', $operation)->count();
        if ($count >= 5) {
            $this->fail($command, 'removal_unconfirmed');
        } else {
            EnrollmentRoster::queue($target->id, $command->revision, $operation);
        }
    }

    private function fail(object $command, string $code, bool $releaseBarrier = true): void
    {
        DB::table('ec_hikvision_enrollment_commands')->where('id', $command->id)->update([
            'state' => $releaseBarrier ? 'failed' : 'unconfirmed', 'error_code' => $code, 'updated_at' => now(),
        ]);
        DB::table('ec_hikvision_enrollments')->where('id', $command->enrollment_id)->update([
            'state' => 'needs_review', 'error_code' => $code, 'updated_at' => now(),
        ]);
    }
}
