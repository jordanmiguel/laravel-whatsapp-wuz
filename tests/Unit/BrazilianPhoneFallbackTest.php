<?php

use JordanMiguel\Wuz\Support\BrazilianPhoneFallback;

it('identifies a 13-digit Brazilian number as Brazilian', function () {
    expect(BrazilianPhoneFallback::isBrazilian('5583993383823'))->toBeTrue();
});

it('rejects a 12-digit Brazilian number', function () {
    expect(BrazilianPhoneFallback::isBrazilian('558393383823'))->toBeFalse();
});

it('rejects a non-Brazilian number', function () {
    expect(BrazilianPhoneFallback::isBrazilian('1234567890123'))->toBeFalse();
});

it('rejects a short number starting with 55', function () {
    expect(BrazilianPhoneFallback::isBrazilian('5511999'))->toBeFalse();
});

it('removes the ninth digit from a 13-digit Brazilian number', function () {
    expect(BrazilianPhoneFallback::removeNinthDigit('5583993383823'))
        ->toBe('558393383823');
});

it('removes the ninth digit from another Brazilian number', function () {
    expect(BrazilianPhoneFallback::removeNinthDigit('5511999999999'))
        ->toBe('551199999999');
});
