<?php

namespace JordanMiguel\Wuz;

use Illuminate\Support\Facades\Notification;
use JordanMiguel\Wuz\Notifications\WuzChannel;
use JordanMiguel\Wuz\Services\WuzServiceFactory;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class WuzServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-whatsapp-wuz')
            ->hasConfigFile('wuz')
            ->hasMigrations([
                'create_wuz_devices_table',
                'create_wuz_device_messages_table',
                'create_wuz_callback_logs_table',
                'create_wuz_device_webhooks_table',
                'create_wuz_phone_jids_table',
            ])
            ->hasRoute('webhook');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(WuzServiceFactory::class);
    }

    public function packageBooted(): void
    {
        Notification::extend('wuz', fn ($app) => $app->make(WuzChannel::class));
    }
}
