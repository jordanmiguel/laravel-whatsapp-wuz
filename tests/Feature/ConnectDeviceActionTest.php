<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\ConnectDeviceAction;
use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

it('calls session connect on WuzAPI', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/session/connect' => Http::response(['data' => ['connected' => true]], 200),
    ]);

    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok-123',
        'device_id' => 'wuz-1',
    ]);

    $action = app(ConnectDeviceAction::class);
    $action->handle($device);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/session/connect'));
});

it('swallows exceptions gracefully', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/session/connect' => Http::response('error', 500),
    ]);

    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok-123',
        'device_id' => 'wuz-1',
    ]);

    $action = app(ConnectDeviceAction::class);
    $action->handle($device);

    expect(true)->toBeTrue();
});
