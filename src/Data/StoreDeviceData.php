<?php

namespace JordanMiguel\Wuz\Data;

use Spatie\LaravelData\Data;

class StoreDeviceData extends Data
{
    public function __construct(
        public readonly string $name,
    ) {}
}
