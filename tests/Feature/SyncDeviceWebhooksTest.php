<?php

use JordanMiguel\Wuz\Actions\SyncDeviceWebhooksAction;
use JordanMiguel\Wuz\Models\WuzDeviceWebhook;
use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

it('creates webhooks for a device', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
    ]);

    app(SyncDeviceWebhooksAction::class)->handle($device, [
        ['event' => 'All', 'url' => 'http://example.com/hook', 'status' => true],
        ['event' => 'Message', 'url' => 'http://example.com/msg', 'status' => false],
    ]);

    expect($device->webhooks()->count())->toBe(2);
});

it('updates existing webhooks instead of creating duplicates', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
    ]);

    app(SyncDeviceWebhooksAction::class)->handle($device, [
        ['event' => 'All', 'url' => 'http://old.com/hook', 'status' => true],
    ]);

    app(SyncDeviceWebhooksAction::class)->handle($device, [
        ['event' => 'All', 'url' => 'http://new.com/hook', 'status' => false],
    ]);

    expect($device->webhooks()->count())->toBe(1);
    expect($device->webhooks()->first()->url)->toBe('http://new.com/hook');
    expect($device->webhooks()->first()->status)->toBeFalse();
});
