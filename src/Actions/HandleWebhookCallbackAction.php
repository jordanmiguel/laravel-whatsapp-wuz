<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class HandleWebhookCallbackAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
    ) {}

    public function handle(string $token, array $payload, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $device = WuzDevice::where('token', $token)->first();

        if (! $device) {
            return;
        }

        $eventType = WuzEventType::detect($payload);

        WuzCallbackLog::create([
            'wuz_device_id' => $device->id,
            'event_type' => $eventType->value,
            'payload' => $payload,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        WebhookReceived::dispatch($device, $eventType, $payload);

        match ($eventType) {
            WuzEventType::MESSAGE => $this->handleMessage($device, $payload),
            WuzEventType::DISCONNECTED => $this->handleDisconnected($device),
            WuzEventType::LOGGED_OUT => $this->handleLoggedOut($device),
            default => null,
        };
    }

    private function handleMessage(WuzDevice $device, array $data): void
    {
        $info = $data['Info'] ?? [];
        $message = $data['Message'] ?? [];

        $chatJid = $info['RemoteJid'] ?? null;
        $senderJid = $info['Sender']['User'] ?? null;
        $messageType = 'text';
        $messageContent = null;
        $metadata = [];

        if (isset($message['conversation'])) {
            $messageType = 'text';
            $messageContent = $message['conversation'];
        } elseif (isset($message['extendedTextMessage'])) {
            $messageType = 'text';
            $messageContent = $message['extendedTextMessage']['text'] ?? null;
        } elseif (isset($message['imageMessage'])) {
            $messageType = 'image';
            $messageContent = $message['imageMessage']['caption'] ?? null;
            $metadata = $this->downloadMedia($device, $message['imageMessage'], 'image');
        } elseif (isset($message['videoMessage'])) {
            $messageType = 'video';
            $messageContent = $message['videoMessage']['caption'] ?? null;
            $metadata = $this->downloadMedia($device, $message['videoMessage'], 'video');
        } elseif (isset($message['documentMessage'])) {
            $messageType = 'document';
            $messageContent = $message['documentMessage']['fileName'] ?? $message['documentMessage']['title'] ?? null;
            $metadata = $this->downloadMedia($device, $message['documentMessage'], 'document');
        }

        $deviceMessage = WuzDeviceMessage::create([
            'wuz_device_id' => $device->id,
            'chat_jid' => $chatJid,
            'sender_jid' => $senderJid,
            'message' => $messageContent,
            'metadata' => $metadata,
            'type' => $messageType,
        ]);

        MessageReceived::dispatch($device, $deviceMessage);
    }

    private function downloadMedia(WuzDevice $device, array $mediaMessage, string $type): array
    {
        if (! config('wuz.download_media')) {
            return ['downloaded' => false, 'reason' => 'Media download disabled'];
        }

        try {
            $url = $mediaMessage['url'] ?? null;
            $directPath = $mediaMessage['directPath'] ?? null;
            $mediaKey = $mediaMessage['mediaKey'] ?? null;
            $mimetype = $mediaMessage['mimetype'] ?? null;
            $fileEncSHA256 = $mediaMessage['fileEncSha256'] ?? null;
            $fileSHA256 = $mediaMessage['fileSha256'] ?? null;
            $fileLength = $mediaMessage['fileLength'] ?? 0;

            if (! $url || ! $mediaKey) {
                return ['downloaded' => false, 'reason' => 'Missing media URL or key'];
            }

            $mediaKeyBase64 = is_array($mediaKey)
                ? base64_encode(implode('', array_map('chr', $mediaKey)))
                : $mediaKey;
            $fileEncSHA256Base64 = is_array($fileEncSHA256)
                ? base64_encode(implode('', array_map('chr', $fileEncSHA256)))
                : $fileEncSHA256;
            $fileSHA256Base64 = is_array($fileSHA256)
                ? base64_encode(implode('', array_map('chr', $fileSHA256)))
                : $fileSHA256;

            $wuz = $this->factory->make($device);

            $result = match ($type) {
                'image' => $wuz->downloadImage($url, $directPath, $mediaKeyBase64, $mimetype, $fileEncSHA256Base64, $fileSHA256Base64, $fileLength),
                'video' => $wuz->downloadVideo($url, $directPath, $mediaKeyBase64, $mimetype, $fileEncSHA256Base64, $fileSHA256Base64, $fileLength),
                'document' => $wuz->downloadDocument($url, $directPath, $mediaKeyBase64, $mimetype, $fileEncSHA256Base64, $fileSHA256Base64, $fileLength),
                default => null,
            };

            if (isset($result['code']) && $result['code'] === 200 && isset($result['data']['Data'])) {
                $base64Data = $result['data']['Data'];

                if (str_contains($base64Data, 'base64,')) {
                    $base64Data = explode('base64,', $base64Data)[1];
                }

                $fileData = base64_decode($base64Data);
                $extension = explode('/', $mimetype)[1] ?? 'bin';
                $extension = explode(';', $extension)[0];
                $fileName = 'wuz-media/' . $device->id . '/' . uniqid() . '.' . $extension;

                Storage::disk('public')->put($fileName, $fileData);

                return [
                    'downloaded' => true,
                    'type' => $type,
                    'mimetype' => $mimetype,
                    'file_length' => $fileLength,
                    'path' => $fileName,
                    'url' => Storage::url($fileName),
                ];
            }

            return ['downloaded' => false, 'reason' => 'Invalid response format'];
        } catch (\Exception $e) {
            Log::error('Wuz media download failed: ' . $e->getMessage());

            return ['downloaded' => false, 'reason' => $e->getMessage()];
        }
    }

    private function handleDisconnected(WuzDevice $device): void
    {
        $device->update(['connected' => false]);
        DeviceDisconnected::dispatch($device);
    }

    private function handleLoggedOut(WuzDevice $device): void
    {
        $device->update(['connected' => false, 'jid' => null]);
        DeviceDisconnected::dispatch($device);
    }
}
