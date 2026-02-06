<?php

namespace JordanMiguel\Wuz\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class WuzChannel
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
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
        $wuz->sendMessageText($phone, $message->content);
    }

    private function resolveDevice(object $notifiable): ?WuzDevice
    {
        if (method_exists($notifiable, 'resolveWuzDevice')) {
            return $notifiable->resolveWuzDevice();
        }

        if (method_exists($notifiable, 'resolveWuzTenant')) {
            $tenant = $notifiable->resolveWuzTenant();

            return $tenant?->defaultWuzDevice();
        }

        return null;
    }
}
