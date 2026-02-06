<?php

use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzService;
use JordanMiguel\Wuz\Services\WuzServiceFactory;
use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

it('creates a service for a specific device', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Test Device',
        'token' => 'device-token-123',
        'device_id' => 'wuz-1',
    ]);

    $factory = new WuzServiceFactory;
    $service = $factory->make($device);

    expect($service)->toBeInstanceOf(WuzService::class);
    expect($service->userToken)->toBe('device-token-123');
});

it('creates an admin service', function () {
    config(['wuz.admin_token' => 'my-admin-token']);

    $factory = new WuzServiceFactory;
    $service = $factory->admin();

    expect($service)->toBeInstanceOf(WuzService::class);
    expect($service->adminToken)->toBe('my-admin-token');
});
