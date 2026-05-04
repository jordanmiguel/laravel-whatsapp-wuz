<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Events\DeviceConnected;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

it('logs callbacks and dispatches WebhookReceived event', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'callback-token-123']);

    $action = app(HandleWebhookCallbackAction::class);
    $action->handle('callback-token-123', ['type' => 'Connected'], '127.0.0.1', 'TestAgent');

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('Connected');
    Event::assertDispatched(WebhookReceived::class);
});

it('dispatches MessageReceived with parsed text fields for MESSAGE webhooks', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'msg-token']);

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

    Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) use ($device) {
        return $event->device->id === $device->id
            && $event->type === 'text'
            && $event->chatJid === '5511@s.whatsapp.net'
            && $event->senderJid === '5511999999999'
            && $event->content === 'Hello from WhatsApp!';
    });
});

it('handles DISCONNECTED events', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create(['token' => 'disc-token']);

    app(HandleWebhookCallbackAction::class)->handle('disc-token', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
    Event::assertDispatched(DeviceDisconnected::class);
});

it('handles CONNECTED events', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'conn-token']);

    expect($device->connected)->toBeFalse();

    app(HandleWebhookCallbackAction::class)->handle('conn-token', ['type' => 'Connected']);

    expect($device->fresh()->connected)->toBeTrue();
    Event::assertDispatched(DeviceConnected::class, fn (DeviceConnected $e) => $e->device->id === $device->id);
});

it('handles LOGGED_OUT events and clears JID', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create(['token' => 'logout-token']);

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
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'ext-token']);

    $payload = [
        'type' => 'Message',
        'Info' => ['RemoteJid' => 'jid', 'Sender' => ['User' => 'sender']],
        'Message' => [
            'extendedTextMessage' => ['text' => 'Extended text with link'],
        ],
    ];

    app(HandleWebhookCallbackAction::class)->handle('ext-token', $payload);

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $e) =>
        $e->type === 'text' && $e->content === 'Extended text with link'
    );
});

it('unwraps the WUZ webhook envelope to detect event type', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'env-token']);

    $envelope = [
        'instanceName' => 'instance-name',
        'jsonData' => json_encode(['event' => new \stdClass(), 'type' => 'Connected']),
        'userID' => 'user-id',
    ];

    app(HandleWebhookCallbackAction::class)->handle('env-token', $envelope);

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('Connected');
    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) =>
        $e->eventType === \JordanMiguel\Wuz\Enums\WuzEventType::CONNECTED
    );
});

it('unwraps the envelope and runs Disconnected side effects', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create(['token' => 'env-disc']);

    $envelope = [
        'instanceName' => 'instance-name',
        'jsonData' => json_encode(['event' => new \stdClass(), 'type' => 'Disconnected']),
        'userID' => 'user-id',
    ];

    app(HandleWebhookCallbackAction::class)->handle('env-disc', $envelope);

    expect($device->fresh()->connected)->toBeFalse();
    Event::assertDispatched(DeviceDisconnected::class);
});

it('unwraps the envelope and exposes nested event keys to MessageReceived', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'env-msg']);

    $envelope = [
        'instanceName' => 'instance-name',
        'jsonData' => json_encode([
            'event' => [
                'Info' => [
                    'RemoteJid' => '5511@s.whatsapp.net',
                    'Sender' => ['User' => '5511999999999'],
                ],
                'Message' => ['conversation' => 'Hello via envelope'],
            ],
            'type' => 'Message',
        ]),
        'userID' => 'user-id',
    ];

    app(HandleWebhookCallbackAction::class)->handle('env-msg', $envelope);

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $e) =>
        $e->chatJid === '5511@s.whatsapp.net'
            && $e->senderJid === '5511999999999'
            && $e->content === 'Hello via envelope'
    );
});

it('logs Unknown when the envelope jsonData is malformed', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'env-bad']);

    $envelope = [
        'instanceName' => 'instance-name',
        'jsonData' => '{not valid json',
        'userID' => 'user-id',
    ];

    app(HandleWebhookCallbackAction::class)->handle('env-bad', $envelope);

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('Unknown');
});
