<?php

namespace JordanMiguel\Wuz\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Models\WuzDevice;

class WebhookReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WuzDevice $device,
        public readonly WuzEventType $eventType,
        public readonly array $payload,
    ) {}
}
