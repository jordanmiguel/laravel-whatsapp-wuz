<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use JordanMiguel\Wuz\Models\WuzDevice;

class ListCallbackLogsAction
{
    public function handle(WuzDevice $device, int $perPage = 15): LengthAwarePaginator
    {
        return $device->callbackLogs()
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
