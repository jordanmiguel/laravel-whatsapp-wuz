<?php

use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

it('returns null when no default device exists', function () {
    $tenant = TestTenant::create(['name' => 'Test']);

    expect($tenant->defaultWuzDevice())->toBeNull();
});

it('returns the default device', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Default',
        'token' => 'tok1',
        'is_default' => true,
    ]);

    expect($tenant->defaultWuzDevice()->id)->toBe($device->id);
});

it('switches default device in a transaction', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $first = $tenant->wuzDevices()->create([
        'name' => 'First',
        'token' => 'tok1',
        'is_default' => true,
    ]);
    $second = $tenant->wuzDevices()->create([
        'name' => 'Second',
        'token' => 'tok2',
        'is_default' => false,
    ]);

    $tenant->setDefaultWuzDevice($second);

    expect($first->fresh()->is_default)->toBeFalse();
    expect($second->fresh()->is_default)->toBeTrue();
    expect($tenant->defaultWuzDevice()->id)->toBe($second->id);
});

it('returns only connected devices', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $tenant->wuzDevices()->create([
        'name' => 'Connected',
        'token' => 'tok1',
        'connected' => true,
    ]);
    $tenant->wuzDevices()->create([
        'name' => 'Disconnected',
        'token' => 'tok2',
        'connected' => false,
    ]);

    expect($tenant->connectedWuzDevices()->count())->toBe(1);
    expect($tenant->connectedWuzDevices()->first()->name)->toBe('Connected');
});

it('isolates devices between tenants', function () {
    $tenantA = TestTenant::create(['name' => 'Tenant A']);
    $tenantB = TestTenant::create(['name' => 'Tenant B']);

    $tenantA->wuzDevices()->create(['name' => 'A-Device', 'token' => 'tokA', 'is_default' => true]);
    $tenantB->wuzDevices()->create(['name' => 'B-Device', 'token' => 'tokB', 'is_default' => true]);

    expect($tenantA->wuzDevices()->count())->toBe(1);
    expect($tenantB->wuzDevices()->count())->toBe(1);
    expect($tenantA->defaultWuzDevice()->name)->toBe('A-Device');
    expect($tenantB->defaultWuzDevice()->name)->toBe('B-Device');
});

it('supports multiple devices per tenant', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $tenant->wuzDevices()->create(['name' => 'Device 1', 'token' => 'tok1', 'is_default' => true]);
    $tenant->wuzDevices()->create(['name' => 'Device 2', 'token' => 'tok2']);
    $tenant->wuzDevices()->create(['name' => 'Device 3', 'token' => 'tok3']);

    expect($tenant->wuzDevices()->count())->toBe(3);
});
