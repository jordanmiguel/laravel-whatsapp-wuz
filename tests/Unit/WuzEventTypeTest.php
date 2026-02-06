<?php

use JordanMiguel\Wuz\Enums\WuzEventType;

it('detects known event types from payload', function (string $type, WuzEventType $expected) {
    expect(WuzEventType::detect(['type' => $type]))->toBe($expected);
})->with([
    ['Message', WuzEventType::MESSAGE],
    ['Disconnected', WuzEventType::DISCONNECTED],
    ['LoggedOut', WuzEventType::LOGGED_OUT],
    ['Connected', WuzEventType::CONNECTED],
    ['QR', WuzEventType::QR],
    ['Receipt', WuzEventType::RECEIPT],
    ['All', WuzEventType::ALL],
]);

it('falls back to UNKNOWN for unrecognized types', function () {
    expect(WuzEventType::detect(['type' => 'SomethingNew']))->toBe(WuzEventType::UNKNOWN);
});

it('falls back to UNKNOWN when type key is missing', function () {
    expect(WuzEventType::detect([]))->toBe(WuzEventType::UNKNOWN);
});

it('returns human-readable labels', function () {
    expect(WuzEventType::MESSAGE->label())->toBe('Message');
    expect(WuzEventType::DISCONNECTED->label())->toBe('Disconnected');
    expect(WuzEventType::QR->label())->toBe('QR Code');
    expect(WuzEventType::CALL_OFFER->label())->toBe('Incoming Call');
    expect(WuzEventType::UNKNOWN->label())->toBe('Unknown');
});

it('has correct string values for all cases', function () {
    expect(WuzEventType::MESSAGE->value)->toBe('Message');
    expect(WuzEventType::UNDECRYPTABLE_MESSAGE->value)->toBe('UndecryptableMessage');
    expect(WuzEventType::FB_MESSAGE->value)->toBe('FBMessage');
});
