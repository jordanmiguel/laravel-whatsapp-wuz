<?php

use JordanMiguel\Wuz\Support\PhoneNormalizer;

it('replaces leading 0 with country code', function () {
    expect(PhoneNormalizer::normalize('08123456789', '55'))->toBe('558123456789');
});

it('strips leading + sign', function () {
    expect(PhoneNormalizer::normalize('+5512345678', '55'))->toBe('5512345678');
});

it('returns as-is when already starts with prefix', function () {
    expect(PhoneNormalizer::normalize('5512345678', '55'))->toBe('5512345678');
});

it('prepends prefix for other formats', function () {
    expect(PhoneNormalizer::normalize('12345678', '55'))->toBe('5512345678');
});

it('strips non-numeric characters except +', function () {
    expect(PhoneNormalizer::normalize('+55 (12) 3456-7890', '55'))->toBe('551234567890');
});

it('uses default country code from config', function () {
    config(['wuz.phone.default_country_code' => '62']);
    expect(PhoneNormalizer::normalize('08123456789'))->toBe('628123456789');
});
