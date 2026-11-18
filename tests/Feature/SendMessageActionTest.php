<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Events\MessageSent;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Event::fake();
});

it('sends a text message and dispatches MessageSent', function () {
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

    expect($message)->toBeArray()
        ->and($message['data']['id'] ?? null)->toBe('msg-1');

    Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($device) {
        return $e->device->id === $device->id
            && $e->type === 'text'
            && $e->content === 'Hello from test!';
    });
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

    expect($message)->toBeArray();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) =>
        $e->type === 'button' && $e->content === 'Choose an option'
    );

    Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/send/buttons'));
});

it('throws when phone is not registered on WhatsApp', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response('Not found', 404),
        '*/user/check' => Http::response(['data' => ['Users' => [['Query' => '441234567890', 'IsInWhatsapp' => false, 'JID' => '']]]], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '+441234567890',
        message: 'Hello',
    ));
})->throws(WuzApiException::class);

// WhatsApp may confirm only the non-canonical Brazilian form, answering with the LID alone.
// The session maps that LID to the canonical number, so sending to the queried form fails
// ("no LID found for … from server"): the LID is the only address that is known to work.
it('sends to the LID when WhatsApp confirmed the number only by its LID', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response('Not found', 404),
        '*/user/check' => Http::response(['data' => ['Users' => [
            ['Query' => '5538999037449', 'IsInWhatsapp' => true, 'JID' => '243808616845420@lid', 'VerifiedName' => ''],
        ]]], 200),
        '*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(phone: '5538999037449', message: 'Oi'));

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chat/send/text')
        && $request['Phone'] === '243808616845420@lid');
    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->phone === '5538999037449');
});

it('sends to the cached LID when the number is known only by it', function () {
    Http::preventStrayRequests();
    Http::fake(['*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200)]);
    WuzPhoneJid::create(['phone' => '5538999037449', 'jid' => '243808616845420@lid', 'lid' => '243808616845420@lid']);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(phone: '5538999037449', message: 'Oi'));

    Http::assertSent(fn ($request) => $request['Phone'] === '243808616845420@lid');
});

it('keeps sending to the phone when its JID is a phone number', function () {
    Http::preventStrayRequests();
    Http::fake(['*/chat/send/text' => Http::response(['data' => ['sent' => true, 'id' => 'msg-1']], 200)]);
    WuzPhoneJid::create(['phone' => '553899037449', 'jid' => '553899037449@s.whatsapp.net', 'lid' => '243808616845420@lid']);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(phone: '5538999037449', message: 'Oi'));

    Http::assertSent(fn ($request) => $request['Phone'] === '553899037449');
});

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
    expect($message)->toBeArray();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) =>
        $e->phone === '552188888888' && $e->content === 'Hello debug!'
    );
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
    Event::assertNotDispatched(MessageSent::class);
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
    expect($message)->toBeArray();
    Event::assertDispatched(MessageSent::class);
});
