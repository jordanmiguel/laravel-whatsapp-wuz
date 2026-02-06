<?php

namespace JordanMiguel\Wuz\Data;

use Spatie\LaravelData\Data;

class SendMessageData extends Data
{
    public function __construct(
        public readonly string $phone,
        public readonly string $type = 'text',
        public readonly ?string $message = null,
        public readonly ?string $caption = null,
        public readonly mixed $media = null,
        public readonly ?array $buttons = null,
        public readonly bool $link_preview = false,
    ) {}
}
