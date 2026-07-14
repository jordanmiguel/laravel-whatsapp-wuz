<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Support\Str;
use JordanMiguel\Wuz\Data\GatewayUserData;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
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
     *
     * The token is a bearer credential, not an identifier: it authenticates every session call to
     * WuzAPI, and it is the only thing authenticating an inbound webhook, whose route ships with
     * no middleware by default. So it comes from a CSPRNG. A time-based token (uniqid) leaks the
     * moment of provisioning and leaves an attacker guessing within a microsecond window — for
     * the right to send WhatsApp messages as the owner, and to forge events into the consumer.
     *
     * @throws WuzApiException when WuzAPI accepts the user but does not say what it is called. The
     *                         admin endpoints address a user by that id, so a device without one
     *                         can never be deleted or repaired; failing here beats persisting it.
     */
    public function handle(string $name, ?string $proxyUrl = null): GatewayUserData
    {
        $token = 'device-' . Str::random(32);

        $result = $this->factory->admin()->addUser(
            name: $name,
            token: $token,
            webhookUrl: route('wuz.webhook', ['token' => $token]),
            proxyUrl: $proxyUrl,
        );

        $id = $result['data']['id'] ?? null;

        if ($id === null || $id === '') {
            throw new WuzApiException(
                'WuzAPI registered the device but returned no user id.',
                json_encode($result) ?: null,
            );
        }

        return new GatewayUserData(token: $token, deviceId: (string) $id);
    }
}
