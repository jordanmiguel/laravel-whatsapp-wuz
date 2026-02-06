<?php

namespace JordanMiguel\Wuz\Data;

use Spatie\LaravelData\Data;

class UpdateDeviceData extends Data
{
    public function __construct(
        public readonly string $name,
    ) {}
}
