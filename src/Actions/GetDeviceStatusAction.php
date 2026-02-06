<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Data\DeviceStatusData;
use JordanMiguel\Wuz\Events\DeviceConnected;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class GetDeviceStatusAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    public function handle(WuzDevice $device): DeviceStatusData
    {
        $wuz = $this->factory->make($device);

        $data = [];
        $qrCode = null;

        try {
            $data = $wuz->sessionStatus();

            $webhookUrl = route('wuz.webhook', ['token' => $device->token]);
            $wuz->setWebhookEvents($webhookUrl);
        } catch (\Exception) {
            // Swallow — device may not be reachable
        }

        $wasConnected = $device->connected;
        $isConnected = $data['data']['loggedIn'] ?? false;
        $jid = $data['data']['jid'] ?? null;

        $device->update([
            'connected' => $isConnected,
            'jid' => $jid,
        ]);

        if ($isConnected && ! $wasConnected) {
            DeviceConnected::dispatch($device);
        }

        if (! $isConnected) {
            try {
                $qrData = $wuz->sessionQr();
                $qrCode = $qrData['data']['QRCode'] ?? null;
            } catch (\Exception) {
                // QR not available yet
            }
        }

        $status = $isConnected ? 'connected' : ($qrCode ? 'qr' : 'disconnected');

        return new DeviceStatusData(
            id: $device->id,
            name: $device->name,
            connected: $isConnected,
            jid: $jid,
            qr_code: $qrCode,
            status: $status,
        );
    }
}
