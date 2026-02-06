<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\DeleteDeviceAction;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/admin/users/*/full' => Http::response(['success' => true], 200),
    ]);
});

it('deletes a device from WuzAPI and database', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
    ]);

    app(DeleteDeviceAction::class)->handle($device);

    expect($owner->wuzDevices()->count())->toBe(0);
});

it('promotes next device to default when deleting the default', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $first = $owner->wuzDevices()->create([
        'name' => 'First',
        'token' => 'tok1',
        'device_id' => 'wuz-1',
        'is_default' => true,
    ]);
    $second = $owner->wuzDevices()->create([
        'name' => 'Second',
        'token' => 'tok2',
        'device_id' => 'wuz-2',
        'is_default' => false,
    ]);

    app(DeleteDeviceAction::class)->handle($first);

    expect($second->fresh()->is_default)->toBeTrue();
});

it('handles deleting when no remaining devices exist', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Only',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'is_default' => true,
    ]);

    app(DeleteDeviceAction::class)->handle($device);

    expect($owner->wuzDevices()->count())->toBe(0);
});
