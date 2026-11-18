<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Data\ValidatedPhone;
use JordanMiguel\Wuz\Exceptions\PhoneNotRegisteredException;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Services\WuzService;
use JordanMiguel\Wuz\Support\BrazilianPhoneFallback;
use JordanMiguel\Wuz\Support\PhoneNormalizer;

/**
 * Resolves a phone to a WhatsApp identity in three steps, cheapest first:
 *
 *  1. our own cache (wuz_phone_jids);
 *  2. GET /user/lid — reads the session's local PN→LID store, so a 404 only means
 *     "this session has not learned the mapping yet" and is never a verdict;
 *  3. POST /user/check — asks WhatsApp. Only its explicit IsInWhatsapp=false marks a
 *     number unregistered (negative-cached for wuz.phone.unregistered_ttl_days).
 *
 * Brazilian mobiles are tried without the ninth digit first, then as given.
 */
class ValidatePhoneAction
{
    public function handle(WuzService $wuz, string $phone): ValidatedPhone
    {
        $candidates = $this->candidates(PhoneNormalizer::normalize($phone));

        foreach ($candidates as $candidate) {
            if (($cached = $this->findCached($candidate)) !== null) {
                return $cached;
            }
        }

        $pending = array_values(array_filter($candidates, fn (string $candidate) => ! $this->isKnownUnregistered($candidate)));

        if ($pending === []) {
            throw $this->notRegistered(...$candidates);
        }

        foreach ($pending as $candidate) {
            if (($resolved = $this->fromLocalMapping($wuz, $candidate)) !== null) {
                return $resolved;
            }
        }

        return $this->askWhatsApp($wuz, $pending, $candidates);
    }

    /**
     * @return list<string>
     */
    private function candidates(string $phone): array
    {
        return BrazilianPhoneFallback::isBrazilian($phone)
            ? [BrazilianPhoneFallback::removeNinthDigit($phone), $phone]
            : [$phone];
    }

    private function fromLocalMapping(WuzService $wuz, string $phone): ?ValidatedPhone
    {
        try {
            $response = $wuz->phoneToJid($phone);
        } catch (WuzApiException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        return $this->remember($phone, $response['data']['jid'] ?? null, $response['data']['lid'] ?? null);
    }

    /**
     * @param  list<string>  $pending  forms not already known to be unregistered
     * @param  list<string>  $candidates  every form tried, for the error message
     */
    private function askWhatsApp(WuzService $wuz, array $pending, array $candidates): ValidatedPhone
    {
        $response = $wuz->isPhoneRegistered($pending);

        $answers = [];
        foreach ($response['data']['Users'] ?? [] as $user) {
            $answers[preg_replace('/\D/', '', (string) ($user['Query'] ?? ''))] = $user;
        }

        $refused = array_values(array_filter(
            $pending,
            fn (string $candidate) => ($answers[$candidate]['IsInWhatsapp'] ?? null) === false,
        ));

        foreach ($refused as $candidate) {
            $this->rememberUnregistered($candidate);
        }

        foreach ($pending as $candidate) {
            if (($answers[$candidate]['IsInWhatsapp'] ?? null) === true) {
                $jid = (string) ($answers[$candidate]['JID'] ?? '');

                return $this->remember($candidate, $jid, str_ends_with($jid, '@lid') ? $jid : null);
            }
        }

        if (count($refused) === count($pending)) {
            throw $this->notRegistered(...$candidates);
        }

        // WhatsApp answered, but not about these numbers: inconclusive, so retryable and uncached.
        throw new WuzApiException(
            'WhatsApp registration check was inconclusive for '.implode(' and ', $pending).'.',
            json_encode($response) ?: null,
        );
    }

    private function remember(string $phone, ?string $jid, ?string $lid): ValidatedPhone
    {
        // A positive answer without a jid still lets the send proceed on the phone, but is
        // not cached — re-check next time rather than store something we cannot reuse.
        if (! empty($jid)) {
            WuzPhoneJid::updateOrCreate(['phone' => $phone], ['jid' => $jid, 'lid' => $lid]);
        }

        return new ValidatedPhone($phone, $jid ?: null, $lid);
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
