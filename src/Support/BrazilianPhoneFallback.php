<?php

namespace JordanMiguel\Wuz\Support;

class BrazilianPhoneFallback
{
    public static function isBrazilian(string $phone): bool
    {
        return str_starts_with($phone, '55') && strlen($phone) === 13;
    }

    /**
     * Remove the 9th digit (index 4) from a 13-digit Brazilian mobile number.
     * 55XX9XXXXXXXX -> 55XXXXXXXXXX
     */
    public static function removeNinthDigit(string $phone): string
    {
        return substr($phone, 0, 4) . substr($phone, 5);
    }
}
