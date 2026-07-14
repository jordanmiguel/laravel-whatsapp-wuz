<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\ReregisterDeviceAction;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(fn () => Http::fake([
    '*/admin/users' => Http::response(['data' => ['id' => 'fresh-gateway-id']], 200),
]));

it('gives the device a fresh gateway user without replacing the row', function () {
    $owner = TestOwner::create(['name' => 'Clinic']);
    $device = WuzDevice::factory()->forOwner($owner)->create([
        'token' => 'device-the-gateway-forgot',
        'device_id' => 'stale-gateway-id',
    ]);

    app(ReregisterDeviceAction::class)->handle($device);

    // Same row: it is the parent of the device's history, which cascades on delete.
    expect($device->fresh())->not->toBeNull()
        ->and(WuzDevice::count())->toBe(1)
        ->and($device->fresh()->id)->toBe($device->id)
        ->and($device->fresh()->token)->not->toBe('device-the-gateway-forgot')
        ->and($device->fresh()->device_id)->toBe('fresh-gateway-id');
});

it('keeps the history that a delete-and-recreate would cascade away', function () {
    $owner = TestOwner::create(['name' => 'Clinic']);
    $device = WuzDevice::factory()->forOwner($owner)->create();
    $log = WuzCallbackLog::create([
        'wuz_device_id' => $device->id,
        'event_type' => 'Message',
        'payload' => ['hello' => 'world'],
    ]);

    app(ReregisterDeviceAction::class)->handle($device);

    expect(WuzCallbackLog::whereKey($log->id)->exists())->toBeTrue();
});

it('comes back unpaired, since the session went with the old user', function () {
    $owner = TestOwner::create(['name' => 'Clinic']);
    $device = WuzDevice::factory()->forOwner($owner)->create([
        'connected' => true,
        'jid' => '5511999999999:1@s.whatsapp.net',
    ]);

    app(ReregisterDeviceAction::class)->handle($device);

    expect($device->fresh()->connected)->toBeFalse()
        ->and($device->fresh()->jid)->toBeNull();
});

it('issues the webhook URL for the new token, not the token the gateway forgot', function () {
    $owner = TestOwner::create(['name' => 'Clinic']);
    $device = WuzDevice::factory()->forOwner($owner)->create(['token' => 'device-old']);

    app(ReregisterDeviceAction::class)->handle($device);

    $newToken = $device->fresh()->token;

    Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/users')
        && $r['token'] === $newToken
        && str_contains($r['webhook'], $newToken));
});

it('carries the device proxy over to the new gateway user', function () {
    $owner = TestOwner::create(['name' => 'Clinic']);
    $device = WuzDevice::factory()->forOwner($owner)->create([
        'proxy_url' => 'http://u:p@geo.iproyal.com:12321',
    ]);

    app(ReregisterDeviceAction::class)->handle($device);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/users')
        && ($r['proxyConfig']['proxyURL'] ?? null) === 'http://u:p@geo.iproyal.com:12321');
});
