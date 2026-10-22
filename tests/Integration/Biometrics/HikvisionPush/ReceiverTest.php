<?php

use App\Models\V2\BiometricDevice;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Biometrics\Hikvision\PushCommands;
use App\Services\Biometrics\Hikvision\PushInbox;
use App\Services\Biometrics\Hikvision\PushProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Biometrics\Hikvision\PushTerminalSimulator;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
    (require base_path('database/migrations/2026_10_10_000020_create_hikvision_push_inbox.php'))->up();
    Carbon::setTestNow('2026-10-10T12:00:00Z');
    config(['hikvision_push.enabled' => true, 'hikvision_push.allow_loopback_http' => true]);
    Http::preventStrayRequests();
    $tenant = Tenant::create(['name' => 'Push test tenant', 'slug' => 'push-test', 'status' => 'active']);
    $school = School::create(['tenant_id' => $tenant->id, 'name' => 'Push test school', 'slug' => 'push-school', 'type' => 'secondary', 'status' => 'active']);
    $device = BiometricDevice::create(['tenant_id' => $tenant->id, 'school_id' => $school->id, 'name' => 'Synthetic Hikvision',
        'device_uid' => 'SYNTHETIC-HIK-001', 'serial_number' => 'SYNTHETIC-HIK-001', 'device_type' => 'hikvision', 'status' => 'active']);
    $student = Student::create(['tenant_id' => $tenant->id, 'school_id' => $school->id,
        'student_number' => 'SIM-001', 'first_name' => 'Synthetic', 'last_name' => 'Student', 'status' => 'active']);
    DB::table('ec_device_personnel')->insert(['tenant_id' => $tenant->id, 'school_id' => $school->id,
        'device_id' => $device->id, 'student_id' => $student->id, 'person_identifier' => '0001']);
    $this->graph = compact('tenant', 'school', 'device', 'student');
    $this->pushId = app(PushProvisioner::class)->register($device->id, 'SYNTHETIC-HIK-001', 'SYNTHETIC-FIRMWARE', 'sha256', 'synthetic-gate', hkPushPassword());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('simulates login scan outage recovery command polling and logout through actual application routes', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan(), loseResponse: true)->assertOk()->assertJsonPath('0.status', 200);
    $terminal->send('CommandRequest')->assertUnauthorized();
    $terminal->connect()->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan())->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan(102, '0001', 'offline-001'))->assertOk();
    // Recreate all service instances: persisted state, not object memory, authenticates the client.
    app()->forgetInstance(\App\Services\Biometrics\Hikvision\PushReceiver::class);
    $terminal->send('CommandRequest')->assertOk()->assertJsonPath('commandNum', 0);
    $terminal->send('Logout')->assertOk();
    $terminal->send('CommandRequest')->assertUnauthorized();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(2);
    expect(DB::table('ec_hikvision_push_deliveries')->count())->toBe(2);
    foreach (['ec_attendance_events', 'ec_attendance_daily_states', 'ec_mobile_notifications', 'ec_integration_outbox_events'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(DB::table('ec_hikvision_observations')->where('delivery_state', 'held_hardware_acceptance')->count())->toBe(2);
    Http::assertNothingSent();
});

it('encrypts the platform secret at rest and never stores scan images names cards or URLs', function (): void {
    $registration = DB::table('ec_hikvision_push_devices')->first();
    expect($registration->credential_encrypted)->not->toContain(hkPushPassword());
    expect(Crypt::decryptString($registration->credential_encrypted))->toBe(hkPushPassword());
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan(overrides: ['name' => 'DO-NOT-STORE', 'cardNo' => 'SECRET-CARD',
        'pictureURL' => 'http://127.0.0.1/private', 'faceImage' => 'IMAGE-DATA']))->assertOk();
    $saved = json_encode(DB::table('ec_hikvision_observations')->first());
    expect($saved)->not->toContain('DO-NOT-STORE', 'SECRET-CARD', 'http:', 'IMAGE-DATA');
    expect(json_decode(DB::table('ec_hikvision_observations')->first()->metadata, true)['employee_no'])->toBe('0001');
});

it('does not automatically register an unknown terminal or assign it to a school', function (): void {
    hkPushTerminal($this, 'UNKNOWN')->connect()->assertUnauthorized();
    expect(DB::table('ec_hikvision_push_devices')->count())->toBe(1);
    expect(DB::table('ec_hikvision_push_sessions')->count())->toBe(0);
});

it('can discover enrollment capabilities through authenticated read-only command polling', function (): void {
    $uuid = app(PushCommands::class)->queue($this->pushId, PushCommands::ENROLLMENT_DISCOVERY_URLS[0]);
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $poll = $terminal->send('CommandRequest')->assertOk();
    expect($poll->json('commandList.0.URL'))->toBe(PushCommands::ENROLLMENT_DISCOVERY_URLS[0]);
    $terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [[
        'UUID' => $uuid, 'data' => base64_encode('{"maxRecordNum":1500,"supportFunction":"get,post,setUp","private":"SECRET"}'),
    ]]])->assertOk();
    $row = DB::table('ec_hikvision_push_commands')->where('command_uuid', $uuid)->sole();
    expect($row->state)->toBe('completed');
    expect(json_decode($row->result_summary, true))->toHaveKey('maxRecordNum', 1500);
    expect($row->result_summary)->not->toContain('SECRET');
});

it('honours global and per-device disable switches', function (): void {
    config(['hikvision_push.enabled' => false]);
    hkPushTerminal($this)->connect()->assertStatus(503);
    config(['hikvision_push.enabled' => true]);
    DB::table('ec_hikvision_push_devices')->update(['enabled' => false]);
    hkPushTerminal($this)->connect()->assertUnauthorized();
});

it('refuses conflicting or unapproved provisioning without replacing existing credentials', function (string $variant): void {
    $saved = DB::table('ec_hikvision_push_devices')->first();
    $profile = $variant === 'unpinned-sha1' ? 'sha1-3.16.1' : 'sha256';
    $serial = $variant === 'serial-mismatch' ? 'SYNTHETIC-OTHER' : 'SYNTHETIC-HIK-001';
    if ($variant === 'inactive-device') {
        DB::table('ec_biometric_devices')->update(['status' => 'inactive']);
    } elseif ($variant === 'inactive-school') {
        DB::table('ec_schools')->update(['status' => 'inactive']);
    } elseif ($variant === 'inactive-tenant') {
        DB::table('ec_tenants')->update(['status' => 'inactive']);
    }
    expect(fn () => app(PushProvisioner::class)->register($this->graph['device']->id, $serial,
        'SYNTHETIC-FIRMWARE', $profile, 'other-user', 'SYNTHETIC-OTHER-password-2026-10'))->toThrow(InvalidArgumentException::class);
    expect(DB::table('ec_hikvision_push_devices')->count())->toBe(1);
    expect(DB::table('ec_hikvision_push_devices')->first()->credential_encrypted)->toBe($saved->credential_encrypted);
})->with(['already-registered', 'serial-mismatch', 'unpinned-sha1', 'inactive-device', 'inactive-school', 'inactive-tenant']);

it('accepts a full batch and repeated uploads without multiplying observations', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $entries = [];
    for ($index = 0; $index < 20; $index++) {
        $entries[] = PushTerminalSimulator::scan(101 + $index, '0001', 'batch-'.$index)['eventList'][0];
    }
    $body = ['eventNum' => 20, 'eventList' => $entries];
    $terminal->send('Event', $body)->assertOk()->assertJsonCount(20)->assertJsonPath('19.UUID', 'batch-19');
    $terminal->send('Event', $body)->assertOk()->assertJsonCount(20);
    $terminal->send('Event', ['eventNum' => 0, 'eventList' => []])->assertOk()->assertExactJson([]);
    expect(DB::table('ec_hikvision_observations')->count())->toBe(20);
    expect(DB::table('ec_hikvision_push_deliveries')->count())->toBe(20);
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
});

it('requires TLS and does not trust a spoofed forwarded HTTPS header', function (): void {
    config(['hikvision_push.allow_loopback_http' => false]);
    $this->postJson(hkPushUrl('AuthInfo'), ['data' => []], ['X-Forwarded-Proto' => 'https'])->assertStatus(426);
    $this->postJson('https://localhost'.hkPushUrl('AuthInfo'), ['data' => []])->assertOk();
});

it('does not accept public HTTP even when the development exception is enabled', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->postJson(hkPushUrl('AuthInfo'), ['data' => []])->assertStatus(426);
});

it('rejects the wrong password username and automatic SHA1 fallback', function (string $username, string $password, string $profile): void {
    hkPushTerminal($this, username: $username, password: $password, profile: $profile)->connect()->assertUnauthorized();
    expect(DB::table('ec_hikvision_push_sessions')->count())->toBe(0);
})->with([
    ['synthetic-gate', 'wrong-password', 'sha256'],
    ['wrong-user', hkPushPassword(), 'sha256'],
    ['synthetic-gate', hkPushPassword(), 'sha1-3.16.1'],
]);

it('supports the explicitly pinned documented legacy profile without downgrading other firmware', function (): void {
    DB::table('ec_hikvision_push_devices')->update(['firmware' => 'V3.16.1_build250320', 'auth_profile' => 'sha1-3.16.1']);
    hkPushTerminal($this, profile: 'sha1-3.16.1')->connect()->assertOk();
});

it('locks repeated login failures even if AuthInfo is refreshed every attempt', function (): void {
    $terminal = hkPushTerminal($this, password: 'wrong-password');
    for ($i = 0; $i < 5; $i++) {
        $response = $terminal->connect()->assertUnauthorized();
    }
    $response->assertJsonPath('data.lockStatus', 'lock');
    hkPushTerminal($this)->connect()->assertUnauthorized();
    Carbon::setTestNow(now()->addSeconds(61));
    hkPushTerminal($this)->connect()->assertOk();
});

it('expires authentication and rolling challenges at the documented deadline', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    Carbon::setTestNow(now()->addSeconds(15));
    $terminal->send('Event', PushTerminalSimulator::scan())->assertUnauthorized();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(0);
    $terminal->connect()->assertOk();
});

it('rejects stale rolling challenges after a successful concurrent or replayed request', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('CommandRequest', loseResponse: true)->assertOk();
    $terminal->send('CommandRequest')->assertUnauthorized();
});

it('rolls back events and the next challenge on storage failure before acknowledgement', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    app()->instance(PushInbox::class, new class extends PushInbox
    {
        public function capture(object $device, array $entry): array
        {
            parent::capture($device, $entry);
            throw new RuntimeException('SYNTHETIC STORAGE FAILURE secret');
        }
    });
    $before = DB::table('ec_hikvision_push_sessions')->first()->challenge;
    $response = $terminal->send('Event', PushTerminalSimulator::scan())->assertStatus(503);
    expect($response->headers->has('My-Custom-Challenge'))->toBeFalse();
    expect($response->getContent())->not->toContain('secret', 'SYNTHETIC STORAGE');
    expect(DB::table('ec_hikvision_observations')->count())->toBe(0);
    expect(DB::table('ec_hikvision_push_deliveries')->count())->toBe(0);
    expect(DB::table('ec_hikvision_push_sessions')->first()->challenge)->toBe($before);
});

it('deduplicates different upload UUIDs that contain the same underlying observation', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan())->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan(uuid: 'scan-other'))->assertOk();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(1);
    expect(DB::table('ec_hikvision_push_deliveries')->count())->toBe(2);
});

it('quarantines reused upload UUIDs or observation identities with different content', function (string $uuid): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan())->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan(person: 'changed-person', uuid: $uuid))->assertOk()->assertJsonPath('0.status', 409);
    expect(DB::table('ec_hikvision_observations')->first()->status)->toBe('held_content_conflict');
    expect(DB::table('ec_hikvision_push_conflicts')->count())->toBe(1);
})->with(['scan-001', 'new-upload']);

it('holds failed remote verification unmapped and unknown-code events', function (array $overrides, string $person, string $expected): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    // Override the default fixture without the array-union precedence used by scan().
    $scan = PushTerminalSimulator::scan(person: $person);
    $data = json_decode(base64_decode($scan['eventList'][0]['data']), true);
    $data['AccessControllerEvent'] = array_replace($data['AccessControllerEvent'], $overrides);
    $scan['eventList'][0]['data'] = base64_encode(json_encode($data));
    $terminal->send('Event', $scan)->assertOk();
    expect(DB::table('ec_hikvision_observations')->first()->status)->toBe($expected);
})->with([
    [['subEventType' => 76], '0001', 'held_authentication_failed'],
    [['remoteCheck' => true], '0001', 'held_remote_verification'],
    [[], 'UNMAPPED', 'held_person_mapping'],
    [['subEventType' => 999], '0001', 'held_unverified_code'],
]);

it('holds future clocks but preserves valid old offline occurrence time', function (string $time, string $expected): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $scan = PushTerminalSimulator::scan();
    $data = json_decode(base64_decode($scan['eventList'][0]['data']), true);
    $data['dateTime'] = $time;
    $scan['eventList'][0]['data'] = base64_encode(json_encode($data));
    $terminal->send('Event', $scan)->assertOk();
    expect(DB::table('ec_hikvision_observations')->first()->status)->toBe($expected);
})->with([
    ['2026-10-10T14:00:00+01:00', 'held_future_clock'],
    ['2026-10-09T07:20:00+01:00', 'recognition_candidate'],
]);

it('keeps invalid or ambiguous times as hash-only receipts rather than guessing', function (string $time): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $scan = PushTerminalSimulator::scan();
    $data = json_decode(base64_decode($scan['eventList'][0]['data']), true);
    $data['dateTime'] = $time;
    $scan['eventList'][0]['data'] = json_encode($data);
    $terminal->send('Event', $scan)->assertOk();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(0);
    expect(DB::table('ec_hikvision_push_deliveries')->first()->status)->toBe('held_invalid_payload');
})->with(['2026-10-10T07:20:00', '2026-02-30T07:20:00+01:00', '2026-10-10T07:20:00+14:01']);

it('decodes namespaced XML and plain or Base64 JSON without persisting XML extras', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $xml = '<EventNotificationAlert xmlns="http://www.isapi.org/ver20/XMLSchema"><eventType>AccessControllerEvent</eventType>'
        .'<dateTime>2026-10-10T07:20:00+01:00</dateTime><AccessControllerEvent><majorEventType>5</majorEventType>'
        .'<subEventType>75</subEventType><serialNo>101</serialNo><employeeNoString>0001</employeeNoString>'
        .'<name>DO-NOT-STORE</name></AccessControllerEvent></EventNotificationAlert>';
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [['UUID' => 'xml-001', 'dataFormat' => 'xmlData', 'data' => base64_encode($xml)]]])->assertOk();
    $terminal->send('Event', PushTerminalSimulator::scan())->assertOk();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(1);
});

it('rejects external XML entities and never fetches supplied URLs', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $xml = '<!DOCTYPE a [<!ENTITY x SYSTEM "file:///C:/Users/NTech/secret">]><a>&x;</a>';
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [['UUID' => 'xxe-001', 'dataFormat' => 'xmlData', 'data' => base64_encode($xml)]]])->assertOk();
    expect(DB::table('ec_hikvision_push_deliveries')->first()->status)->toBe('held_invalid_payload');
    Http::assertNothingSent();
});

it('does not interpret an image-containing multipart envelope as attendance', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [['UUID' => 'image-001', 'dataFormat' => 'boundaryData', 'data' => 'SYNTHETIC-IMAGE']]])->assertOk();
    expect(DB::table('ec_hikvision_push_deliveries')->first()->status)->toBe('held_invalid_payload');
    expect(DB::table('ec_hikvision_observations')->count())->toBe(0);
});

it('extracts one multipart access event and discards binary parts and their filenames', function (bool $lengths): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $metadata = base64_decode(PushTerminalSimulator::scan()['eventList'][0]['data']);
    $entry = hkPushMultipart([
        ['application/json', $metadata, 'event.json'],
        ['image/jpeg', "DO-NOT-STORE-IMAGE\0\xff", '../../DO-NOT-WRITE.jpg'],
    ], lengths: $lengths);
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [$entry]])->assertOk()->assertJsonPath('0.status', 200);
    $terminal->send('Event', PushTerminalSimulator::scan())->assertOk();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(1);
    expect(DB::table('ec_hikvision_push_deliveries')->count())->toBe(2);
    $saved = json_encode(DB::table('ec_hikvision_observations')->first());
    expect($saved)->not->toContain('DO-NOT-STORE-IMAGE', 'DO-NOT-WRITE', 'event.json');
    Http::assertNothingSent();
})->with([true, false]);

it('accepts bounded empty multipart preamble lines without saving media', function (int $blankLines): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $metadata = base64_decode(PushTerminalSimulator::scan()['eventList'][0]['data']);
    $entry = hkPushMultipart([['application/json', $metadata, 'event.json'], ['image/jpeg', 'DO-NOT-STORE-IMAGE', 'face.jpg']]);
    $text = base64_decode($entry['data']);
    $end = strpos($text, "\r\n\r\n") + 4;
    $entry['data'] = base64_encode(substr($text, 0, $end).str_repeat("\r\n", $blankLines).substr($text, $end));
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [$entry]])->assertOk();
    $saved = DB::table('ec_hikvision_observations')->sole();
    expect($saved->status)->toBe('recognition_candidate');
    expect($saved->delivery_state)->toBe('held_hardware_acceptance');
    expect($saved->metadata)->not->toContain('DO-NOT-STORE', 'face.jpg');
})->with([1, 2, 8]);

it('requires explicit test-runtime opt-in for the observed open final multipart marker', function (bool $enabled): void {
    config(['hikvision_push.allow_open_multipart_end' => $enabled]);
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $metadata = base64_decode(PushTerminalSimulator::scan()['eventList'][0]['data']);
    $entry = hkPushMultipart([['application/json', $metadata, 'event.json'], ['image/jpeg', 'DO-NOT-STORE-IMAGE', 'face.jpg']]);
    $text = str_replace("--SYNTHETIC-HK-BOUNDARY--\r\n", "--SYNTHETIC-HK-BOUNDARY\r\n", base64_decode($entry['data']));
    $end = strpos($text, "\r\n\r\n") + 4;
    $entry['data'] = base64_encode(substr($text, 0, $end)."\r\n".substr($text, $end));
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [$entry]])->assertOk();
    expect(DB::table('ec_hikvision_observations')->count())->toBe($enabled ? 1 : 0);
    expect(DB::table('ec_hikvision_push_deliveries')->value('status'))->toBe($enabled ? 'recognition_candidate' : 'held_invalid_payload');
    if ($enabled) {
        $saved = DB::table('ec_hikvision_observations')->sole();
        expect($saved->delivery_state)->toBe('held_hardware_acceptance');
        expect($saved->metadata)->not->toContain('DO-NOT-STORE', 'face.jpg');
    }
})->with([true, false]);

it('keeps incomplete ambiguous and malformed open-end variants held even with test compatibility', function (string $variant): void {
    config(['hikvision_push.allow_open_multipart_end' => true]);
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $metadata = base64_decode(PushTerminalSimulator::scan()['eventList'][0]['data']);
    $parts = [['application/json', $metadata, 'event.json']];
    if ($variant === 'ambiguous') {
        $parts[] = ['application/json', $metadata, 'second.json'];
    }
    $entry = hkPushMultipart($parts);
    $text = str_replace("--SYNTHETIC-HK-BOUNDARY--\r\n", "--SYNTHETIC-HK-BOUNDARY\r\n", base64_decode($entry['data']));
    $text = match ($variant) {
        'missing-marker' => substr($text, 0, -strlen("--SYNTHETIC-HK-BOUNDARY\r\n")),
        'bad-length' => str_replace('Content-Length: '.strlen($metadata), 'Content-Length: 1', $text),
        'trailing-junk' => $text.'JUNK',
        default => $text,
    };
    $entry['data'] = base64_encode($text);
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [$entry]])->assertOk();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(0);
    expect(DB::table('ec_hikvision_push_deliveries')->value('status'))->toBe('held_invalid_payload');
})->with(['ambiguous', 'missing-marker', 'bad-length', 'trailing-junk']);

it('holds multipart uploads that are incomplete ambiguous or have repeated headers', function (string $variant): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $metadata = base64_decode(PushTerminalSimulator::scan()['eventList'][0]['data']);
    $parts = [['application/json', $metadata, 'event.json']];
    if ($variant === 'ambiguous') {
        $parts[] = ['application/json', $metadata, 'second.json'];
    }
    $entry = hkPushMultipart($parts);
    $text = base64_decode($entry['data']);
    if ($variant === 'truncated') {
        $text = substr($text, 0, -10);
    } elseif ($variant === 'repeated-header') {
        $text = str_replace('Content-Type: application/json', "Content-Type: application/json\r\nContent-Type: image/jpeg", $text);
    } elseif ($variant === 'bad-length') {
        $text = str_replace('Content-Length: '.strlen($metadata), 'Content-Length: 1', $text);
    } elseif (in_array($variant, ['too-many-blank-lines', 'lone-lf', 'preamble-junk'], true)) {
        $end = strpos($text, "\r\n\r\n") + 4;
        $prefix = match ($variant) {
            'too-many-blank-lines' => str_repeat("\r\n", 9), 'lone-lf' => "\n", default => 'UNTRUSTED-PREAMBLE',
        };
        $text = substr($text, 0, $end).$prefix.substr($text, $end);
    }
    $entry['data'] = base64_encode($text);
    $terminal->send('Event', ['eventNum' => 1, 'eventList' => [$entry]])->assertOk()->assertJsonPath('0.status', 200);
    expect(DB::table('ec_hikvision_observations')->count())->toBe(0);
    expect(DB::table('ec_hikvision_push_deliveries')->first()->status)->toBe('held_invalid_payload');
})->with(['truncated', 'ambiguous', 'repeated-header', 'bad-length', 'too-many-blank-lines', 'lone-lf', 'preamble-junk']);

it('rejects bounded batch violations without acknowledging or consuming the challenge', function (array $body): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('Event', $body)->assertStatus(400);
    $terminal->send('CommandRequest')->assertOk();
    expect(DB::table('ec_hikvision_push_deliveries')->count())->toBe(0);
})->with([
    [['eventNum' => 21, 'eventList' => []]],
    [['eventNum' => 1, 'eventList' => []]],
    [['eventNum' => '0', 'eventList' => []]],
    [['eventNum' => 1, 'eventList' => [['UUID' => '../bad', 'dataFormat' => 'jsonData', 'data' => '{}']]]],
]);

it('isolates students and registration bindings across schools and tenants', function (): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $otherTenant = Tenant::create(['name' => 'Other tenant', 'slug' => 'other-tenant', 'status' => 'active']);
    $otherSchool = School::create(['tenant_id' => $otherTenant->id, 'name' => 'Other school', 'slug' => 'other-school', 'type' => 'secondary', 'status' => 'active']);
    DB::table('ec_device_personnel')->update(['tenant_id' => $otherTenant->id]);
    $terminal->send('Event', PushTerminalSimulator::scan())->assertOk();
    expect(DB::table('ec_hikvision_observations')->first()->status)->toBe('held_person_mapping');
    DB::table('ec_hikvision_push_devices')->update(['school_id' => $otherSchool->id]);
    $terminal->send('CommandRequest')->assertUnauthorized();
});

it('queues read-only commands durably retries their unchanged UUIDs and saves only a result summary', function (): void {
    $uuid = app(PushCommands::class)->queue($this->pushId, 'GET /ISAPI/System/deviceInfo');
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('CommandRequest')->assertOk()->assertJsonPath('commandList.0.UUID', $uuid);
    Carbon::setTestNow(now()->addSeconds(31));
    $terminal->connect()->assertOk();
    $terminal->send('CommandRequest')->assertOk()->assertJsonPath('commandList.0.UUID', $uuid);
    $body = ['commandNum' => 1, 'commandList' => [['UUID' => $uuid, 'dataFormat' => 'xmlData',
        'data' => base64_encode('<DeviceInfo><model>DS-K1T342MFWX-E1</model><deviceName>DO-NOT-STORE</deviceName></DeviceInfo>')]]];
    $terminal->send('CommandResult', $body)->assertOk()->assertJsonPath('isPendingCommand', false);
    $terminal->send('CommandResult', $body)->assertOk();
    $saved = DB::table('ec_hikvision_push_commands')->first();
    expect($saved->state)->toBe('completed');
    expect($saved->attempts)->toBe(2);
    expect($saved->result_summary)->not->toContain('DO-NOT-STORE');
    $terminal->send('CommandRequest')->assertOk()->assertJsonPath('commandNum', 0);
});

it('does not allow enrollment door-control firmware or arbitrary commands', function (string $url): void {
    app(PushCommands::class)->queue($this->pushId, $url);
})->with(['PUT /ISAPI/System/updateFirmware', 'PUT /ISAPI/AccessControl/RemoteControl/door/1',
    'POST /ISAPI/AccessControl/UserInfo/Record?format=json', 'GET http://example.com/',
    'PUT /ISAPI/System/Network/PUSHCfg?format=json', 'GET /ISAPI/System/Network/PUSHCfg?format=json&extra=1'])->throws(InvalidArgumentException::class);

it('reads certificate flags without retaining configuration credentials or inferring missing booleans', function (string $variant): void {
    $settings = ['enabled' => true, 'protocolType' => 'https', 'status' => 'online',
        'notCertifiedEnabled' => false, 'username' => 'DO-NOT-STORE-USER', 'password' => 'DO-NOT-STORE-PASSWORD',
        'hostName' => 'DO-NOT-STORE-HOST', 'serialNumber' => 'DO-NOT-STORE-SERIAL', 'model' => 'DO-NOT-STORE-MODEL'];
    if ($variant !== 'missing') {
        $settings['httpsCertificateEnabled'] = match ($variant) {
            'false' => false, 'string' => 'true', default => true,
        };
    }
    $document = $variant === 'wrapped' ? ['PUSHCfg' => $settings] : $settings;
    $uuid = app(PushCommands::class)->queue($this->pushId, PushCommands::SECURITY_URLS[0]);
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('CommandRequest')->assertOk()->assertJsonPath('commandList.0.URL', PushCommands::SECURITY_URLS[0]);
    $reply = ['commandNum' => 1, 'commandList' => [['UUID' => $uuid,
        'data' => base64_encode(json_encode($document, JSON_THROW_ON_ERROR))]]];
    $terminal->send('CommandResult', $reply)->assertOk();
    $saved = DB::table('ec_hikvision_push_commands')->where('command_uuid', $uuid)->sole();
    $summary = json_decode($saved->result_summary, true);
    expect($saved->state)->toBe('completed');
    expect($saved->result_summary)->not->toContain('DO-NOT-STORE', 'username', 'password', 'hostName', 'serialNumber', 'model');
    expect($summary['configuration_recognized'])->toBeTrue();
    expect($summary['notCertifiedEnabled'])->toBeFalse();
    expect($summary['certificate_setting_present'])->toBe($variant !== 'missing');
    expect(array_key_exists('httpsCertificateEnabled', $summary))->toBe(! in_array($variant, ['missing', 'string'], true));
    if (array_key_exists('httpsCertificateEnabled', $summary)) {
        expect($summary['httpsCertificateEnabled'])->toBe($variant !== 'false');
    }
})->with(['true', 'false', 'missing', 'string', 'wrapped']);

it('does not treat a security configuration error as evidence about certificate verification', function (string $payload): void {
    $uuid = app(PushCommands::class)->queue($this->pushId, PushCommands::SECURITY_URLS[0]);
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('CommandRequest')->assertOk();
    $terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [['UUID' => $uuid,
        'data' => base64_encode($payload)]]])->assertOk();
    $saved = DB::table('ec_hikvision_push_commands')->where('command_uuid', $uuid)->sole();
    $summary = json_decode($saved->result_summary, true);
    expect($saved->state)->toBe('failed');
    expect($summary['configuration_recognized'])->toBeFalse();
    expect($saved->result_summary)->not->toContain('httpsCertificateEnabled', 'DO-NOT-STORE');
})->with([
    '{"statusCode":4,"statusString":"DO-NOT-STORE","httpsCertificateEnabled":false}',
    '<ResponseStatus><statusCode>4</statusCode><statusString>DO-NOT-STORE</statusString></ResponseStatus>',
]);

it('retains only typed certificate capability facts and treats unsupported inquiry separately from device support', function (string $variant): void {
    $payload = match ($variant) {
        'xml-options' => '<PUSHCfgCap><httpsCertificateEnabled opt="true,false">false</httpsCertificateEnabled><password>DO-NOT-STORE</password></PUSHCfgCap>',
        'json-options' => '{"PUSHCfgCap":{"httpsCertificateEnabled":{"@opt":"true,false"},"password":"DO-NOT-STORE"}}',
        'boolean-array' => '{"httpsCertificateEnabled":{"opt":[true,false]},"username":"DO-NOT-STORE"}',
        'missing' => '{"enabled":{"opt":[true,false]},"password":"DO-NOT-STORE"}',
        'invalid-option' => '{"httpsCertificateEnabled":{"opt":"true,DO-NOT-STORE"}}',
        'xml-error' => '<ResponseStatus><statusCode>4</statusCode><subStatusCode>invalidOperation</subStatusCode><statusString>DO-NOT-STORE</statusString></ResponseStatus>',
        'json-error' => '{"statusCode":4,"subStatusCode":"notSupport","statusString":"DO-NOT-STORE","httpsCertificateEnabled":true}',
    };
    $uuid = app(PushCommands::class)->queue($this->pushId, PushCommands::SECURITY_CAPABILITY_URLS[0]);
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('CommandRequest')->assertOk();
    $terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [['UUID' => $uuid,
        'data' => base64_encode($payload)]]])->assertOk();
    $saved = DB::table('ec_hikvision_push_commands')->where('command_uuid', $uuid)->sole();
    $summary = json_decode($saved->result_summary, true);
    $error = in_array($variant, ['xml-error', 'json-error'], true);
    expect($saved->state)->toBe($error ? 'failed' : 'completed');
    expect($summary['certificate_field_present'])->toBe(! $error && $variant !== 'missing');
    expect($saved->result_summary)->not->toContain('DO-NOT-STORE', 'password', 'username', 'statusString');
    if (in_array($variant, ['xml-options', 'json-options', 'boolean-array'], true)) {
        expect($summary['certificate_boolean_options'])->toBe([false, true]);
    }
    if ($error) {
        expect($summary['response_status_code'])->toBe(4);
        expect($summary)->not->toHaveKey('reported_capability_boolean');
    }
    if (in_array($variant, ['missing', 'invalid-option'], true)) {
        expect($summary)->not->toHaveKey('certificate_boolean_options');
    }
})->with(['xml-options', 'json-options', 'boolean-array', 'missing', 'invalid-option', 'xml-error', 'json-error']);

it('rejects ambiguous repeated certificate capability fields without completing the command', function (): void {
    $uuid = app(PushCommands::class)->queue($this->pushId, PushCommands::SECURITY_CAPABILITY_URLS[0]);
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('CommandRequest')->assertOk();
    $terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [['UUID' => $uuid,
        'data' => base64_encode('<PUSHCfgCap><httpsCertificateEnabled>true</httpsCertificateEnabled><httpsCertificateEnabled>false</httpsCertificateEnabled></PUSHCfgCap>')]]])->assertStatus(400);
    expect(DB::table('ec_hikvision_push_commands')->where('command_uuid', $uuid)->value('state'))->toBe('sent');
});

it('accepts supplier-documented missing command formats only for valid structured replies', function (string $payload): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $uuid = app(PushCommands::class)->queue($this->pushId, 'GET /ISAPI/System/deviceInfo');
    $terminal->send('CommandRequest')->assertOk();
    $body = ['commandNum' => 1, 'commandList' => [['UUID' => $uuid, 'data' => base64_encode($payload)]]];
    $terminal->send('CommandResult', $body)->assertOk()->assertJsonPath('isPendingCommand', false);
    $terminal->send('CommandResult', $body)->assertOk();
    $command = DB::table('ec_hikvision_push_commands')->where('command_uuid', $uuid)->sole();
    expect($command->state)->toBe('completed');
    expect(json_decode($command->result_summary, true)['model'])->toBe('SYNTHETIC-MODEL');
})->with([
    '<DeviceInfo><model>SYNTHETIC-MODEL</model></DeviceInfo>',
    '{"model":"SYNTHETIC-MODEL"}',
]);

it('rejects malformed or ambiguous replies without a format and does not advance their command', function (string $payload): void {
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $uuid = app(PushCommands::class)->queue($this->pushId, 'GET /ISAPI/System/deviceInfo');
    $terminal->send('CommandRequest')->assertOk();
    $terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [['UUID' => $uuid,
        'data' => base64_encode($payload)]]])->assertStatus(400);
    $command = DB::table('ec_hikvision_push_commands')->where('command_uuid', $uuid)->sole();
    expect($command->state)->toBe('sent');
    expect($command->result_hash)->toBeNull();
})->with(['', 'arbitrary binary', '[]', '<malformed', '{malformed',
    '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///private">]><x>&e;</x>']);

it('rejects command results belonging to another device or an unsent command', function (): void {
    $uuid = app(PushCommands::class)->queue($this->pushId, 'GET /ISAPI/System/deviceInfo');
    $terminal = hkPushTerminal($this);
    $terminal->connect()->assertOk();
    $terminal->send('CommandResult', ['commandNum' => 1, 'commandList' => [['UUID' => $uuid, 'dataFormat' => 'jsonData', 'data' => '{}']]])->assertStatus(400);
    expect(DB::table('ec_hikvision_push_commands')->first()->state)->toBe('queued');
});

it('rejects malformed JSON wrong content type unknown routes and unsupported encryption query', function (): void {
    $this->call('POST', hkPushUrl('AuthInfo'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad')->assertStatus(400);
    $this->call('POST', hkPushUrl('AuthInfo'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}')->assertStatus(415);
    $this->postJson(hkPushUrl('AuthInfo').'?security=3', ['data' => []])->assertStatus(400);
    $this->postJson(hkPushUrl('Anything'), [])->assertNotFound();
    $this->get(hkPushUrl('AuthInfo'))->assertStatus(405);
});

function hkPushPassword(): string
{
    return 'SYNTHETIC-ONLY-password-2026-10';
}

function hkPushUrl(string $operation, string $serial = 'SYNTHETIC-HIK-001'): string
{
    return '/iot/'.$serial.'/global/0-global/model/service/operate/PUSH/'.$operation;
}

function hkPushTerminal(object $test, string $serial = 'SYNTHETIC-HIK-001', string $username = 'synthetic-gate',
    string $password = 'SYNTHETIC-ONLY-password-2026-10', string $profile = 'sha256'): PushTerminalSimulator
{
    return new PushTerminalSimulator(fn (string $operation, array $body, array $headers) => $test->postJson(hkPushUrl($operation, $serial), $body, $headers),
        $username, $password, $profile);
}

function hkPushMultipart(array $parts, bool $lengths = true): array
{
    $boundary = 'SYNTHETIC-HK-BOUNDARY';
    $text = "HTTP/1.1 200 OK\r\nContent-Type: multipart/form-data; boundary=\"".$boundary."\"\r\n\r\n";
    foreach ($parts as [$type, $content, $filename]) {
        $text .= '--'.$boundary."\r\nContent-Type: ".$type."\r\nContent-Disposition: form-data; filename=\"".$filename."\"\r\n";
        if ($lengths) {
            $text .= 'Content-Length: '.strlen($content)."\r\n";
        }
        $text .= "\r\n".$content."\r\n";
    }
    $text .= '--'.$boundary."--\r\n";

    return ['UUID' => 'multipart-001', 'dataFormat' => 'boundaryData', 'data' => base64_encode($text)];
}
