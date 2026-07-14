<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JordanMiguel\Wuz\Data\StoreDeviceData;
use JordanMiguel\Wuz\Models\WuzDevice;

class StoreDeviceAction
{
    public function __construct(
        private readonly RegisterDeviceAtGatewayAction $register,
        private readonly ConnectDeviceAction $connectAction,
    ) {}

    public function handle(Model $owner, StoreDeviceData $data, ?int $createdBy = null): WuzDevice
    {
        return DB::transaction(function () use ($owner, $data, $createdBy) {
            $credentials = $this->register->handle($data->name, $data->proxyUrl);

            $isFirst = $owner->wuzDevices()->count() === 0;

            $device = $owner->wuzDevices()->create([
                'device_id' => $credentials->deviceId,
                'name' => $data->name,
                'token' => $credentials->token,
                'is_default' => $isFirst,
                'created_by' => $createdBy,
                'proxy_url' => $data->proxyUrl,
                'proxy_session' => $data->proxySession,
            ]);

            $this->connectAction->handle($device);

            return $device;
        });
    }
}
