<?php

namespace JordanMiguel\Wuz\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Actions\ValidatePhoneAction;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class WuzChannel
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
        private readonly ValidatePhoneAction $validatePhone,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! config('wuz.enabled')) {
            return;
        }

        if (! method_exists($notification, 'toWuz') && ! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $phone = $notifiable->routeNotificationFor('wuz')
            ?? $notifiable->routeNotificationFor('whatsapp');

        if (! $phone) {
            return;
        }

        $device = $this->resolveDevice($notifiable);

        if (! $device?->connected) {
            Log::warning('No connected WuzDevice for notification', [
                'notifiable' => get_class($notifiable),
                'phone' => $phone,
            ]);

            return;
        }

        $message = method_exists($notification, 'toWuz')
            ? $notification->toWuz($notifiable)
            : $notification->toWhatsApp($notifiable);

        $wuz = $this->factory->make($device);

        try {
            $validated = $this->validatePhone->handle($wuz, $phone);
        } catch (WuzApiException $e) {
            Log::error('WuzChannel: phone validation failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $wuz->sendMessageText($validated->phone, $message->content);
    }

    private function resolveDevice(object $notifiable): ?WuzDevice
    {
        if (method_exists($notifiable, 'resolveWuzDevice')) {
            return $notifiable->resolveWuzDevice();
        }

        if (method_exists($notifiable, 'resolveWuzOwner')) {
            $owner = $notifiable->resolveWuzOwner();

            return $owner?->defaultWuzDevice();
        }

        return null;
    }
}
