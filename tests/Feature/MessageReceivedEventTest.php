<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

function messagePayload(array $message): array
{
    return [
        'type' => 'Message',
        'Info' => [
            'RemoteJid' => '5511@s.whatsapp.net',
            'Sender' => ['User' => '5511999999999'],
        ],
        'Message' => $message,
    ];
}

it('dispatches MessageReceived with parsed text fields', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'msg-token']);

    app(HandleWebhookCallbackAction::class)->handle(
        'msg-token',
        messagePayload(['conversation' => 'Hello!']),
    );

    Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) use ($device) {
        return $event->device->id === $device->id
            && $event->type === 'text'
            && $event->chatJid === '5511@s.whatsapp.net'
            && $event->senderJid === '5511999999999'
            && $event->content === 'Hello!'
            && is_array($event->payload);
    });
});

it('parses extendedTextMessage as text', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['extendedTextMessage' => ['text' => 'extended hello']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'text' && $e->content === 'extended hello'
    );
});

it('parses imageMessage with caption', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['imageMessage' => ['caption' => 'a photo', 'url' => 'https://x']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'image' && $e->content === 'a photo'
    );
});

it('parses videoMessage with caption', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['videoMessage' => ['caption' => 'a clip']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'video' && $e->content === 'a clip'
    );
});

it('parses documentMessage with filename', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['documentMessage' => ['fileName' => 'report.pdf']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'document' && $e->content === 'report.pdf'
    );
});

it('does not dispatch MessageReceived when RemoteJid is missing', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    $payload = [
        'type' => 'Message',
        'Info' => ['Sender' => ['User' => '5511999999999']],
        'Message' => ['conversation' => 'orphan'],
    ];

    app(HandleWebhookCallbackAction::class)->handle('t', $payload);

    Event::assertNotDispatched(MessageReceived::class);
});
