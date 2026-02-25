<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

it('sends a text message and stores it', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => 'lid123']], 200),
        '*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

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
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => 'lid123']], 200),
        '*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '011999999999',
        message: 'Hi',
    ));

    $phoneJid = WuzPhoneJid::first();
    expect($phoneJid->phone)->toBe('551199999999');
    expect($phoneJid->jid)->toBe('5511@s.whatsapp.net');
});

it('sends a button message', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => 'lid123']], 200),
        '*/chat/send/buttons' => Http::response(['data' => ['sent' => true]], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

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

it('throws when phone is not registered on WhatsApp', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response('Not found', 404),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '+441234567890',
        message: 'Hello',
    ));
})->throws(WuzApiException::class);

it('redirects message to debug phone when WUZ_DEBUG is enabled', function () {
    config([
        'wuz.debug.enabled' => true,
        'wuz.debug.to' => '552188888888',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5521@s.whatsapp.net', 'lid' => 'lid123']], 200),
        '*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $message = app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999',
        type: 'text',
        message: 'Hello debug!',
    ));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/user/lid/552188888888'));
    expect($message)->toBeInstanceOf(WuzDeviceMessage::class);
    expect($message->message)->toBe('Hello debug!');
});

it('logs and skips sending when WUZ_DEBUG is enabled without WUZ_DEBUG_TO', function () {
    config([
        'wuz.debug.enabled' => true,
        'wuz.debug.to' => null,
    ]);

    Http::preventStrayRequests();
    Log::spy();

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $result = app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '5511999999999',
        type: 'text',
        message: 'Hello debug!',
    ));

    Http::assertNothingSent();
    expect($result)->toBeNull();
    expect(WuzDeviceMessage::count())->toBe(0);
    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn ($msg, $context) => str_contains($msg, 'Wuz debug') && $context['phone'] === '5511999999999');
});

it('sends normally when WUZ_DEBUG is disabled', function () {
    config(['wuz.debug.enabled' => false]);

    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => 'lid123']], 200),
        '*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $message = app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999',
        type: 'text',
        message: 'Hello!',
    ));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/user/lid/551199999999'));
    expect($message)->toBeInstanceOf(WuzDeviceMessage::class);
});
