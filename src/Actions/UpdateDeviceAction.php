<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Data\UpdateDeviceData;
use JordanMiguel\Wuz\Models\WuzDevice;

class UpdateDeviceAction
{
    public function handle(WuzDevice $device, UpdateDeviceData $data): WuzDevice
    {
        $device->update(['name' => $data->name]);

        return $device->fresh();
    }
}
