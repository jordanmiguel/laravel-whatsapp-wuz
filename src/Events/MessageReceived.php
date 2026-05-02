<?php

namespace JordanMiguel\Wuz\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JordanMiguel\Wuz\Models\WuzDevice;

class MessageReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $type  'text' | 'image' | 'video' | 'document'
     * @param  array<string, mixed>  $payload  raw WUZ webhook payload
     */
    public function __construct(
        public readonly WuzDevice $device,
        public readonly string $type,
        public readonly string $chatJid,
        public readonly ?string $senderJid,
        public readonly ?string $content,
        public readonly array $payload,
    ) {}
}
