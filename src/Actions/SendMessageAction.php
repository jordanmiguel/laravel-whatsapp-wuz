<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Events\MessageSent;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzService;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class SendMessageAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
        private readonly ValidatePhoneAction $validatePhone,
    ) {}

    /**
     * @return array<string, mixed>|null  WUZ API response body, or null when debug-skipped.
     */
    public function handle(WuzDevice $device, SendMessageData $data): ?array
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

        $wuz = $this->factory->make($device);
        $validated = $this->validatePhone->handle($wuz, $data->phone);
        $phone = $validated->phone;

        [$response, $messageContent] = $this->dispatchToWuz($wuz, $phone, $data);

        $event = new MessageSent($device, $data->type, $phone, $messageContent, $response);
        $this->safely(fn () => event($event));

        return $response;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function dispatchToWuz(WuzService $wuz, string $phone, SendMessageData $data): array
    {
        return match ($data->type) {
            'text' => [
                $wuz->sendMessageText($phone, $data->message, $data->link_preview),
                $data->message,
            ],
            'image' => [
                $wuz->sendMessageImage($phone, $this->encodeMedia($data->media), $data->caption ?? ''),
                $data->caption ?? 'Image',
            ],
            'video' => [
                $wuz->sendMessageVideo($phone, $this->encodeMedia($data->media), $data->caption ?? ''),
                $data->caption ?? 'Video',
            ],
            'document' => [
                $wuz->sendMessageDocument(
                    $phone,
                    $this->encodeMedia($data->media),
                    is_object($data->media) && method_exists($data->media, 'getClientOriginalName')
                        ? $data->media->getClientOriginalName()
                        : 'document',
                ),
                is_object($data->media) && method_exists($data->media, 'getClientOriginalName')
                    ? $data->media->getClientOriginalName()
                    : 'document',
            ],
            'button' => [
                $wuz->sendMessageButton($phone, $data->message, $data->buttons ?? []),
                $data->message,
            ],
        };
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

    private function safely(\Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
