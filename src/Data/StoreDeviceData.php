<?php

namespace JordanMiguel\Wuz\Data;

use Spatie\LaravelData\Data;

class StoreDeviceData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $proxyUrl = null,
        public readonly ?string $proxySession = null,
    ) {}
}
