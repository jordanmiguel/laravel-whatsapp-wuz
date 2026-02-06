<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Support\Facades\DB;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class DeleteDeviceAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    public function handle(WuzDevice $device): void
    {
        try {
            $this->factory->admin()->deleteUser($device->device_id);
        } catch (\Exception) {
            // Swallow — device may already be removed from WuzAPI
        }

        DB::transaction(function () use ($device) {
            $wasDefault = $device->is_default;
            $tenant = $device->tenant;

            $device->delete();

            if ($wasDefault && $tenant) {
                $nextDevice = $tenant->wuzDevices()->oldest()->first();
                $nextDevice?->update(['is_default' => true]);
            }
        });
    }
}
