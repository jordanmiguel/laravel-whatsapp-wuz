<?php

namespace JordanMiguel\Wuz\Notifications;

class WuzMessage
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $mediaUrl = null,
    ) {}

    public static function create(string $content): self
    {
        return new self($content);
    }

    public function media(string $url): self
    {
        return new self($this->content, $url);
    }
}
