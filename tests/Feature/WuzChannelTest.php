<?php

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
use JordanMiguel\Wuz\Notifications\WuzChannel;
use JordanMiguel\Wuz\Notifications\WuzMessage;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

class TestWuzNotification extends Notification
{
    public function toWuz($notifiable): WuzMessage
    {
        return WuzMessage::create('Hello from notification!');
    }
}

class TestNotifiable
{
    public WuzDevice $wuzDevice;

    public function routeNotificationFor(string $channel): ?string
    {
        if ($channel === 'wuz' || $channel === 'whatsapp') {
            return '5511999999999';
        }

        return null;
    }

    public function resolveWuzDevice(): ?WuzDevice
    {
        return $this->wuzDevice ?? null;
    }
}

it('sends a message via the WuzChannel', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => 'lid123']], 200),
        '*/chat/send/text' => Http::response(['data' => ['sent' => true]], 200),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => true,
    ]);

    $notifiable = new TestNotifiable;
    $notifiable->wuzDevice = $device;

    $channel = app(WuzChannel::class);
    $channel->send($notifiable, new TestWuzNotification);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/send/text')
        && $request['Body'] === 'Hello from notification!'
    );

    $message = WuzDeviceMessage::where('wuz_device_id', $device->id)->first();
    expect($message)->not->toBeNull()
        ->and($message->message)->toBe('Hello from notification!')
        ->and($message->type)->toBe('text');
});

it('silently skips when phone validation fails', function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/user/lid/*' => Http::response('Not found', 404),
    ]);

    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => true,
    ]);

    $notifiable = new TestNotifiable;
    $notifiable->wuzDevice = $device;

    $channel = app(WuzChannel::class);
    $channel->send($notifiable, new TestWuzNotification);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/chat/send/text'));
});

it('skips sending when no phone is available', function () {
    Http::preventStrayRequests();

    $notifiable = new class {
        public function routeNotificationFor(string $channel): ?string
        {
            return null;
        }
    };

    $channel = app(WuzChannel::class);
    $channel->send($notifiable, new TestWuzNotification);

    Http::assertNothingSent();
});

it('skips sending when device is not connected', function () {
    Http::preventStrayRequests();

    $owner = TestOwner::create(['name' => 'Test']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Device',
        'token' => 'tok',
        'device_id' => 'wuz-1',
        'connected' => false,
    ]);

    $notifiable = new TestNotifiable;
    $notifiable->wuzDevice = $device;

    $channel = app(WuzChannel::class);
    $channel->send($notifiable, new TestWuzNotification);

    Http::assertNothingSent();
});
