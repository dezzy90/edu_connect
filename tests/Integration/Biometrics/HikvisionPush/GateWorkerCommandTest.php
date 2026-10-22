<?php

use App\Services\Biometrics\Gate\DayProjector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

function gateWorkerRun(array $options = []): array
{
    $exit = Artisan::call('gate:work', ['--json' => true] + $options);

    return [$exit, json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR)];
}

it('runs the real gate projection and dedicated destinations without enrollment or legacy attendance', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    Http::fake(['https://eduadmin.test/api/v1/integrations/edu-connect/gate-events' => function ($request) {
        $event = json_decode($request->body(), true)['events'][0];

        return Http::response(['accepted' => [$event['event_id'].':'.$event['revision']], 'duplicates' => []]);
    }]);
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(0)->and($status['ok'])->toBeTrue()->and($status['passes'])->toBe(1)
        ->and($status['observations'])->toBe(1)->and($status['delivery']['sent'])->toBe(2);
    expect(DB::table('ec_gate_movements')->count())->toBe(1)->and(DB::table('ec_mobile_notifications')->count())->toBe(1)
        ->and(DB::table('ec_attendance_events')->count())->toBe(0);
    Http::assertSentCount(1);
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(0)->and($status['observations'])->toBe(0)->and($status['delivery']['sent'])->toBe(0);
    expect(DB::table('ec_gate_movements')->count())->toBe(1)->and(DB::table('ec_mobile_notifications')->count())->toBe(1);
    Http::assertSentCount(1);
});

it('does no work when disabled', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    config(['gate.enabled' => false]);
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(0)->and($status)->toBe(['ok' => true, 'enabled' => false, 'passes' => 0]);
    expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects malformed enabled school scope before processing', function (mixed $scope): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    config(['gate.school_ids' => $scope]);
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(1)->and($status['error'])->toBe('gate_scope_not_configured');
    expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
    Http::assertNothingSent();
})->with([[null], [[]], [['1']], [[0]], [[1, 1]], [['school' => 1]]]);

it('rejects non-boolean activation instead of coercing it', function (mixed $flag): void {
    config(['gate.enabled' => $flag]);
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(1)->and($status['error'])->toBe('gate_scope_not_configured');
    Http::assertNothingSent();
})->with([null, 'false', 'true', 1]);

it('validates bounded command options without clamping or interpreting malformed numbers', function (array $options): void {
    [$exit, $status] = gateWorkerRun($options);
    expect($exit)->toBe(2)->and($status['error'])->toBe('invalid_worker_options');
    expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    [['--limit' => '0']], [['--limit' => '501']], [['--limit' => '1e2']], [['--limit' => '1.5']],
    [['--seconds' => '-1']], [['--seconds' => '56']], [['--interval-ms' => '0']], [['--interval-ms' => '5001']],
]);

it('skips an overlapping worker without effects', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    $lock = Cache::lock('biometrics:gate:work', 120);
    expect($lock->get())->toBeTrue();
    try {
        [$exit, $status] = gateWorkerRun();
        expect($exit)->toBe(0)->and($status['skipped'])->toBe('worker_already_running')->and($status['passes'])->toBe(0);
        expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
    } finally {
        $lock->release();
    }
    Http::assertNothingSent();
});

it('keeps committed delivery independent when projection fails and releases the lock', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    app(DayProjector::class)->work();
    app()->bind(DayProjector::class, static fn () => throw new RuntimeException('PRIVATE-PROJECTION-SECRET'));
    config(['gate.mirror_delivery_enabled' => false]);
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(1)->and($status['delivery']['sent'])->toBe(1)->and($status['failures'])->toBe(['projection_failed']);
    expect(json_encode($status))->not->toContain('PRIVATE', 'Synthetic', 'token', 'photo');
    $lock = Cache::lock('biometrics:gate:work', 120);
    expect($lock->get())->toBeTrue();
    $lock->release();
    Http::assertNothingSent();
});

it('processes only the configured school and never a different school observation', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    config(['gate.school_ids' => [$this->gate['school']->id + 100], 'gate.mirror_delivery_enabled' => false]);
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(0)->and($status['observations'])->toBe(0)->and($status['delivery']['sent'])->toBe(0);
    expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
    Http::assertNothingSent();
});

it('provides a bounded repeat-pass mode without sending HTTP when there is no work', function (): void {
    config(['gate.mirror_delivery_enabled' => false, 'gate.parent_delivery_enabled' => false]);
    [$exit, $status] = gateWorkerRun(['--seconds' => '1', '--interval-ms' => '250']);
    expect($exit)->toBe(0)->and($status['passes'])->toBeGreaterThan(1)->toBeLessThanOrEqual(4);
    Http::assertNothingSent();
});

it('redacts a cache failure and does not process observations without the worker lease', function (): void {
    GateFixture::scan($this->gate, 1, '07:30:00');
    Cache::shouldReceive('lock')->once()->andThrow(new RuntimeException('PRIVATE-CACHE-CREDENTIAL'));
    [$exit, $status] = gateWorkerRun();
    expect($exit)->toBe(1)->and($status)->toBe(['ok' => false, 'error' => 'gate_worker_failed']);
    expect(json_encode($status))->not->toContain('PRIVATE', 'credential');
    expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
    Http::assertNothingSent();
});

it('provides a human-readable disabled status without exposing configuration', function (): void {
    config(['gate.enabled' => false]);
    expect(Artisan::call('gate:work'))->toBe(0);
    expect(Artisan::output())->toContain('disabled; no work performed');
    Http::assertNothingSent();
});

it('registers the independent scheduled worker only when explicitly enabled', function (mixed $flag, int $expected): void {
    config(['gate.enabled' => $flag]);
    Artisan::call('gate:work', ['--json' => true]);
    // The test application loaded console routes before this case configured its activation flag.
    // Re-evaluate registration only; no scheduled command is executed by requiring this file.
    require base_path('routes/gate_console.php');
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => $event->description === 'gate.projection-delivery');
    expect($events)->toHaveCount($expected);
    if ($expected) {
        $event = $events->sole();
        expect($event->expression)->toBe('* * * * *')->and($event->command)->toContain('gate:work', '--seconds=50', '--interval-ms=2000');
        expect($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
    }
    Http::assertNothingSent();
})->with([[false, 0], [null, 0], ['true', 0], [true, 1]]);
