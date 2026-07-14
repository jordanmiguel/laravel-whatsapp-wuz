<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Data\GatewayUserData;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class RegisterDeviceAtGatewayAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    /**
     * Mints a WuzAPI user and hands back its credentials.
     *
     * The token is both the device's identity at the gateway and the path segment of its webhook
     * URL, so the two are issued together, here, once. A caller that minted its own would have to
     * restate the token scheme and the webhook route, and would drift the moment either changed.
     */
    public function handle(string $name, ?string $proxyUrl = null): GatewayUserData
    {
        $token = 'device-' . uniqid() . time();

        $result = $this->factory->admin()->addUser(
            name: $name,
            token: $token,
            webhookUrl: route('wuz.webhook', ['token' => $token]),
            proxyUrl: $proxyUrl,
        );

        $id = $result['data']['id'] ?? null;

        return new GatewayUserData(
            token: $token,
            deviceId: $id === null ? null : (string) $id,
        );
    }
}
