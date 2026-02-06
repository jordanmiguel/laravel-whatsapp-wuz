<?php

namespace JordanMiguel\Wuz\Exceptions;

use Exception;

class NoDefaultDeviceException extends Exception
{
    public function __construct(string $message = 'No default WuzDevice found for this tenant.')
    {
        parent::__construct($message);
    }
}
