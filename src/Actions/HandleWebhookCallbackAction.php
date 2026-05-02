<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Enums\WuzEventType;
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

        $rawType = is_string($payload['type'] ?? null) ? $payload['type'] : null;
        $eventType = WuzEventType::detect($payload);

        // 1. Run state-mutating side effects FIRST (the package's behavioural job).
        match ($eventType) {
            WuzEventType::MESSAGE => $this->handleMessage($device, $payload),
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

    private function handleDisconnected(WuzDevice $device): void
    {
        $device->update(['connected' => false]);
        $event = new DeviceDisconnected($device);
        $this->safely(fn () => event($event));
    }

    private function handleLoggedOut(WuzDevice $device): void
    {
        $device->update(['connected' => false, 'jid' => null]);
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
}
