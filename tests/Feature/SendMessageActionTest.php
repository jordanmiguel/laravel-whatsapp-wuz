<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Tests\Fixtures\TestTenant;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => 'lid123']], 200),
        '*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200),
        '*/chat/send/image' => Http::response(['data' => ['sent' => true]], 200),
        '*/chat/send/buttons' => Http::response(['data' => ['sent' => true]], 200),
    ]);
});

it('sends a text message and stores it', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => true,
        'jid' => 'my@jid',
    ]);

    $action = app(SendMessageAction::class);
    $message = $action->handle($device, new SendMessageData(
        phone: '011999999999',
        type: 'text',
        message: 'Hello from test!',
    ));

    expect($message)->toBeInstanceOf(WuzDeviceMessage::class);
    expect($message->message)->toBe('Hello from test!');
    expect($message->type)->toBe('text');
    expect($message->wuz_device_id)->toBe($device->id);
});

it('normalizes phone and resolves JID', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => true,
    ]);

    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '011999999999',
        message: 'Hi',
    ));

    $phoneJid = WuzPhoneJid::first();
    expect($phoneJid->phone)->toBe('5511999999999');
    expect($phoneJid->jid)->toBe('5511@s.whatsapp.net');
});

it('sends a button message', function () {
    $tenant = TestTenant::create(['name' => 'Test']);
    $device = $tenant->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => true,
    ]);

    $message = app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '5511999999999',
        type: 'button',
        message: 'Choose an option',
        buttons: [['buttonId' => '1', 'buttonText' => ['displayText' => 'Option 1']]],
    ));

    expect($message->type)->toBe('button');
    expect($message->message)->toBe('Choose an option');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/send/buttons'));
});
