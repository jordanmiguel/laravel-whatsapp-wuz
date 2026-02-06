<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class DisconnectDeviceAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    public function handle(WuzDevice $device): void
    {
        try {
            $this->factory->make($device)->sessionLogout();
        } catch (\Exception) {
            // Swallow API exceptions
        }

        $device->update([
            'connected' => false,
            'jid' => null,
        ]);

        DeviceDisconnected::dispatch($device);
    }
}
