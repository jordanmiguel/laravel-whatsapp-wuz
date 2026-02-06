<?php

namespace JordanMiguel\Wuz\Data;

use Spatie\LaravelData\Data;

class DeviceStatusData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $connected,
        public readonly ?string $jid,
        public readonly ?string $qr_code,
        public readonly string $status,
    ) {}
}
