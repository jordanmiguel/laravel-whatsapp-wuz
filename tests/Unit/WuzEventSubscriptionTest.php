<?php

use JordanMiguel\Wuz\Enums\WuzEventSubscription;

it('exposes All as a sentinel case', function () {
    expect(WuzEventSubscription::ALL->value)->toBe('All');
});

it('exposes the subscription strings WUZ recognises', function (string $value, WuzEventSubscription $expected) {
    expect(WuzEventSubscription::tryFrom($value))->toBe($expected);
})->with([
    ['All', WuzEventSubscription::ALL],
    ['Message', WuzEventSubscription::MESSAGE],
    ['ReadReceipt', WuzEventSubscription::READ_RECEIPT],
    ['Presence', WuzEventSubscription::PRESENCE],
    ['HistorySync', WuzEventSubscription::HISTORY_SYNC],
    ['ChatPresence', WuzEventSubscription::CHAT_PRESENCE],
]);

it('returns null for unrecognised subscription values', function () {
    expect(WuzEventSubscription::tryFrom('Nope'))->toBeNull();
});
