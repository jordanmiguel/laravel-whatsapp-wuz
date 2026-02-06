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
        $tenant = $this->resolveWuzTenant();

        return $tenant?->defaultWuzDevice();
    }

    abstract public function resolveWuzTenant(): mixed;
}
