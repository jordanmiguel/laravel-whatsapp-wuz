<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

function gatingDevice(string $token = 't'): WuzDevice
{
    $owner = TestOwner::create(['name' => 'O']);
    return WuzDevice::factory()->for($owner, 'owner')->create(['token' => $token]);
}

it('dispatches WebhookReceived when the type is in the allowlist', function () {
    config()->set('wuz.webhook_event.event_types', [WuzEventType::CONNECTED]);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    Event::assertDispatched(WebhookReceived::class);
});

it('does not dispatch WebhookReceived when the type is not in the allowlist', function () {
    config()->set('wuz.webhook_event.event_types', [WuzEventType::CONNECTED]);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    Event::assertNotDispatched(WebhookReceived::class);
});

it('keeps logging and dispatch allowlists independent', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::CONNECTED]);
    config()->set('wuz.webhook_event.event_types', []);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    Event::assertNotDispatched(WebhookReceived::class);
    expect(\JordanMiguel\Wuz\Models\WuzCallbackLog::count())->toBe(1);
});

it('matches a raw-string allowlist entry against the payload type', function () {
    config()->set('wuz.webhook_event.event_types', ['FooEvent']);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'FooEvent']);

    Event::assertDispatched(WebhookReceived::class);
});
