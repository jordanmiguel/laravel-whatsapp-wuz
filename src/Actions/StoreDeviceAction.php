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
        // The gateway calls stay outside the transaction. Neither is something a rollback could
        // undo — the WuzAPI user outlives it — so all the transaction bought by wrapping them was
        // a database connection, and whatever rows it locked, held open across the network for as
        // long as WuzAPI felt like taking. It guards the read-then-write it is actually there for:
        // deciding whether this device is the owner's first, and creating it.
        $credentials = $this->register->handle($data->name, $data->proxyUrl);

        $device = DB::transaction(function () use ($owner, $data, $createdBy, $credentials) {
            $isFirst = $owner->wuzDevices()->count() === 0;

            return $owner->wuzDevices()->create([
                'device_id' => $credentials->deviceId,
                'name' => $data->name,
                'token' => $credentials->token,
                'is_default' => $isFirst,
                'created_by' => $createdBy,
                'proxy_url' => $data->proxyUrl,
                'proxy_session' => $data->proxySession,
            ]);
        });

        $this->connectAction->handle($device);

        return $device;
    }
}
