<?php

use JordanMiguel\Wuz\Enums\WuzEventType;

afterEach(function () {
    config()->set('wuz.logging.event_types', null);
    config()->set('wuz.webhook_event.event_types', null);
});

it('defaultLoggingTypes excludes MESSAGE', function () {
    expect(WuzEventType::defaultLoggingTypes())
        ->not->toContain(WuzEventType::MESSAGE);
});

it('defaultLoggingTypes includes QR_TIMEOUT', function () {
    expect(WuzEventType::defaultLoggingTypes())
        ->toContain(WuzEventType::QR_TIMEOUT);
});

it('defaultLoggingTypes contains lifecycle, pairing, error, and UNKNOWN types', function () {
    $defaults = WuzEventType::defaultLoggingTypes();
    expect($defaults)->toContain(
        WuzEventType::CONNECTED,
        WuzEventType::DISCONNECTED,
        WuzEventType::LOGGED_OUT,
        WuzEventType::PAIR_SUCCESS,
        WuzEventType::PAIR_ERROR,
        WuzEventType::QR,
        WuzEventType::QR_TIMEOUT,
        WuzEventType::CONNECT_FAILURE,
        WuzEventType::STREAM_ERROR,
        WuzEventType::TEMPORARY_BAN,
        WuzEventType::CLIENT_OUTDATED,
        WuzEventType::UNKNOWN,
    );
});

it('defaultDispatchTypes is MESSAGE plus the three lifecycle types', function () {
    expect(WuzEventType::defaultDispatchTypes())->toEqualCanonicalizing([
        WuzEventType::MESSAGE,
        WuzEventType::CONNECTED,
        WuzEventType::DISCONNECTED,
        WuzEventType::LOGGED_OUT,
    ]);
});

it('shouldLog returns true when the case is in the configured allowlist', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::MESSAGE]);
    expect(WuzEventType::MESSAGE->shouldLog())->toBeTrue();
    expect(WuzEventType::CONNECTED->shouldLog())->toBeFalse();
});

it('shouldLog accepts string entries that match the case value', function () {
    config()->set('wuz.logging.event_types', ['Message']);
    expect(WuzEventType::MESSAGE->shouldLog())->toBeTrue();
});

it('shouldLog accepts the wildcard *', function () {
    config()->set('wuz.logging.event_types', ['*']);
    expect(WuzEventType::CHAT_PRESENCE->shouldLog())->toBeTrue();
});

it('shouldLog returns false for an empty allowlist', function () {
    config()->set('wuz.logging.event_types', []);
    expect(WuzEventType::MESSAGE->shouldLog())->toBeFalse();
});

it('shouldLog falls back to defaultLoggingTypes when config is missing', function () {
    config()->set('wuz.logging.event_types', null);
    expect(WuzEventType::CONNECTED->shouldLog())->toBeTrue();
    expect(WuzEventType::MESSAGE->shouldLog())->toBeFalse();
});

it('shouldLog matches the raw payload string when no enum case exists for it', function () {
    config()->set('wuz.logging.event_types', ['FooEvent']);
    expect(WuzEventType::UNKNOWN->shouldLog('FooEvent'))->toBeTrue();
    expect(WuzEventType::UNKNOWN->shouldLog('OtherEvent'))->toBeFalse();
    expect(WuzEventType::UNKNOWN->shouldLog(null))->toBeFalse();
});

it('shouldDispatch is symmetric with shouldLog', function () {
    config()->set('wuz.webhook_event.event_types', [WuzEventType::CONNECTED]);
    expect(WuzEventType::CONNECTED->shouldDispatch())->toBeTrue();
    expect(WuzEventType::DISCONNECTED->shouldDispatch())->toBeFalse();
});

it('accepts a scalar wildcard config value', function () {
    config()->set('wuz.logging.event_types', '*');
    expect(WuzEventType::CHAT_PRESENCE->shouldLog())->toBeTrue();

    config()->set('wuz.webhook_event.event_types', '*');
    expect(WuzEventType::CHAT_PRESENCE->shouldDispatch())->toBeTrue();
});

it('accepts a scalar enum case as the config value', function () {
    config()->set('wuz.logging.event_types', WuzEventType::CONNECTED);
    expect(WuzEventType::CONNECTED->shouldLog())->toBeTrue();
    expect(WuzEventType::DISCONNECTED->shouldLog())->toBeFalse();
});

it('accepts a scalar string as the config value', function () {
    config()->set('wuz.webhook_event.event_types', 'Connected');
    expect(WuzEventType::CONNECTED->shouldDispatch())->toBeTrue();
    expect(WuzEventType::DISCONNECTED->shouldDispatch())->toBeFalse();
});
