<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class SendMessageAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
        private readonly ValidatePhoneAction $validatePhone,
    ) {}

    public function handle(WuzDevice $device, SendMessageData $data): ?WuzDeviceMessage
    {
        if (config('wuz.debug.enabled')) {
            $debugTo = config('wuz.debug.to');

            if (empty($debugTo)) {
                Log::info('Wuz debug: message skipped', [
                    'phone' => $data->phone,
                    'type' => $data->type,
                    'message' => $data->message,
                ]);

                return null;
            }

            $data = new SendMessageData(
                phone: $debugTo,
                type: $data->type,
                message: $data->message,
                caption: $data->caption,
                media: $data->media,
                buttons: $data->buttons,
                link_preview: $data->link_preview,
            );
        }

        return DB::transaction(function () use ($device, $data) {
            $wuz = $this->factory->make($device);
            $validated = $this->validatePhone->handle($wuz, $data->phone);
            $phone = $validated->phone;

            $response = null;
            $messageContent = '';

            switch ($data->type) {
                case 'text':
                    $response = $wuz->sendMessageText($phone, $data->message, $data->link_preview);
                    $messageContent = $data->message;
                    break;

                case 'image':
                    $base64Image = $this->encodeMedia($data->media);
                    $response = $wuz->sendMessageImage($phone, $base64Image, $data->caption ?? '');
                    $messageContent = $data->caption ?? 'Image';
                    break;

                case 'video':
                    $base64Video = $this->encodeMedia($data->media);
                    $response = $wuz->sendMessageVideo($phone, $base64Video, $data->caption ?? '');
                    $messageContent = $data->caption ?? 'Video';
                    break;

                case 'document':
                    $base64Doc = $this->encodeMedia($data->media);
                    $filename = is_object($data->media) && method_exists($data->media, 'getClientOriginalName')
                        ? $data->media->getClientOriginalName()
                        : 'document';
                    $response = $wuz->sendMessageDocument($phone, $base64Doc, $filename);
                    $messageContent = $filename;
                    break;

                case 'button':
                    $response = $wuz->sendMessageButton($phone, $data->message, $data->buttons ?? []);
                    $messageContent = $data->message;
                    break;
            }

            return WuzDeviceMessage::create([
                'wuz_device_id' => $device->id,
                'chat_jid' => $validated->jid,
                'sender_jid' => $device->jid,
                'message' => $messageContent,
                'metadata' => $response,
                'type' => $data->type,
            ]);
        });
    }

    private function encodeMedia(mixed $media): string
    {
        if (is_string($media)) {
            return $media;
        }

        if (is_object($media) && method_exists($media, 'getRealPath')) {
            $content = base64_encode(file_get_contents($media->getRealPath()));
            $mimeType = $media->getMimeType();

            return "data:{$mimeType};base64,{$content}";
        }

        return '';
    }
}
