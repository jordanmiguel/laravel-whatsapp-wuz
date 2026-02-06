<?php

use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

it('uses configurable table name', function () {
    $device = new WuzDevice;
    expect($device->getTable())->toBe('wuz_devices');

    config(['wuz.table_names.devices' => 'custom_devices']);
    expect($device->getTable())->toBe('custom_devices');
});

it('stores the token as plain text for webhook lookup', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Test Device',
        'token' => 'device-abc123',
        'device_id' => 'wuz-1',
    ]);

    $device->refresh();
    expect($device->token)->toBe('device-abc123');

    $raw = \DB::table('wuz_devices')->where('id', $device->id)->value('token');
    expect($raw)->toBe('device-abc123');
});

it('casts connected and is_default to boolean', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Test',
        'token' => 'tok',
        'connected' => 1,
        'is_default' => 0,
    ]);

    $device->refresh();
    expect($device->connected)->toBeTrue();
    expect($device->is_default)->toBeFalse();
});

it('has morph relationship to owner', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Test Device',
        'token' => 'tok',
    ]);

    expect($device->owner)->toBeInstanceOf(TestOwner::class);
    expect($device->owner->id)->toBe($owner->id);
});
