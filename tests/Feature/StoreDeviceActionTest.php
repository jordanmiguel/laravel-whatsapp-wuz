<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\StoreDeviceAction;
use JordanMiguel\Wuz\Data\StoreDeviceData;
use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/admin/users' => Http::response(['data' => ['id' => 42]], 200),
        '*/session/connect' => Http::response(['data' => ['connected' => true]], 200),
    ]);
});

it('creates a device via the WuzAPI', function () {
    $tenant = TestTenant::create(['name' => 'Clinic A']);

    $action = app(StoreDeviceAction::class);
    $device = $action->handle($tenant, new StoreDeviceData(name: 'Reception Phone'));

    expect($device->name)->toBe('Reception Phone');
    expect($device->device_id)->toEqual(42);
    expect($device->token)->not->toBeNull();
    expect($device->tenant_id)->toBe($tenant->id);
    expect($device->tenant_type)->toBe(TestTenant::class);
});

it('sets first device as default automatically', function () {
    $tenant = TestTenant::create(['name' => 'Clinic A']);

    $action = app(StoreDeviceAction::class);
    $device = $action->handle($tenant, new StoreDeviceData(name: 'First Device'));

    expect($device->is_default)->toBeTrue();
});

it('does not set subsequent devices as default', function () {
    $tenant = TestTenant::create(['name' => 'Clinic A']);

    $action = app(StoreDeviceAction::class);
    $first = $action->handle($tenant, new StoreDeviceData(name: 'First'));
    $second = $action->handle($tenant, new StoreDeviceData(name: 'Second'));

    expect($first->fresh()->is_default)->toBeTrue();
    expect($second->is_default)->toBeFalse();
});

it('stores created_by when provided', function () {
    $tenant = TestTenant::create(['name' => 'Clinic A']);

    $action = app(StoreDeviceAction::class);
    $device = $action->handle($tenant, new StoreDeviceData(name: 'Device'), createdBy: 99);

    expect($device->created_by)->toBe(99);
});
