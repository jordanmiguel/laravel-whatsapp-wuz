<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Events\DeviceConnected;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;

class HandleWebhookCallbackAction
{
    public function handle(string $token, array $payload, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $device = WuzDevice::where('token', $token)->first();

        if (! $device) {
            return;
        }

        $payload = $this->unwrapEnvelope($payload);

        $rawType = is_string($payload['type'] ?? null) ? $payload['type'] : null;
        $eventType = WuzEventType::detect($payload);

        // 1. Run state-mutating side effects FIRST (the package's behavioural job).
        match ($eventType) {
            WuzEventType::MESSAGE => $this->handleMessage($device, $payload),
            WuzEventType::CONNECTED => $this->handleConnected($device),
            WuzEventType::DISCONNECTED => $this->handleDisconnected($device),
            WuzEventType::LOGGED_OUT => $this->handleLoggedOut($device),
            default => null,
        };

        // 2. Best-effort log insert.
        if ($eventType->shouldLog($rawType)) {
            $this->safely(fn () => WuzCallbackLog::create([
                'wuz_device_id' => $device->id,
                'event_type' => $rawType ?? $eventType->value,
                'payload' => $payload,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]));
        }

        // 3. Best-effort generic WebhookReceived dispatch. Event constructed first so
        //    package-side TypeErrors propagate; only listener execution is wrapped.
        if ($eventType->shouldDispatch($rawType)) {
            $event = new WebhookReceived($device, $eventType, $payload);
            $this->safely(fn () => event($event));
        }
    }

    private function handleMessage(WuzDevice $device, array $payload): void
    {
        $info = $payload['Info'] ?? [];
        $message = $payload['Message'] ?? [];

        $chatJid = $info['RemoteJid'] ?? null;

        if (! is_string($chatJid) || $chatJid === '') {
            return;
        }

        $senderJid = $info['Sender']['User'] ?? null;
        [$type, $content] = $this->parseMessage($message);

        $event = new MessageReceived($device, $type, $chatJid, $senderJid, $content, $payload);
        $this->safely(fn () => event($event));
    }

    private function handleConnected(WuzDevice $device): void
    {
        $device->markConnected();
        $event = new DeviceConnected($device);
        $this->safely(fn () => event($event));
    }

    /**
     * whatsmeow emits this on every dropped socket and reconnects on its own seconds later, all
     * while WhatsApp still considers the session logged in — so this is "the line went quiet",
     * not "the phone is gone". Consumers deciding whether to give up should read
     * `disconnected_at`, not this event.
     */
    private function handleDisconnected(WuzDevice $device): void
    {
        $device->markDisconnected();
        $event = new DeviceDisconnected($device);
        $this->safely(fn () => event($event));
    }

    private function handleLoggedOut(WuzDevice $device): void
    {
        $device->markDisconnected(unlinked: true);
        $event = new DeviceDisconnected($device);
        $this->safely(fn () => event($event));
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function parseMessage(array $message): array
    {
        if (isset($message['conversation'])) {
            return ['text', $message['conversation']];
        }

        if (isset($message['extendedTextMessage'])) {
            return ['text', $message['extendedTextMessage']['text'] ?? null];
        }

        if (isset($message['imageMessage'])) {
            return ['image', $message['imageMessage']['caption'] ?? null];
        }

        if (isset($message['videoMessage'])) {
            return ['video', $message['videoMessage']['caption'] ?? null];
        }

        if (isset($message['documentMessage'])) {
            return [
                'document',
                $message['documentMessage']['fileName'] ?? $message['documentMessage']['title'] ?? null,
            ];
        }

        return ['text', null];
    }

    private function safely(\Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * WUZ delivers callbacks as `{ instanceName, jsonData, userID }` where
     * `jsonData` is a JSON-encoded `{ type, event }` body. Promote the
     * inner event keys to the top level and surface `type` so the rest of
     * the pipeline reads a flat shape. Returns the payload unchanged when
     * no envelope is present (e.g. test fixtures).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapEnvelope(array $payload): array
    {
        if (! array_key_exists('jsonData', $payload)) {
            return $payload;
        }

        $jsonData = $payload['jsonData'];

        if (is_string($jsonData)) {
            $jsonData = json_decode($jsonData, true);
        }

        if (! is_array($jsonData)) {
            return $payload;
        }

        $event = is_array($jsonData['event'] ?? null) ? $jsonData['event'] : [];

        return [...$event, 'type' => $jsonData['type'] ?? null];
    }
}
