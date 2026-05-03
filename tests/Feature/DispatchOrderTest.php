<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
});

function orderDevice(string $token = 't'): WuzDevice
{
    $owner = TestOwner::create(['name' => 'O']);
    return WuzDevice::factory()->for($owner, 'owner')->connected()->create(['token' => $token]);
}

it('does not propagate WebhookReceived listener exceptions', function () {
    Exceptions::fake();
    config()->set('wuz.webhook_event.event_types', ['*']);
    Event::listen(WebhookReceived::class, fn () => throw new RuntimeException('boom'));
    $device = orderDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('does not propagate DeviceDisconnected listener exceptions', function () {
    Exceptions::fake();
    Event::listen(DeviceDisconnected::class, fn () => throw new RuntimeException('boom'));
    $device = orderDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('does not propagate MessageReceived listener exceptions and still inserts the log row when applicable', function () {
    Exceptions::fake();
    config()->set('wuz.logging.event_types', ['*']);
    Event::listen(MessageReceived::class, fn () => throw new RuntimeException('boom'));
    orderDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', [
        'type' => 'Message',
        'Info' => ['RemoteJid' => 'x@s.whatsapp.net'],
        'Message' => ['conversation' => 'hi'],
    ]);

    expect(WuzCallbackLog::count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('keeps state mutations on Disconnected even when log insert throws', function () {
    config()->set('wuz.logging.event_types', ['*']);
    $device = orderDevice();

    // Drop the table to force WuzCallbackLog::create() to throw.
    $device->getConnection()->getSchemaBuilder()->drop(
        config('wuz.table_names.callback_logs', 'wuz_callback_logs'),
    );

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
});
