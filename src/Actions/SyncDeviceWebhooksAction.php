<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Support\Facades\DB;
use JordanMiguel\Wuz\Models\WuzDevice;

class SyncDeviceWebhooksAction
{
    public function handle(WuzDevice $device, array $webhooks): void
    {
        DB::transaction(function () use ($device, $webhooks) {
            foreach ($webhooks as $webhookData) {
                $device->webhooks()->updateOrCreate(
                    ['event' => $webhookData['event']],
                    [
                        'url' => $webhookData['url'] ?? null,
                        'status' => $webhookData['status'] ?? false,
                    ],
                );
            }
        });
    }
}
