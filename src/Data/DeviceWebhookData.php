<?php

namespace JordanMiguel\Wuz\Data;

use JordanMiguel\Wuz\Models\WuzDeviceWebhook;
use Spatie\LaravelData\Data;

class DeviceWebhookData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $event,
        public readonly ?string $url,
        public readonly bool $status,
    ) {}

    public static function fromModel(WuzDeviceWebhook $webhook): self
    {
        return new self(
            id: $webhook->id,
            event: $webhook->event,
            url: $webhook->url,
            status: $webhook->status,
        );
    }
}
