<?php

use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\GateWork;
use App\Services\Biometrics\Gate\OutboxDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-20T18:00:00Z');
    $this->gate = GateFixture::boot();
    GateFixture::parent($this->gate);
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

it('projects and delivers attendance without an enrollment worker or any source-photo request', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    Http::fake(['https://eduadmin.test/api/v1/integrations/edu-connect/gate-events' => function ($request) {
        $event = json_decode($request->body(), true)['events'][0];

        return Http::response(['accepted' => [$event['event_id'].':'.$event['revision']], 'duplicates' => []]);
    }]);
    $stats = app(GateWork::class)->tick();
    expect($stats['observations'])->toBe(1)->and($stats['delivery']['sent'])->toBe(2)->and($stats['failures'])->toBe([]);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(1);
    Http::assertSentCount(1);
});

it('continues pending deliveries when projection throws and keeps exception details out of status', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    // A deliberately invalid projector binding exercises the worker's failure isolation without mocking a final class.
    app()->bind(DayProjector::class, static fn () => throw new RuntimeException('PRIVATE-PROJECTION-ERROR'));
    config(['gate.mirror_delivery_enabled' => false]);
    $stats = app(GateWork::class)->tick();
    expect($stats['failures'])->toBe(['projection_failed'])->and($stats['delivery']['sent'])->toBe(1);
    expect(json_encode($stats))->not->toContain('PRIVATE-PROJECTION-ERROR');
    Http::assertNothingSent();
});

it('retains committed observations when delivery is unavailable', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app()->bind(OutboxDispatcher::class, static fn () => throw new RuntimeException('PRIVATE-DELIVERY-ERROR'));
    $stats = app(GateWork::class)->tick();
    expect($stats['observations'])->toBe(1)->and($stats['failures'])->toBe(['delivery_failed']);
    expect(DB::table('ec_gate_movements')->count())->toBe(1)->and(DB::table('ec_gate_outbox')->count())->toBe(2);
    Http::assertNothingSent();
});
