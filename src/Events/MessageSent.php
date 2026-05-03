<?php

namespace JordanMiguel\Wuz\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JordanMiguel\Wuz\Models\WuzDevice;

/**
 * Fires when WUZ accepted an outbound send (HTTP 2xx).
 *
 * Does not assert delivery confirmation — WhatsApp delivery confirmation
 * arrives later as a separate ReadReceipt webhook. Failed sends throw
 * WuzApiException; this event does not fire in that path.
 */
class MessageSent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $apiResponse  raw WUZ API response body
     */
    public function __construct(
        public readonly WuzDevice $device,
        public readonly string $type,
        public readonly string $phone,
        public readonly ?string $content,
        public readonly array $apiResponse,
    ) {}
}
