<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\GetDeviceStatusAction;
use JordanMiguel\Wuz\Data\DeviceStatusData;
use JordanMiguel\Wuz\Events\DeviceConnected;
use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('returns connected status when device is logged in', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => true, 'jid' => '5511@s.whatsapp.net']], 200),
        '*/webhook' => Http::response(['success' => true], 200),
    ]);

    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => false,
    ]);

    Event::fake();

    $action = app(GetDeviceStatusAction::class);
    $result = $action->handle($device);

    expect($result)->toBeInstanceOf(DeviceStatusData::class);
    expect($result->connected)->toBeTrue();
    expect($result->status)->toBe('connected');
    expect($result->jid)->toBe('5511@s.whatsapp.net');

    Event::assertDispatched(DeviceConnected::class);
});

it('returns QR status when device needs pairing', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => false, 'jid' => null]], 200),
        '*/webhook' => Http::response(['success' => true], 200),
        '*/session/qr' => Http::response(['data' => ['QRCode' => 'base64-qr-data']], 200),
    ]);

    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
    ]);

    $action = app(GetDeviceStatusAction::class);
    $result = $action->handle($device);

    expect($result->status)->toBe('qr');
    expect($result->qr_code)->toBe('base64-qr-data');
});

it('updates device connected state in database', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => true, 'jid' => 'jid123']], 200),
        '*/webhook' => Http::response(['success' => true], 200),
    ]);

    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => false,
    ]);

    Event::fake();

    app(GetDeviceStatusAction::class)->handle($device);

    expect($device->fresh()->connected)->toBeTrue();
    expect($device->fresh()->jid)->toBe('jid123');
});
