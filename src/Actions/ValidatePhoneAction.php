<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Data\ValidatedPhone;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Services\WuzService;
use JordanMiguel\Wuz\Support\BrazilianPhoneFallback;
use JordanMiguel\Wuz\Support\PhoneNormalizer;

class ValidatePhoneAction
{
    public function handle(WuzService $wuz, string $phone): ValidatedPhone
    {
        $phone = PhoneNormalizer::normalize($phone);

        if (BrazilianPhoneFallback::isBrazilian($phone)) {
            $shortPhone = BrazilianPhoneFallback::removeNinthDigit($phone);

            return $this->findCached($phone)
                ?? $this->findCached($shortPhone)
                ?? $this->resolveBrazilian($wuz, $phone, $shortPhone);
        }

        return $this->findCached($phone)
            ?? $this->resolveAndCache($wuz, $phone);
    }

    private function resolveBrazilian(WuzService $wuz, string $originalPhone, string $shortPhone): ValidatedPhone
    {
        try {
            return $this->resolveAndCache($wuz, $shortPhone);
        } catch (WuzApiException) {
            // 12-digit failed, try original 13-digit
        }

        try {
            return $this->resolveAndCache($wuz, $originalPhone);
        } catch (WuzApiException $e) {
            throw new WuzApiException(
                "Phone not registered on WhatsApp. Tried {$shortPhone} and {$originalPhone}.",
                $e->responseBody,
                $e->getCode(),
            );
        }
    }

    private function resolveAndCache(WuzService $wuz, string $phone): ValidatedPhone
    {
        $response = $wuz->phoneToJid($phone);

        $jid = $response['data']['jid'] ?? null;
        $lid = $response['data']['lid'] ?? null;

        WuzPhoneJid::updateOrCreate(
            ['phone' => $phone],
            ['jid' => $jid, 'lid' => $lid],
        );

        return new ValidatedPhone($phone, $jid, $lid);
    }

    private function findCached(string $phone): ?ValidatedPhone
    {
        $record = WuzPhoneJid::where('phone', $phone)->first();

        if ($record && ! empty($record->jid)) {
            return new ValidatedPhone($record->phone, $record->jid, $record->lid);
        }

        return null;
    }
}
