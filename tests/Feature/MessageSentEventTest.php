<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Events\MessageSent;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
});

// Note: Event::fake() is called per-test rather than in beforeEach because the
// listener-isolation test needs the real dispatcher to actually run the listener.

function fakeWuzPhoneAndSend(): void
{
    Http::fake([
        '*/user/lid/*' => Http::response([
            'data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null],
        ], 200),
        '*/chat/send/text' => Http::response([
            'code' => 200,
            'data' => ['id' => 'wamid.123'],
        ], 200),
    ]);
}

// Tests use 12-digit numbers that bypass BrazilianPhoneFallback (which
// only triggers for 13-digit Brazilian mobile numbers via `.isBrazilian()`).
// This keeps phone resolution single-step and assertions deterministic.

it('dispatches MessageSent with the API response on a successful text send', function () {
    Event::fake();
    fakeWuzPhoneAndSend();
    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $response = app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    expect($response)->toBeArray()
        ->and($response['data']['id'] ?? null)->toBe('wamid.123');

    Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($device) {
        return $e->device->id === $device->id
            && $e->type === 'text'
            && $e->phone === '551199999999'
            && $e->content === 'hi';
    });
});

it('dispatches MessageSent for image, video, and document sends', function () {
    Event::fake();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null]], 200),
        '*/chat/send/image' => Http::response(['data' => ['id' => 'img-1']], 200),
        '*/chat/send/video' => Http::response(['data' => ['id' => 'vid-1']], 200),
        '*/chat/send/document' => Http::response(['data' => ['id' => 'doc-1']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999', type: 'image', message: '', caption: 'photo', media: 'data:image/jpeg;base64,AAA',
    ));
    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999', type: 'video', message: '', caption: 'clip', media: 'data:video/mp4;base64,AAA',
    ));
    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999', type: 'document', message: '', media: 'data:application/pdf;base64,AAA',
    ));

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->type === 'image' && $e->content === 'photo');
    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->type === 'video' && $e->content === 'clip');
    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->type === 'document');
});

it('does not dispatch MessageSent in debug-skip mode', function () {
    Event::fake();
    config()->set('wuz.debug.enabled', true);
    config()->set('wuz.debug.to', null);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $response = app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    expect($response)->toBeNull();
    Event::assertNotDispatched(MessageSent::class);
});

it('dispatches MessageSent with the redirected phone in debug-redirect mode', function () {
    Event::fake();
    fakeWuzPhoneAndSend();
    config()->set('wuz.debug.enabled', true);
    config()->set('wuz.debug.to', '552188888888');

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) =>
        $e->phone === '552188888888'
    );
});

it('does not dispatch MessageSent and propagates WuzApiException on API failure', function () {
    Event::fake();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null]], 200),
        '*/chat/send/text' => Http::response(['error' => 'rate limit'], 429),
    ]);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    expect(fn () => app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    ))->toThrow(WuzApiException::class);

    Event::assertNotDispatched(MessageSent::class);
});

it('persists the WuzPhoneJid cache row even when the send fails', function () {
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null]], 200),
        '*/chat/send/text' => Http::response(['error' => 'rate limit'], 429),
    ]);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    try {
        app(SendMessageAction::class)->handle(
            $device,
            new SendMessageData(phone: '551199999999', message: 'hi'),
        );
    } catch (WuzApiException) {
        // expected
    }

    expect(WuzPhoneJid::where('phone', '551199999999')->count())->toBe(1);
});

it('does not propagate MessageSent listener exceptions', function () {
    // Note: NO Event::fake() — the real dispatcher must run so the listener
    // fires and the safely() catch-and-report path is exercised.
    fakeWuzPhoneAndSend();
    \Illuminate\Support\Facades\Exceptions::fake();

    \Illuminate\Support\Facades\Event::listen(MessageSent::class, function () {
        throw new RuntimeException('listener boom');
    });

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $response = app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    expect($response)->toBeArray()
        ->and($response['data']['id'] ?? null)->toBe('wamid.123');

    \Illuminate\Support\Facades\Exceptions::assertReported(RuntimeException::class);
});
