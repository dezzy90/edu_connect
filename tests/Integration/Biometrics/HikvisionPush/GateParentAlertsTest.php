<?php

use App\Models\AdminUser;
use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationDelivery;
use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\OutboxDispatcher;
use App\Services\Biometrics\Gate\ParentAlertSettings;
use App\Services\Biometrics\Gate\GatePushDelivery;
use App\Services\Notifications\PushNotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);
beforeEach(function () {
    $this->f = GateFixture::boot();
    $this->f['student']->forceFill(['source_system' => 'edu_admin', 'source_id' => '2934', 'device_sync_enabled' => true])->save();
    foreach (['2026_10_21_000024_create_gate_setup_drafts.php', '2026_10_24_000030_create_gate_parent_alerts.php'] as $file) (require base_path('database/migrations/'.$file))->up();
    Carbon::setTestNow('2026-10-20T18:00:00Z');
    Http::preventStrayRequests();
    \Illuminate\Support\Facades\Queue::fake();
    config(['gate.parent_push_enabled' => true, 'gate.mirror_delivery_enabled' => false, 'educonnect.notifications.push_transport' => 'provider',
        'educonnect.notifications.fcm.access_token' => 'SYNTHETIC-token', 'educonnect.notifications.fcm.project_id' => 'synthetic-project']);
    $this->parent = GateFixture::parent($this->f);
    $this->token = DB::table('ec_mobile_push_tokens')->insertGetId(['parent_account_id' => $this->parent->id, 'token' => 'SYNTHETIC-device-token', 'platform' => 'android', 'provider' => 'fcm', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ec_gate_parent_alert_settings')->insert(['tenant_id' => $this->f['tenant']->id, 'school_id' => $this->f['school']->id,
        'arrivals_enabled' => true, 'departures_enabled' => true, 'effective_from' => '2026-10-19 00:00:00', 'revision' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
});
afterEach(fn () => Carbon::setTestNow());

it('delivers arrival and departure through the actual push adapter once per accepted movement', function () {
    Http::fake(['https://fcm.googleapis.com/*' => Http::response(['name' => 'synthetic/message/1'])]);
    GateFixture::scan($this->f, 1, '07:30:00'); GateFixture::scan($this->f, 2, '07:30:10'); GateFixture::scan($this->f, 3, '15:05:00');
    app(DayProjector::class)->work(); app(OutboxDispatcher::class)->work();
    expect(DB::table('ec_mobile_notifications')->count())->toBe(2);
    expect(GatePushDelivery::allowed(MobileNotification::first()))->toBeTrue();
    expect(app(PushNotificationDispatcher::class)->dispatchGateQueued()['sent'])->toBe(2);
    expect(app(PushNotificationDispatcher::class)->dispatchGateQueued()['sent'])->toBe(0);
    Http::assertSentCount(2);
    expect(DB::table('ec_mobile_notifications')->orderBy('id')->pluck('title')->all())->toBe(['Arrived at school', 'Left school']);
    expect(DB::table('ec_notification_deliveries')->where('status', 'sent')->count())->toBe(2);
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\V2\Notifications\DispatchGateParentAlertsJob::class);
});

it('rechecks parent access and preferences before any queued phone alert', function (string $change) {
    GateFixture::scan($this->f, 1, '07:30:00'); app(DayProjector::class)->work(); app(OutboxDispatcher::class)->work();
    if ($change === 'link') DB::table('ec_parent_student_links')->update(['status' => 'revoked']);
    if ($change === 'parent') DB::table('ec_parent_accounts')->update(['status' => 'inactive']);
    if ($change === 'preference') DB::table('ec_notification_preferences')->insert(['parent_account_id' => $this->parent->id, 'category' => 'attendance', 'in_app_enabled' => true, 'push_enabled' => false]);
    if ($change === 'school') DB::table('ec_gate_parent_alert_settings')->update(['arrivals_enabled' => false]);
    expect(app(PushNotificationDispatcher::class)->dispatchGateQueued()['skipped'])->toBe(1);
    Http::assertNothingSent();
})->with(['link', 'parent', 'preference', 'school']);

it('supports push-only preference without forcing in-app delivery', function () {
    DB::table('ec_notification_preferences')->insert(['parent_account_id' => $this->parent->id, 'category' => 'attendance', 'in_app_enabled' => false, 'push_enabled' => true]);
    GateFixture::scan($this->f, 1, '07:30:00'); app(DayProjector::class)->work(); app(OutboxDispatcher::class)->work();
    expect(DB::table('ec_mobile_notifications')->sole()->channel)->toBe('push');
    \Laravel\Sanctum\Sanctum::actingAs($this->parent, ['mobile:parent']);
    $this->getJson('/api/mobile/v2/notifications')->assertOk()->assertJsonCount(0, 'data.items');
});

it('retries temporary push failures with the same notification identity and without retaining request details', function () {
    GateFixture::scan($this->f, 1, '07:30:00'); app(DayProjector::class)->work(); app(OutboxDispatcher::class)->work();
    $attempt = 0;
    Http::fake(['https://fcm.googleapis.com/*' => function () use (&$attempt) {
        if (++$attempt === 1) throw new \Illuminate\Http\Client\ConnectionException('DO-NOT-RETAIN-REQUEST');
        return Http::response(['name' => 'synthetic/retry']);
    }]);
    expect(app(PushNotificationDispatcher::class)->dispatchGateQueued()['failed'])->toBe(1);
    $delivery = NotificationDelivery::sole();
    expect($delivery->last_error)->not->toContain('DO-NOT-RETAIN');
    expect(app(PushNotificationDispatcher::class)->dispatchGateQueued()['failed'])->toBe(0);
    Carbon::setTestNow(now()->addSeconds(301));
    expect(app(PushNotificationDispatcher::class)->dispatchGateQueued()['sent'])->toBe(1);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(1)->and(DB::table('ec_notification_deliveries')->count())->toBe(1);
});

it('requires explicit school opt-in and never sends historical arrivals from before opt-in', function (bool $remove) {
    if ($remove) DB::table('ec_gate_parent_alert_settings')->delete();
    else DB::table('ec_gate_parent_alert_settings')->update(['effective_from' => '2026-10-20 10:00:00']);
    GateFixture::scan($this->f, 1, '07:30:00'); app(DayProjector::class)->work(); app(OutboxDispatcher::class)->work();
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    Http::assertNothingSent();
})->with([true, false]);

it('leases queued gate push work and recovers an interrupted worker', function () {
    GateFixture::scan($this->f, 1, '07:30:00'); app(DayProjector::class)->work(); app(OutboxDispatcher::class)->work();
    $delivery = NotificationDelivery::sole(); $first = GatePushDelivery::claim($delivery);
    expect($first)->not->toBeNull(); expect(GatePushDelivery::claim($delivery))->toBeNull();
    Carbon::setTestNow(now()->addSeconds(121));
    $second = GatePushDelivery::claim($delivery); expect($second)->not->toBeNull()->not->toBe($first);
    GatePushDelivery::release($delivery->id, $first);
    expect(GatePushDelivery::claim($delivery))->toBeNull();
    GatePushDelivery::release($delivery->id, $second);
    expect(GatePushDelivery::claim($delivery))->not->toBeNull();
});

it('saves parent alert preferences independently of the timetable with scoped revision checks', function () {
    config(['gate.setup_enabled' => true, 'gate.setup_school_ids' => [$this->f['school']->id]]);
    $actor = AdminUser::create(['name' => 'SYNTHETIC admin', 'email' => 'synthetic-alert-admin@example.test', 'password' => 'SYNTHETIC', 'role' => 'school_admin', 'is_active' => true]);
    DB::table('ec_gate_setup_assignments')->insert(['admin_id' => $actor->id, 'tenant_id' => $this->f['tenant']->id, 'school_id' => $this->f['school']->id, 'active' => true]);
    $body = ['expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-alert-update', 'arrivals_enabled' => false, 'departures_enabled' => true];
    $settings = app(ParentAlertSettings::class)->save($actor, $this->f['school']->id, $body);
    expect($settings['revision'])->toBe(2)->and($settings['arrivals_enabled'])->toBeFalse();
    expect(app(ParentAlertSettings::class)->save($actor, $this->f['school']->id, $body)['revision'])->toBe(2);
    expect(DB::table('ec_gate_parent_alert_changes')->count())->toBe(1);
    expect(DB::table('ec_gate_policies')->count())->toBe(1);
});
