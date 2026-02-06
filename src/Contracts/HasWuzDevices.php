<?php

namespace JordanMiguel\Wuz\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use JordanMiguel\Wuz\Models\WuzDevice;

interface HasWuzDevices
{
    public function wuzDevices(): MorphMany;

    public function defaultWuzDevice(): ?WuzDevice;

    public function setDefaultWuzDevice(WuzDevice $device): void;

    public function connectedWuzDevices(): MorphMany;
}
