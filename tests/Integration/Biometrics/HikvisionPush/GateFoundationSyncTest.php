<?php

use App\Contracts\EduAdminConnector;
use App\Models\V2\AcademicYear;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\Tenant;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
    Http::preventStrayRequests();
    $legacy = Tenant::create(['name' => 'SYNTHETIC legacy', 'slug' => 'legacy', 'status' => 'active']);
    $tenant = Tenant::create(['name' => 'SYNTHETIC new', 'slug' => 'new', 'status' => 'active']);
    $this->year = AcademicYear::create(['tenant_id' => $legacy->id, 'name' => 'SYNTHETIC global year',
        'source_system' => 'edu_admin', 'source_id' => '1', 'is_current' => true, 'status' => 'active']);
    $this->connection = IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'edu_admin',
        'mode' => 'connected', 'status' => 'active', 'base_url' => 'https://source.example.test',
        'feature_flags' => ['gate_only_sync' => true, 'attendance' => false, 'messages' => false]]);
    $this->connector = new class implements EduAdminConnector
    {
        public array $calls = [];

        public function bootstrap(): array
        {
            return ['complex' => ['id' => 2, 'name' => 'SYNTHETIC new', 'slug' => 'new', 'status' => 'active']];
        }

        public function resource(string $resource, ?string $cursor = null, array $filters = []): array
        {
            $this->calls[] = $resource;

            return ['data' => [], 'has_more' => false, 'next_cursor' => null];
        }

        public function pushAttendanceEvents(array $events): array
        {
            throw new RuntimeException('No delivery allowed.');
        }

        public function pushConversationMessage(array $message): array
        {
            throw new RuntimeException('No delivery allowed.');
        }
    };
});

it('limits both initial and scheduled incremental gate-only pulls without moving a shared source year', function (string $kind): void {
    $before = $this->year->fresh()->getRawOriginal();
    $sync = app(SyncCoordinator::class);
    $run = $kind === 'initial' ? $sync->runInitialSync($this->connection, $this->connector)
        : $sync->runIncrementalSync($this->connection, $this->connector);
    expect($run->status)->toBe('completed')->and($this->connector->calls)->toBe(['sections', 'education_options', 'streams', 'classes', 'students']);
    expect($this->year->fresh()->getRawOriginal())->toBe($before);
    expect(DB::table('ec_parent_student_links')->count())->toBe(0)->and(DB::table('ec_mobile_messages')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['initial', 'incremental']);

it('honors an explicit allowed subset', function (): void {
    app(SyncCoordinator::class)->runIncrementalSync($this->connection, $this->connector, ['resources' => ['students']]);
    expect($this->connector->calls)->toBe(['students']);
});

it('imports only parent access in addition to gate resources when explicitly opted in', function () {
    $this->connection->update(['feature_flags' => ['gate_only_sync' => true, 'gate_parent_links_enabled' => true]]);
    app(SyncCoordinator::class)->runIncrementalSync($this->connection, $this->connector);
    expect($this->connector->calls)->toBe(['sections', 'education_options', 'streams', 'classes', 'students', 'parent_links']);
    expect(fn () => app(SyncCoordinator::class)->runIncrementalSync($this->connection, $this->connector, ['resources' => ['academic_years']]))->toThrow(InvalidArgumentException::class);
});

it('rejects unrelated or mixed requests before creating a run or making source requests', function (array $resources): void {
    expect(fn () => app(SyncCoordinator::class)->runIncrementalSync($this->connection, $this->connector, ['resources' => $resources]))
        ->toThrow(InvalidArgumentException::class);
    expect($this->connector->calls)->toBe([])->and(DB::table('ec_integration_sync_runs')->count())->toBe(0);
})->with([[['academic_years']], [['mobile_messages']], [['students', 'parent_links']], [['schools']]]);

it('applies the same resource boundary to direct record import', function (): void {
    expect(fn () => app(SyncCoordinator::class)->importResourceRecords($this->connection, 'academic_years', [
        ['id' => 1, 'name' => 'SYNTHETIC must not move', 'is_current' => true],
    ]))->toThrow(InvalidArgumentException::class);
    expect($this->year->fresh()->tenant_id)->toBe($this->year->tenant_id);
});

it('fails closed on malformed gate-only mode', function (mixed $value): void {
    $this->connection->update(['feature_flags' => ['gate_only_sync' => $value]]);
    expect(fn () => app(SyncCoordinator::class)->runInitialSync($this->connection, $this->connector))->toThrow(InvalidArgumentException::class);
    expect($this->connector->calls)->toBe([])->and(DB::table('ec_integration_sync_runs')->count())->toBe(0);
})->with([['true'], [1], [null], [[]]]);

it('preserves existing full-integration behavior unless the mode is explicitly enabled', function (array $flags): void {
    $this->connection->update(['feature_flags' => $flags]);
    app(SyncCoordinator::class)->runInitialSync($this->connection, $this->connector);
    expect($this->connector->calls)->toBe(['schools', 'academic_years', 'sections', 'education_options', 'streams',
        'classes', 'students', 'parent_links', 'mobile_messages', 'student_mobile_profiles']);
})->with([[[]], [['gate_only_sync' => false]]]);
