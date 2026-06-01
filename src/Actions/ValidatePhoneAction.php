<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Data\ValidatedPhone;
use JordanMiguel\Wuz\Exceptions\PhoneNotRegisteredException;
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
            ?? $this->resolve($wuz, $phone);
    }

    private function resolve(WuzService $wuz, string $phone): ValidatedPhone
    {
        if ($this->isKnownUnregistered($phone)) {
            throw $this->notRegistered($phone);
        }

        return $this->resolveAndCache($wuz, $phone);
    }

    private function resolveBrazilian(WuzService $wuz, string $originalPhone, string $shortPhone): ValidatedPhone
    {
        // Try the 12-digit form first, then the 13-digit. A form already known to be
        // unregistered (within TTL) is skipped — that's what stops the repeated 404
        // storm for numbers that aren't on WhatsApp.
        foreach ([$shortPhone, $originalPhone] as $candidate) {
            if ($this->isKnownUnregistered($candidate)) {
                continue;
            }

            try {
                return $this->resolveAndCache($wuz, $candidate);
            } catch (PhoneNotRegisteredException) {
                // Negative-cached inside resolveAndCache; fall through to the next form.
            }
        }

        throw $this->notRegistered($shortPhone, $originalPhone);
    }

    private function resolveAndCache(WuzService $wuz, string $phone): ValidatedPhone
    {
        try {
            $response = $wuz->phoneToJid($phone);
        } catch (WuzApiException $e) {
            // 404 = number not on WhatsApp (permanent). Anything else is transient and
            // must NOT be negative-cached — let it bubble up so the caller can retry.
            if ($e->getCode() === 404) {
                $this->rememberUnregistered($phone);

                throw $this->notRegistered($phone);
            }

            throw $e;
        }

        $jid = $response['data']['jid'] ?? null;
        $lid = $response['data']['lid'] ?? null;

        // A 200 without a jid is a soft miss, not a definitive "not registered": return
        // it so the send still proceeds on the phone, but don't cache it as a negative —
        // re-check next time rather than storing a false negative.
        if (empty($jid)) {
            return new ValidatedPhone($phone, $jid, $lid);
        }

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

    private function isKnownUnregistered(string $phone): bool
    {
        $record = WuzPhoneJid::where('phone', $phone)->first();

        if ($record === null || ! empty($record->jid) || $record->updated_at === null) {
            return false;
        }

        $ttlDays = (int) config('wuz.phone.unregistered_ttl_days', 14);

        return $record->updated_at->greaterThan(now()->subDays($ttlDays));
    }

    private function rememberUnregistered(string $phone): void
    {
        WuzPhoneJid::updateOrCreate(['phone' => $phone], ['jid' => null, 'lid' => null]);

        // Refresh the TTL window even when the row already existed unchanged.
        WuzPhoneJid::where('phone', $phone)->touch();
    }

    private function notRegistered(string ...$phones): PhoneNotRegisteredException
    {
        return new PhoneNotRegisteredException(
            'Phone not registered on WhatsApp. Tried '.implode(' and ', $phones).'.',
            null,
            404,
        );
    }
}
