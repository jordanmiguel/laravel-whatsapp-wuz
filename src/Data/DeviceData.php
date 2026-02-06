<?php

namespace JordanMiguel\Wuz\Data;

use Carbon\CarbonImmutable;
use JordanMiguel\Wuz\Models\WuzDevice;
use Spatie\LaravelData\Data;

class DeviceData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $device_id,
        public readonly bool $connected,
        public readonly ?string $jid,
        public readonly bool $is_default,
        public readonly ?CarbonImmutable $created_at,
    ) {}

    public static function fromModel(WuzDevice $device): self
    {
        return new self(
            id: $device->id,
            name: $device->name,
            device_id: $device->device_id,
            connected: $device->connected,
            jid: $device->jid,
            is_default: $device->is_default,
            created_at: $device->created_at ? CarbonImmutable::parse($device->created_at) : null,
        );
    }
}
