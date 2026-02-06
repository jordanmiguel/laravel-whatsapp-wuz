<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class ConnectDeviceAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    public function handle(WuzDevice $device): void
    {
        try {
            $this->factory->make($device)->sessionConnect();
        } catch (\Exception) {
            // Connection is async — QR will be polled via GetDeviceStatusAction
        }
    }
}
