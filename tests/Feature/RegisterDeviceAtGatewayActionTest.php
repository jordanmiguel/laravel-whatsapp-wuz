<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\RegisterDeviceAtGatewayAction;
use JordanMiguel\Wuz\Exceptions\WuzApiException;

it('mints an unguessable token', function () {
    Http::fake(['*/admin/users' => Http::response(['data' => ['id' => 'gw-1']], 200)]);

    // The token authenticates every session call to WuzAPI and is the only thing authenticating an
    // inbound webhook, so it must come from a CSPRNG. A time-based token (uniqid) leaves an
    // attacker guessing within a microsecond window of the moment the device was provisioned.
    $first = app(RegisterDeviceAtGatewayAction::class)->handle('Clinic');
    $second = app(RegisterDeviceAtGatewayAction::class)->handle('Clinic');

    expect($first->token)->toMatch('/^device-[A-Za-z0-9]{32}$/')
        ->and($second->token)->not->toBe($first->token);
});

it('refuses a registration WuzAPI cannot name', function () {
    // The admin endpoints address a user by this id, so a device without one could never be
    // deleted or repaired. Persisting it would bury the failure in a row nobody can act on.
    Http::fake(['*/admin/users' => Http::response(['data' => []], 200)]);

    expect(fn () => app(RegisterDeviceAtGatewayAction::class)->handle('Clinic'))
        ->toThrow(WuzApiException::class);
});

it('issues the webhook URL for the token it just minted', function () {
    Http::fake(['*/admin/users' => Http::response(['data' => ['id' => 'gw-1']], 200)]);

    $credentials = app(RegisterDeviceAtGatewayAction::class)->handle('Clinic');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/users')
        && $r['token'] === $credentials->token
        && str_contains($r['webhook'], $credentials->token));
});
