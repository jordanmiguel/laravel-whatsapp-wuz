<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\DeleteDeviceAction;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/admin/users/*/full' => Http::response(['success' => true], 200),
    ]);
});

it('deletes a device from WuzAPI and database', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create();

    app(DeleteDeviceAction::class)->handle($device);

    expect($owner->wuzDevices()->count())->toBe(0);
});

it('promotes next device to default when deleting the default', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $first = WuzDevice::factory()->for($owner, 'owner')->default()->create();
    $second = WuzDevice::factory()->for($owner, 'owner')->create();

    app(DeleteDeviceAction::class)->handle($first);

    expect($second->fresh()->is_default)->toBeTrue();
});

it('handles deleting when no remaining devices exist', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->default()->create();

    app(DeleteDeviceAction::class)->handle($device);

    expect($owner->wuzDevices()->count())->toBe(0);
});
