<?php

namespace JordanMiguel\Wuz\Support;

class PhoneNormalizer
{
    public static function normalize(string $phoneNumber, ?string $prefix = null): string
    {
        $prefix = $prefix ?? config('wuz.phone.default_country_code', '55');

        $phoneNumber = preg_replace('/[^\d+]/', '', $phoneNumber);

        if (str_starts_with($phoneNumber, '0')) {
            return $prefix . substr($phoneNumber, 1);
        }

        if (str_starts_with($phoneNumber, '+')) {
            return substr($phoneNumber, 1);
        }

        if (str_starts_with($phoneNumber, $prefix)) {
            return $phoneNumber;
        }

        return $prefix . $phoneNumber;
    }
}
