<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\GetDeviceStatusAction;
use JordanMiguel\Wuz\Data\DeviceStatusData;
use JordanMiguel\Wuz\Events\DeviceConnected;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('returns connected status when device is logged in', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => true, 'jid' => '5511@s.whatsapp.net']], 200),
        '*/webhook' => Http::response(['success' => true], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create();

    Event::fake();

    $action = app(GetDeviceStatusAction::class);
    $result = $action->handle($device);

    expect($result)->toBeInstanceOf(DeviceStatusData::class);
    expect($result->connected)->toBeTrue();
    expect($result->status)->toBe('connected');
    expect($result->jid)->toBe('5511@s.whatsapp.net');

    Event::assertDispatched(DeviceConnected::class);
});

it('returns QR status when the caller asks for one and pairing is underway', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => false, 'connected' => true, 'jid' => null]], 200),
        '*/webhook' => Http::response(['success' => true], 200),
        '*/session/qr' => Http::response(['data' => ['QRCode' => 'base64-qr-data']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create();

    $action = app(GetDeviceStatusAction::class);
    $result = $action->handle($device, withQr: true);

    expect($result->status)->toBe('qr');
    expect($result->qr_code)->toBe('base64-qr-data');
});

it('does not request a QR unless the caller asks for one', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => false, 'connected' => true, 'jid' => null]], 200),
        '*/webhook' => Http::response(['success' => true], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create();

    $result = app(GetDeviceStatusAction::class)->handle($device);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/session/qr'));
    expect($result->qr_code)->toBeNull();
    expect($result->status)->toBe('disconnected');
});

it('does not request a QR while the socket is not up, where WuzAPI can only answer "no session"', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => false, 'connected' => false, 'jid' => null]], 200),
        '*/webhook' => Http::response(['success' => true], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create();

    $result = app(GetDeviceStatusAction::class)->handle($device, withQr: true);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/session/qr'));
    expect($result->qr_code)->toBeNull();
    expect($result->status)->toBe('disconnected');
});

it('updates device connected state in database', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => true, 'jid' => 'jid123']], 200),
        '*/webhook' => Http::response(['success' => true], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create();

    Event::fake();

    app(GetDeviceStatusAction::class)->handle($device);

    expect($device->fresh()->connected)->toBeTrue();
    expect($device->fresh()->jid)->toBe('jid123');
});

describe('webhook subscription', function () {
    function statusWithSubscription(WuzDevice $device, ?string $webhook, ?string $events): array
    {
        return ['data' => array_filter([
            'loggedIn' => true,
            'connected' => true,
            'jid' => '5511@s.whatsapp.net',
            'webhook' => $webhook,
            'events' => $events,
        ], fn ($value) => $value !== null)];
    }

    function expectedWebhook(WuzDevice $device): string
    {
        return route('wuz.webhook', ['token' => $device->token]);
    }

    it('leaves a correct subscription alone instead of rewriting it on every poll', function () {
        $device = WuzDevice::factory()->for(TestOwner::create(['name' => 'Test']), 'owner')->create();
        Http::fake([
            '*/session/status' => Http::response(statusWithSubscription($device, expectedWebhook($device), 'All'), 200),
            '*/webhook' => Http::response(['success' => true], 200), // faked so a rewrite would be recorded, not swallowed
        ]);

        app(GetDeviceStatusAction::class)->handle($device);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/webhook'));
    });

    it('re-arms the subscription when WuzAPI reports something else', function (?string $webhook, ?string $events) {
        $device = WuzDevice::factory()->for(TestOwner::create(['name' => 'Test']), 'owner')->create();
        Http::fake([
            '*/session/status' => Http::response(statusWithSubscription($device, $webhook, $events), 200),
            '*/webhook' => Http::response(['success' => true], 200),
        ]);

        app(GetDeviceStatusAction::class)->handle($device);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/webhook')
            && $request['webhook'] === expectedWebhook($device));
    })->with([
        'another url' => ['https://old.example.test/api/wuz/webhook/stale', 'All'],
        'no url' => ['', 'All'],
        'narrower events' => [null, 'Message,ReadReceipt'],
        'a WuzAPI that does not report the subscription' => [null, null],
    ]);
});
