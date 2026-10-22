<?php

use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\OutboxDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20T18:00:00Z');
    $this->gate = GateFixture::boot();
    Queue::fake();
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

function gateMirrorAck($request)
{
    $body = json_decode($request->body(), true);
    $event = $body['events'][0];

    return Http::response(['accepted' => [$event['event_id'].':'.$event['revision']], 'duplicates' => []]);
}

it('delivers the signed frozen gate contract without the legacy endpoint or private enrollment fields', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    Http::fake(['https://eduadmin.test/api/v1/integrations/edu-connect/gate-events' => fn ($r) => gateMirrorAck($r)]);
    expect(app(OutboxDispatcher::class)->work())->toBe(['sent' => 1, 'skipped' => 1, 'retry' => 0, 'held' => 0]);
    Http::assertSent(function ($r): bool {
        $body = json_decode($r->body(), true);
        $signature = 'sha256='.hash_hmac('sha256', $r->header('X-Edu-Connect-Timestamp')[0].'.'.$r->body(), 'SYNTHETIC-SECRET');

        return $r->hasHeader('Authorization', 'Bearer SYNTHETIC-TOKEN') && $r->hasHeader('X-Edu-Connect-Signature', $signature)
            && $body['events'][0]['student_id'] === 2934 && $body['events'][0]['school_id'] === 26
            && ! isset($body['events'][0]['timezone'], $body['events'][0]['photo'], $body['events'][0]['tenant_id']);
    });
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('retries a lost acknowledgement idempotently and keeps response bodies out of durable errors', function (): void {
    config(['gate.parent_delivery_enabled' => false]);
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    $key = DB::table('ec_gate_movements')->value('event_id').':1';
    Http::fake(['*' => Http::sequence()->push('DO-NOT-RETAIN-PRIVATE-BODY', 503)->push(['accepted' => [], 'duplicates' => [$key]])]);
    expect(app(OutboxDispatcher::class)->work()['retry'])->toBe(1);
    expect(DB::table('ec_gate_outbox')->where('destination', 'edu_admin')->value('last_error'))->toBe('remote_unavailable');
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    Carbon::setTestNow(now()->addSeconds(16));
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(1);
    expect(DB::table('ec_gate_movements')->count())->toBe(1);
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(1);
    expect(json_encode(DB::table('ec_gate_outbox')->get()))->not->toContain('DO-NOT-RETAIN');
});

it('requires the exact event revision acknowledgement rather than a generic HTTP success', function (): void {
    config(['gate.parent_delivery_enabled' => false]);
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    Http::fake(['*' => Http::response(['success' => true])]);
    expect(app(OutboxDispatcher::class)->work()['retry'])->toBe(1);
    expect(DB::table('ec_gate_outbox')->where('destination', 'edu_admin')->value('last_error'))->toBe('invalid_acknowledgement');
});

it('does not skip a rejected revision to deliver a later correction', function (): void {
    config(['gate.parent_delivery_enabled' => false]);
    $id = GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_hikvision_observations')->where('id', $id)->update(['status' => 'held_content_conflict']);
    app(DayProjector::class)->work();
    Http::fake(['*' => Http::response([], 409)]);
    expect(app(OutboxDispatcher::class)->work()['held'])->toBe(1);
    expect(DB::table('ec_gate_outbox')->where('destination', 'edu_admin')->where('revision', 2)->value('attempts'))->toBe(0);
    Http::assertSentCount(1);
});

it('refuses reassigned origins bindings and source scope without contacting another destination', function (string $change): void {
    config(['gate.parent_delivery_enabled' => false]);
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    match ($change) {
        'origin' => $this->gate['connection']->update(['base_url' => 'https://other.test']),
        'binding' => DB::table('ec_gate_mirror_bindings')->update(['source_id' => '71b8a962-1020-4026-a000-000000000099']),
        'scope' => $this->gate['connection']->update(['enabled_school_ids' => []]),
    };
    $stats = app(OutboxDispatcher::class)->work();
    expect($stats['held'] + $stats['retry'])->toBe(1);
    Http::assertNothingSent();
})->with(['origin', 'binding', 'scope']);

it('honours disabled delivery and recovers a crashed lease without two concurrent sends', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    config(['gate.parent_delivery_enabled' => false, 'gate.mirror_delivery_enabled' => false]);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    config(['gate.mirror_delivery_enabled' => true]);
    DB::table('ec_gate_outbox')->where('destination', 'edu_admin')->update(['status' => 'processing', 'lease_until' => now()->timestamp + 90]);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    Carbon::setTestNow(now()->addSeconds(91));
    Http::fake(['*' => fn ($r) => gateMirrorAck($r)]);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(1);
    Http::assertSentCount(1);
});

it('creates one in-app parent notification per event revision without dispatching push', function (): void {
    config(['gate.mirror_delivery_enabled' => false]);
    GateFixture::parent($this->gate);
    $id = GateFixture::scan($this->gate, 1, '07:30:00');
    GateFixture::scan($this->gate, 2, '07:30:10');
    app(DayProjector::class)->work();
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(1);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(1);
    $notice = DB::table('ec_mobile_notifications')->sole();
    expect($notice->channel)->toBe('in_app');
    expect(json_decode($notice->data, true)['source'])->toBe('gate');
    DB::table('ec_hikvision_observations')->where('id', $id)->update(['status' => 'held_content_conflict']);
    app(DayProjector::class)->work();
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(1);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(2);
    expect(DB::table('ec_gate_parent_deliveries')->count())->toBe(2);
    expect(DB::table('ec_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not send old records to a newly linked parent or a revoked original link', function (): void {
    config(['gate.mirror_delivery_enabled' => false]);
    $first = GateFixture::parent($this->gate);
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_parent_student_links')->where('parent_account_id', $first->id)->update(['status' => 'revoked']);
    GateFixture::parent($this->gate, '2');
    expect(app(OutboxDispatcher::class)->work()['skipped'])->toBe(1);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    expect(DB::table('ec_gate_parent_deliveries')->sole()->parent_account_id)->toBe($first->id);
});

it('respects parent attendance in-app preferences even when push is permitted separately', function (): void {
    config(['gate.mirror_delivery_enabled' => false]);
    $parent = GateFixture::parent($this->gate);
    DB::table('ec_notification_preferences')->insert(['parent_account_id' => $parent->id, 'category' => 'attendance', 'in_app_enabled' => false, 'push_enabled' => true]);
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    expect(app(OutboxDispatcher::class)->work()['skipped'])->toBe(1);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('holds missing mappings instead of resolving a different student when a retry runs later', function (): void {
    DB::table('ec_integration_mappings')->where('local_type', 'student')->delete();
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_outbox')->where('destination', 'edu_admin')->value('status'))->toBe('held_mapping');
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    Http::assertNothingSent();
});

it('does not send a stale arrival and its correction when neither had reached the parent', function (): void {
    config(['gate.mirror_delivery_enabled' => false]);
    GateFixture::parent($this->gate);
    $id = GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_hikvision_observations')->where('id', $id)->update(['status' => 'held_content_conflict']);
    app(DayProjector::class)->work();
    expect(app(OutboxDispatcher::class)->work()['skipped'])->toBe(2);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    expect(DB::table('ec_gate_parent_deliveries')->pluck('reason_code')->all())->toBe(['superseded_before_delivery', 'withdrawn_before_delivery']);
});
