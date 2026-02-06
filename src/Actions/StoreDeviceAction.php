<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JordanMiguel\Wuz\Data\StoreDeviceData;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class StoreDeviceAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
        private readonly ConnectDeviceAction $connectAction,
    ) {}

    public function handle(Model $tenant, StoreDeviceData $data, ?int $createdBy = null): WuzDevice
    {
        return DB::transaction(function () use ($tenant, $data, $createdBy) {
            $token = 'device-' . uniqid() . time();

            $webhookUrl = route('wuz.webhook', ['token' => $token]);

            $result = $this->factory->admin()->addUser(
                name: $data->name,
                token: $token,
                webhookUrl: $webhookUrl,
            );

            $isFirst = $tenant->wuzDevices()->count() === 0;

            $device = $tenant->wuzDevices()->create([
                'device_id' => $result['data']['id'] ?? null,
                'name' => $data->name,
                'token' => $token,
                'is_default' => $isFirst,
                'created_by' => $createdBy,
            ]);

            $this->connectAction->handle($device);

            return $device;
        });
    }
}
