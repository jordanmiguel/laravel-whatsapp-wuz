<?php

namespace JordanMiguel\Wuz\Exceptions;

use Exception;

class WuzApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $responseBody = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
