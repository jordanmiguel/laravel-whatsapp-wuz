<?php

namespace JordanMiguel\Wuz\Data;

use Spatie\LaravelData\Data;

class GatewayUserData extends Data
{
    public function __construct(
        /** The device's identity at WuzAPI, and the path segment of its webhook URL. */
        public readonly string $token,
        /** WuzAPI's own id for the user, which the admin endpoints address it by. */
        public readonly string $deviceId,
    ) {}
}
