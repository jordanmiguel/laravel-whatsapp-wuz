<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Models\WuzDevice;

class ReregisterDeviceAction
{
    public function __construct(
        private readonly RegisterDeviceAtGatewayAction $register,
    ) {}

    /**
     * Gives an existing device a fresh WuzAPI user, for when the gateway no longer knows its token
     * — a WuzAPI whose store was rebuilt, or a user deleted out from under us. WuzAPI answers 401
     * to a token it does not know, and connect and status then 401 for good, so the device can
     * never pair again on its own.
     *
     * The row is re-registered rather than replaced. It is the parent of the device's message and
     * callback history, all of which cascades on delete, and that history must not be the price of
     * repairing a pairing. The old user is gone, so the session goes with it: the device comes
     * back unpaired, ready for a QR.
     */
    public function handle(WuzDevice $device): void
    {
        $credentials = $this->register->handle($device->name, $device->proxy_url);

        $device->update([
            'token' => $credentials->token,
            'device_id' => $credentials->deviceId,
            'connected' => false,
            'jid' => null,
        ]);
    }
}
