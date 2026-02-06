<?php

use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

it('returns null when no default device exists', function () {
    $owner = TestOwner::create(['name' => 'Test']);

    expect($owner->defaultWuzDevice())->toBeNull();
});

it('returns the default device', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Default',
        'token' => 'tok1',
        'is_default' => true,
    ]);

    expect($owner->defaultWuzDevice()->id)->toBe($device->id);
});

it('switches default device in a transaction', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $first = $owner->wuzDevices()->create([
        'name' => 'First',
        'token' => 'tok1',
        'is_default' => true,
    ]);
    $second = $owner->wuzDevices()->create([
        'name' => 'Second',
        'token' => 'tok2',
        'is_default' => false,
    ]);

    $owner->setDefaultWuzDevice($second);

    expect($first->fresh()->is_default)->toBeFalse();
    expect($second->fresh()->is_default)->toBeTrue();
    expect($owner->defaultWuzDevice()->id)->toBe($second->id);
});

it('returns only connected devices', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $owner->wuzDevices()->create([
        'name' => 'Connected',
        'token' => 'tok1',
        'connected' => true,
    ]);
    $owner->wuzDevices()->create([
        'name' => 'Disconnected',
        'token' => 'tok2',
        'connected' => false,
    ]);

    expect($owner->connectedWuzDevices()->count())->toBe(1);
    expect($owner->connectedWuzDevices()->first()->name)->toBe('Connected');
});

it('isolates devices between owners', function () {
    $ownerA = TestOwner::create(['name' => 'Owner A']);
    $ownerB = TestOwner::create(['name' => 'Owner B']);

    $ownerA->wuzDevices()->create(['name' => 'A-Device', 'token' => 'tokA', 'is_default' => true]);
    $ownerB->wuzDevices()->create(['name' => 'B-Device', 'token' => 'tokB', 'is_default' => true]);

    expect($ownerA->wuzDevices()->count())->toBe(1);
    expect($ownerB->wuzDevices()->count())->toBe(1);
    expect($ownerA->defaultWuzDevice()->name)->toBe('A-Device');
    expect($ownerB->defaultWuzDevice()->name)->toBe('B-Device');
});

it('supports multiple devices per owner', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $owner->wuzDevices()->create(['name' => 'Device 1', 'token' => 'tok1', 'is_default' => true]);
    $owner->wuzDevices()->create(['name' => 'Device 2', 'token' => 'tok2']);
    $owner->wuzDevices()->create(['name' => 'Device 3', 'token' => 'tok3']);

    expect($owner->wuzDevices()->count())->toBe(3);
});
