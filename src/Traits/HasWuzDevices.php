<?php

namespace JordanMiguel\Wuz\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use JordanMiguel\Wuz\Models\WuzDevice;

trait HasWuzDevices
{
    public function wuzDevices(): MorphMany
    {
        return $this->morphMany(WuzDevice::class, 'tenant');
    }

    public function defaultWuzDevice(): ?WuzDevice
    {
        return $this->wuzDevices()->where('is_default', true)->first();
    }

    public function setDefaultWuzDevice(WuzDevice $device): void
    {
        DB::transaction(function () use ($device) {
            $this->wuzDevices()
                ->where('is_default', true)
                ->where('id', '!=', $device->id)
                ->update(['is_default' => false]);

            $device->update(['is_default' => true]);
        });
    }

    public function connectedWuzDevices(): MorphMany
    {
        return $this->wuzDevices()->where('connected', true);
    }
}
