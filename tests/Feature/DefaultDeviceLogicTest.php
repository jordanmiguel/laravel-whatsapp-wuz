<?php

use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

it('returns null when no default device exists', function () {
    $owner = TestOwner::create(['name' => 'Test']);

    expect($owner->defaultWuzDevice())->toBeNull();
});

it('returns the default device', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->default()->create();

    expect($owner->defaultWuzDevice()->id)->toBe($device->id);
});

it('switches default device in a transaction', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $first = WuzDevice::factory()->for($owner, 'owner')->default()->create();
    $second = WuzDevice::factory()->for($owner, 'owner')->create();

    $owner->setDefaultWuzDevice($second);

    expect($first->fresh()->is_default)->toBeFalse();
    expect($second->fresh()->is_default)->toBeTrue();
    expect($owner->defaultWuzDevice()->id)->toBe($second->id);
});

it('returns only connected devices', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->connected()->create(['name' => 'Connected']);
    WuzDevice::factory()->for($owner, 'owner')->create(['name' => 'Disconnected']);

    expect($owner->connectedWuzDevices()->count())->toBe(1);
    expect($owner->connectedWuzDevices()->first()->name)->toBe('Connected');
});

it('isolates devices between owners', function () {
    $ownerA = TestOwner::create(['name' => 'Owner A']);
    $ownerB = TestOwner::create(['name' => 'Owner B']);

    WuzDevice::factory()->for($ownerA, 'owner')->default()->create(['name' => 'A-Device']);
    WuzDevice::factory()->for($ownerB, 'owner')->default()->create(['name' => 'B-Device']);

    expect($ownerA->wuzDevices()->count())->toBe(1);
    expect($ownerB->wuzDevices()->count())->toBe(1);
    expect($ownerA->defaultWuzDevice()->name)->toBe('A-Device');
    expect($ownerB->defaultWuzDevice()->name)->toBe('B-Device');
});

it('supports multiple devices per owner', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->default()->create();
    WuzDevice::factory()->for($owner, 'owner')->create();
    WuzDevice::factory()->for($owner, 'owner')->create();

    expect($owner->wuzDevices()->count())->toBe(3);
});
