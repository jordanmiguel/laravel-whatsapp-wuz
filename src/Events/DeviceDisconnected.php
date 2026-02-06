<?php

namespace JordanMiguel\Wuz\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JordanMiguel\Wuz\Models\WuzDevice;

class DeviceDisconnected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WuzDevice $device,
    ) {}
}
