<?php

use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\DeliverySnapshot;
use App\Services\Biometrics\Gate\MirrorRecovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->f = GateFixture::boot();
    Queue::fake(); Http::preventStrayRequests();
    $this->f['student']->update(['source_system' => 'edu_admin', 'source_id' => '2934']);
    $this->f['connection']->update(['enabled_school_ids' => [26]]);
    GateFixture::scan($this->f, 1, '07:30:00');
    app(DayProjector::class)->work();
    $this->row = DB::table('ec_gate_outbox')->where('destination', 'edu_admin')->sole();
    $this->f['connection']->update(['enabled_school_ids' => [$this->f['school']->id]]);
    $this->approval = ['outbox_id' => $this->row->id, 'payload_sha256' => hash('sha256', $this->row->payload),
        'snapshot' => DeliverySnapshot::currentMirror(json_decode($this->row->payload, true))];
});

it('recovers only an explicitly approved held mirror without changing attendance or parent recipients', function (): void {
    $parents = json_encode(DB::table('ec_gate_outbox')->where('destination', 'parents')->get());
    expect($this->row->status)->toBe('held_mapping');
    // Ordinary retries continue to respect the previously frozen hold.
    expect(DeliverySnapshot::mirror(json_decode($this->row->payload, true)))->toBeNull();
    $service = app(MirrorRecovery::class);
    expect($service->recover($this->f['tenant']->id, $this->f['connection']->id, [$this->approval]))->toBe(1);
    expect($service->recover($this->f['tenant']->id, $this->f['connection']->id, [$this->approval]))->toBe(0);
    DB::table('ec_gate_outbox')->where('id', $this->row->id)->update(['status' => 'sent', 'attempts' => 1]);
    expect($service->recover($this->f['tenant']->id, $this->f['connection']->id, [$this->approval]))->toBe(0);
    $saved = DB::table('ec_gate_outbox')->where('id', $this->row->id)->sole();
    expect($saved->status)->toBe('sent')->and($saved->attempts)->toBe(1)->and($saved->payload)->toBe($this->row->payload)
        ->and(DB::table('ec_gate_movements')->count())->toBe(1)->and(DB::table('ec_gate_movement_revisions')->count())->toBe(1)
        ->and(json_encode(DB::table('ec_gate_outbox')->where('destination', 'parents')->get()))->toBe($parents)
        ->and(DB::table('ec_integration_audit_events')->where('event_type', 'gate.mirror_mapping.recovered')->count())->toBe(1);
    Http::assertNothingSent(); Queue::assertNothingPushed();
});

it('refuses changed payloads identities scope or existing destinations instead of guessing', function (string $change): void {
    match ($change) {
        'payload' => $this->approval['payload_sha256'] = str_repeat('0', 64),
        'student' => $this->approval['snapshot']['student_id'] = 9999,
        'source_identity' => $this->f['student']->update(['source_id' => '9999']),
        'scope' => $this->f['connection']->update(['enabled_school_ids' => []]),
        'attempted' => DB::table('ec_gate_outbox')->where('id', $this->row->id)->update(['attempts' => 1]),
        'withdrawn' => DB::table('ec_gate_movements')->where('event_id', $this->row->event_id)->update(['state' => 'withdrawn']),
        'connection' => $this->approval['snapshot']['connection_id'] = 999,
        'destination' => DB::table('ec_gate_outbox')->where('id', $this->row->id)->update(['delivery_snapshot' => '{}']),
        'parent' => $this->approval['outbox_id'] = DB::table('ec_gate_outbox')->where('destination', 'parents')->value('id'),
    };
    expect(fn () => app(MirrorRecovery::class)->recover($this->f['tenant']->id, $this->f['connection']->id, [$this->approval]))->toThrow(RuntimeException::class);
    expect(DB::table('ec_gate_outbox')->where('id', $this->row->id)->value('status'))->toBe('held_mapping');
    Http::assertNothingSent();
})->with(['payload', 'student', 'source_identity', 'scope', 'attempted', 'withdrawn', 'connection', 'destination', 'parent']);

it('rejects cross-tenant recovery and duplicate approvals', function (): void {
    expect(fn () => app(MirrorRecovery::class)->recover(999, $this->f['connection']->id, [$this->approval]))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => app(MirrorRecovery::class)->recover($this->f['tenant']->id, $this->f['connection']->id, [$this->approval, $this->approval]))->toThrow(RuntimeException::class);
    expect(DB::table('ec_gate_outbox')->where('id', $this->row->id)->value('status'))->toBe('held_mapping');
});

it('rolls back the whole approved batch when a later intent fails validation', function (): void {
    $invalid = $this->approval;
    $invalid['outbox_id'] = DB::table('ec_gate_outbox')->where('destination', 'parents')->value('id');
    expect(fn () => app(MirrorRecovery::class)->recover($this->f['tenant']->id, $this->f['connection']->id, [$this->approval, $invalid]))->toThrow(RuntimeException::class);
    expect(DB::table('ec_gate_outbox')->where('id', $this->row->id)->value('status'))->toBe('held_mapping')
        ->and(DB::table('ec_gate_outbox')->where('id', $this->row->id)->value('delivery_snapshot'))->toBeNull()
        ->and(DB::table('ec_integration_audit_events')->where('event_type', 'gate.mirror_mapping.recovered')->count())->toBe(0);
});
