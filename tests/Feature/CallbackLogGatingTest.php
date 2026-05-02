<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

function deviceWithToken(string $token): WuzDevice
{
    $owner = TestOwner::create(['name' => 'O']);
    return WuzDevice::factory()->for($owner, 'owner')->create(['token' => $token]);
}

it('inserts a log row when the type is in the allowlist', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::CONNECTED]);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    expect(WuzCallbackLog::count())->toBe(1);
});

it('skips log insertion when the type is not in the allowlist', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::CONNECTED]);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    expect(WuzCallbackLog::count())->toBe(0);
});

it('logs every type when the allowlist is the wildcard', function () {
    config()->set('wuz.logging.event_types', ['*']);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    expect(WuzCallbackLog::count())->toBe(1);
});

it('logs nothing when the allowlist is empty', function () {
    config()->set('wuz.logging.event_types', []);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    expect(WuzCallbackLog::count())->toBe(0);
});

it('accepts string entries alongside enum cases', function () {
    config()->set('wuz.logging.event_types', ['Connected', WuzEventType::DISCONNECTED]);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect(WuzCallbackLog::count())->toBe(2);
});

it('falls back to defaultLoggingTypes when the config key is null', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    // CONNECTED is in defaults, ChatPresence is not.
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    expect(WuzCallbackLog::count())->toBe(1);
});

it('does not log MESSAGE under defaults', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', [
        'type' => 'Message',
        'Info' => ['RemoteJid' => 'x@s.whatsapp.net'],
        'Message' => ['conversation' => 'hi'],
    ]);

    expect(WuzCallbackLog::count())->toBe(0);
});

it('logs UNKNOWN payload types under defaults and preserves the raw type', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'BrandNewEvent']);

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('BrandNewEvent');
});

it('matches a raw-string allowlist entry against the payload type', function () {
    config()->set('wuz.logging.event_types', ['FooEvent']);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'FooEvent']);

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('FooEvent');
});

it('does not throw and logs UNKNOWN for malformed type fields', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', []);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => null]);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 42]);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => ['x']]);

    expect(WuzCallbackLog::count())->toBe(4);
    expect(WuzCallbackLog::all()->pluck('event_type')->all())->each->toBe('Unknown');
});
