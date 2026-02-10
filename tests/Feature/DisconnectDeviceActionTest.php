<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\DisconnectDeviceAction;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

it('logs out the device and updates state', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/session/logout' => Http::response(['success' => true], 200),
    ]);

    Event::fake();

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(DisconnectDeviceAction::class)->handle($device);

    expect($device->fresh()->connected)->toBeFalse();
    expect($device->fresh()->jid)->toBeNull();
    Event::assertDispatched(DeviceDisconnected::class);
});

it('swallows API exceptions during logout', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/session/logout' => Http::response('error', 500),
    ]);

    Event::fake();

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(DisconnectDeviceAction::class)->handle($device);

    expect($device->fresh()->connected)->toBeFalse();
    Event::assertDispatched(DeviceDisconnected::class);
});
