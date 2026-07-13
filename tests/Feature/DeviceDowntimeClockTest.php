<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\GetDeviceStatusAction;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

function pairedDevice(array $attributes = []): WuzDevice
{
    $owner = TestOwner::create(['name' => 'Test']);

    return WuzDevice::factory()->for($owner, 'owner')->create([
        'connected' => true,
        'jid' => '5511999990000@s.whatsapp.net',
        ...$attributes,
    ]);
}

it('starts the clock when a live session drops', function () {
    $device = pairedDevice();

    app(HandleWebhookCallbackAction::class)->handle($device->token, ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse()
        ->and($device->fresh()->disconnected_at)->not->toBeNull();
});

it('does not restart a clock that is already running', function () {
    // A flapping socket has been down since the FIRST drop — restarting the clock on every
    // Disconnected would make a session that never came back look perpetually fresh.
    $downSince = now()->subHours(3)->startOfSecond();
    $device = pairedDevice(['connected' => false, 'disconnected_at' => $downSince]);

    app(HandleWebhookCallbackAction::class)->handle($device->token, ['type' => 'Disconnected']);

    expect($device->fresh()->disconnected_at->eq($downSince))->toBeTrue();
});

it('stops the clock when the session comes back', function () {
    $device = pairedDevice(['connected' => false, 'disconnected_at' => now()->subMinutes(5)]);

    app(HandleWebhookCallbackAction::class)->handle($device->token, ['type' => 'Connected']);

    expect($device->fresh()->connected)->toBeTrue()
        ->and($device->fresh()->disconnected_at)->toBeNull();
});

it('never starts the clock for a device that was never paired', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['connected' => false, 'jid' => null]);

    app(HandleWebhookCallbackAction::class)->handle($device->token, ['type' => 'Disconnected']);

    // Unconfigured is not "down": a null clock is what tells the two apart.
    expect($device->fresh()->disconnected_at)->toBeNull();
});

it('clears the jid only when the phone is really unlinked', function () {
    $device = pairedDevice();

    app(HandleWebhookCallbackAction::class)->handle($device->token, ['type' => 'LoggedOut']);

    expect($device->fresh()->jid)->toBeNull()
        ->and($device->fresh()->disconnected_at)->not->toBeNull();
});

it('does not resurrect the jid of a logged-out device on the next poll', function () {
    // WuzAPI keeps serving the last known jid long after a logout — persisting it here would
    // undo the LoggedOut handler that deliberately cleared it.
    Http::fake([
        '*/session/status' => Http::response(['data' => [
            'connected' => false, 'loggedIn' => false, 'jid' => '5511999990000@s.whatsapp.net',
        ]], 200),
        '*/webhook' => Http::response(['success' => true], 200),
        '*/session/qr' => Http::response(['data' => []], 200),
    ]);
    $device = pairedDevice(['connected' => false, 'jid' => null, 'disconnected_at' => now()->subHour()]);

    app(GetDeviceStatusAction::class)->handle($device);

    expect($device->fresh()->jid)->toBeNull();
});

it('keeps the session up when only the socket dropped', function () {
    // The exact production case: whatsmeow is mid-reconnect, so the websocket is down while the
    // session stays authenticated. `connected` must follow loggedIn, not the socket.
    Http::fake([
        '*/session/status' => Http::response(['data' => [
            'connected' => false, 'loggedIn' => true, 'jid' => '5511999990000@s.whatsapp.net',
        ]], 200),
        '*/webhook' => Http::response(['success' => true], 200),
    ]);
    $device = pairedDevice(['connected' => false, 'disconnected_at' => now()->subMinute()]);

    $status = app(GetDeviceStatusAction::class)->handle($device);

    expect($status->connected)->toBeTrue()
        ->and($status->socket_connected)->toBeFalse()
        ->and($device->fresh()->connected)->toBeTrue()
        ->and($device->fresh()->disconnected_at)->toBeNull();
});

it('refuses to answer when WuzAPI cannot be reached', function () {
    // Silence is not a logged-out device. Swallowing this would let one WuzAPI outage look like
    // every workspace unlinking their phone at once.
    Http::fake(['*' => Http::response('gateway down', 502)]);
    $device = pairedDevice();

    expect(fn () => app(GetDeviceStatusAction::class)->handle($device))
        ->toThrow(WuzApiException::class);

    expect($device->fresh()->connected)->toBeTrue()
        ->and($device->fresh()->jid)->not->toBeNull();
});
