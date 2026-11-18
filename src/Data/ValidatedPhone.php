<?php

namespace JordanMiguel\Wuz\Data;

class ValidatedPhone
{
    public function __construct(
        public readonly string $phone,
        public readonly ?string $jid,
        public readonly ?string $lid,
    ) {}

    /**
     * Where the message must go. A number WhatsApp confirmed only by its LID may not be the
     * canonical form the session maps that LID to, so sending to the phone fails; the LID
     * itself always resolves.
     */
    public function address(): string
    {
        return $this->jid !== null && str_ends_with($this->jid, '@lid') ? $this->jid : $this->phone;
    }
}
