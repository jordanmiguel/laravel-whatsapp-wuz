<?php

namespace JordanMiguel\Wuz\Traits;

use JordanMiguel\Wuz\Models\WuzDevice;

trait InteractsWithWuz
{
    public function routeNotificationForWuz(): ?string
    {
        return $this->routeNotificationFor('whatsapp');
    }

    public function resolveWuzDevice(): ?WuzDevice
    {
        $owner = $this->resolveWuzOwner();

        return $owner?->defaultWuzDevice();
    }

    abstract public function resolveWuzOwner(): mixed;
}
