<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\DeleteDeviceAction;
use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/admin/users/*/full' => Http::response(['success' => true], 200),
    ]);
});

it('deletes a device from WuzAPI and database', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
    ]);

    app(DeleteDeviceAction::class)->handle($device);

    expect($tenant->wuzDevices()->count())->toBe(0);
});

it('promotes next device to default when deleting the default', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $first = $tenant->wuzDevices()->create([
        'name' => 'First',
        'token' => 'tok1',
        'device_id' => 'wuz-1',
        'is_default' => true,
    ]);
    $second = $tenant->wuzDevices()->create([
        'name' => 'Second',
        'token' => 'tok2',
        'device_id' => 'wuz-2',
        'is_default' => false,
    ]);

    app(DeleteDeviceAction::class)->handle($first);

    expect($second->fresh()->is_default)->toBeTrue();
});

it('handles deleting when no remaining devices exist', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Only',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'is_default' => true,
    ]);

    app(DeleteDeviceAction::class)->handle($device);

    expect($tenant->wuzDevices()->count())->toBe(0);
});
