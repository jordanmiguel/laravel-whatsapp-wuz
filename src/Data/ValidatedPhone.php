<?php

namespace JordanMiguel\Wuz\Data;

class ValidatedPhone
{
    public function __construct(
        public readonly string $phone,
        public readonly ?string $jid,
        public readonly ?string $lid,
    ) {}
}
