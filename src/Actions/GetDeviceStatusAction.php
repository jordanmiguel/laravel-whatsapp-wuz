<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Data\DeviceStatusData;
use JordanMiguel\Wuz\Events\DeviceConnected;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzService;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class GetDeviceStatusAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    /**
     * Asks WuzAPI what it knows about the session and persists the answer.
     *
     * WuzAPI reports two different things, and conflating them is what makes device state rot:
     *
     * - `connected` is the websocket. It drops and re-establishes on its own several times a day,
     *   and says nothing about whether the session survives.
     * - `loggedIn` is the authentication. It survives a dropped socket — whatsmeow holds the flag
     *   while it reconnects — and clears only when the phone is really gone.
     *
     * `loggedIn` is therefore what `WuzDevice::$connected` mirrors: "this session is still ours".
     *
     * @throws \JordanMiguel\Wuz\Exceptions\WuzApiException when WuzAPI cannot be reached. An API
     *                                                      that did not answer is not a device that logged out, and a caller repairing state
     *                                                      must be able to tell those apart before it acts on the difference.
     */
    public function handle(WuzDevice $device): DeviceStatusData
    {
        $wuz = $this->factory->make($device);

        $data = $wuz->sessionStatus();

        try {
            $wuz->setWebhookEvents(route('wuz.webhook', ['token' => $device->token]));
        } catch (\Exception) {
            // Re-arming the subscription is opportunistic; failing at it must not cost us the
            // status we just read successfully.
        }

        $wasConnected = $device->connected;
        $loggedIn = (bool) ($data['data']['loggedIn'] ?? false);
        $socketConnected = (bool) ($data['data']['connected'] ?? false);
        $jid = $data['data']['jid'] ?? null;

        // The jid is only trusted from a live session: WuzAPI serves the last known jid long
        // after a logout, so persisting it from a down session resurrects a cleared number.
        $loggedIn
            ? $device->markConnected(is_string($jid) && $jid !== '' ? $jid : null)
            : $device->markDisconnected();

        if ($loggedIn && ! $wasConnected) {
            DeviceConnected::dispatch($device);
        }

        $qrCode = $loggedIn ? null : $this->qrCode($wuz);

        return new DeviceStatusData(
            id: $device->id,
            name: $device->name,
            connected: $loggedIn,
            socket_connected: $socketConnected,
            jid: $device->jid,
            qr_code: $qrCode,
            status: $loggedIn ? 'connected' : ($qrCode ? 'qr' : 'disconnected'),
            disconnected_at: $device->disconnected_at?->toIso8601String(),
        );
    }

    private function qrCode(WuzService $wuz): ?string
    {
        try {
            return $wuz->sessionQr()['data']['QRCode'] ?? null;
        } catch (\Exception) {
            return null; // no QR on offer — the session may simply be mid-reconnect
        }
    }
}
