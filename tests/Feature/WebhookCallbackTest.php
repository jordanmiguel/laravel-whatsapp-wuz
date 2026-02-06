<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

it('logs callbacks and dispatches WebhookReceived event', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'callback-token-123',
        'device_id' => 'wuz-1',
    ]);

    $action = app(HandleWebhookCallbackAction::class);
    $action->handle('callback-token-123', ['type' => 'Receipt'], '127.0.0.1', 'TestAgent');

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('Receipt');
    Event::assertDispatched(WebhookReceived::class);
});

it('handles MESSAGE events and stores device messages', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'msg-token',
        'device_id' => 'wuz-1',
    ]);

    $payload = [
        'type' => 'Message',
        'Info' => [
            'RemoteJid' => '5511@s.whatsapp.net',
            'Sender' => ['User' => '5511999999999'],
        ],
        'Message' => [
            'conversation' => 'Hello from WhatsApp!',
        ],
    ];

    app(HandleWebhookCallbackAction::class)->handle('msg-token', $payload);

    expect(WuzDeviceMessage::count())->toBe(1);
    $msg = WuzDeviceMessage::first();
    expect($msg->message)->toBe('Hello from WhatsApp!');
    expect($msg->type)->toBe('text');
    expect($msg->chat_jid)->toBe('5511@s.whatsapp.net');

    Event::assertDispatched(MessageReceived::class);
});

it('handles DISCONNECTED events', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'disc-token',
        'device_id' => 'wuz-1',
        'connected' => true,
    ]);

    app(HandleWebhookCallbackAction::class)->handle('disc-token', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
    Event::assertDispatched(DeviceDisconnected::class);
});

it('handles LOGGED_OUT events and clears JID', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'logout-token',
        'device_id' => 'wuz-1',
        'connected' => true,
        'jid' => '5511@s.whatsapp.net',
    ]);

    app(HandleWebhookCallbackAction::class)->handle('logout-token', ['type' => 'LoggedOut']);

    expect($device->fresh()->connected)->toBeFalse();
    expect($device->fresh()->jid)->toBeNull();
    Event::assertDispatched(DeviceDisconnected::class);
});

it('ignores callbacks for unknown tokens', function () {
    app(HandleWebhookCallbackAction::class)->handle('non-existent-token', ['type' => 'Message']);

    expect(WuzCallbackLog::count())->toBe(0);
    Event::assertNotDispatched(WebhookReceived::class);
});

it('handles extended text messages', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'ext-token',
        'device_id' => 'wuz-1',
    ]);

    $payload = [
        'type' => 'Message',
        'Info' => ['RemoteJid' => 'jid', 'Sender' => ['User' => 'sender']],
        'Message' => [
            'extendedTextMessage' => ['text' => 'Extended text with link'],
        ],
    ];

    app(HandleWebhookCallbackAction::class)->handle('ext-token', $payload);

    expect(WuzDeviceMessage::first()->message)->toBe('Extended text with link');
});
