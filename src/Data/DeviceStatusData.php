<?php

namespace JordanMiguel\Wuz\Data;

use Spatie\LaravelData\Data;

class DeviceStatusData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        /** WuzAPI's `loggedIn`: the session is authenticated and survives a dropped socket. */
        public readonly bool $connected,
        /** WuzAPI's `connected`: the websocket right now. Drops and re-establishes on its own. */
        public readonly bool $socket_connected,
        public readonly ?string $jid,
        public readonly ?string $qr_code,
        public readonly string $status,
        /** When the session went down, or null while it is up. Null also means "never paired". */
        public readonly ?string $disconnected_at = null,
    ) {}
}
