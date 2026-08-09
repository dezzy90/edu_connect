<?php

use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationMapping;
use App\Models\V2\Tenant;
use App\Services\Integration\MappingService;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
});

it('keeps one mapping per external identity and resolves both directions', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Local Demo',
        'slug' => 'local-demo',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'base_url' => 'https://edu-admin.test',
        'status' => 'active',
    ]);

    $service = new MappingService();

    $service->upsert($connection, 'student', 1, 'student', 70, 'first-checksum');
    $service->upsert($connection, 'student', 2, 'student', 70, 'second-checksum');

    expect(IntegrationMapping::query()->count())->toBe(1);
    expect($service->findLocalId($connection, 'student', 70))->toBe(2);
    expect($service->findExternalId($connection, 'student', 2))->toBe('70');
    expect($service->findLocalId($connection, 'student', 999))->toBeNull();
});
