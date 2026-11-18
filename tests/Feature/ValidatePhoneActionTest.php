<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\ValidatePhoneAction;
use JordanMiguel\Wuz\Data\ValidatedPhone;
use JordanMiguel\Wuz\Exceptions\PhoneNotRegisteredException;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Services\WuzService;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
});

function makeWuzService(): WuzService
{
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    return new WuzService(
        apiUrl: config('wuz.api_url'),
        userToken: $device->token,
    );
}

/**
 * WuzAPI's /user/check envelope: one entry per queried phone, echoed back in Query.
 *
 * @param  array<string, array{0: bool, 1?: string}>  $users  query => [isInWhatsapp, jid]
 */
function checkResponse(array $users): \GuzzleHttp\Promise\PromiseInterface
{
    $entries = [];
    foreach ($users as $query => $user) {
        $entries[] = ['Query' => (string) $query, 'IsInWhatsapp' => $user[0], 'JID' => $user[1] ?? '', 'VerifiedName' => ''];
    }

    return Http::response(['code' => 200, 'data' => ['Users' => $entries], 'success' => true], 200);
}

function lidMiss(): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['code' => 404, 'error' => 'LID not found for this number', 'success' => false], 404);
}

it('validates a non-Brazilian phone on first attempt', function () {
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '4412@s.whatsapp.net', 'lid' => 'lid-uk']], 200),
    ]);

    $action = app(ValidatePhoneAction::class);
    $result = $action->handle(makeWuzService(), '+441234567890');

    expect($result)->toBeInstanceOf(ValidatedPhone::class);
    expect($result->phone)->toBe('441234567890');
    expect($result->jid)->toBe('4412@s.whatsapp.net');
    expect($result->lid)->toBe('lid-uk');

    expect(WuzPhoneJid::where('phone', '441234567890')->first()->jid)->toBe('4412@s.whatsapp.net');
});

it('returns from cache when jid already exists', function () {
    WuzPhoneJid::create([
        'phone' => '5511999999999',
        'jid' => 'cached@s.whatsapp.net',
        'lid' => 'cached-lid',
    ]);

    $action = app(ValidatePhoneAction::class);
    $result = $action->handle(makeWuzService(), '5511999999999');

    expect($result->jid)->toBe('cached@s.whatsapp.net');

    Http::assertNothingSent();
});

it('tries 12-digit version first for Brazilian 13-digit number and succeeds', function () {
    Http::fake([
        '*/user/lid/551199999999' => Http::response(['data' => ['jid' => '5511@short.net', 'lid' => 'lid-short']], 200),
    ]);

    $action = app(ValidatePhoneAction::class);
    $result = $action->handle(makeWuzService(), '5511999999999');

    expect($result->phone)->toBe('551199999999');
    expect($result->jid)->toBe('5511@short.net');

    expect(WuzPhoneJid::where('phone', '551199999999')->first()->jid)->toBe('5511@short.net');
});

it('falls back to 13-digit when 12-digit fails for Brazilian number', function () {
    Http::fake([
        '*/user/lid/551199999999' => Http::response('Not found', 404),
        '*/user/lid/5511999999999' => Http::response(['data' => ['jid' => '5511@long.net', 'lid' => 'lid-long']], 200),
    ]);

    $action = app(ValidatePhoneAction::class);
    $result = $action->handle(makeWuzService(), '5511999999999');

    expect($result->phone)->toBe('5511999999999');
    expect($result->jid)->toBe('5511@long.net');
});

it('only caches the resolved phone variant, not the original 13-digit', function () {
    Http::fake([
        '*/user/lid/558393383823' => Http::response(['data' => ['jid' => '5583@s.whatsapp.net', 'lid' => 'lid-br']], 200),
    ]);

    $action = app(ValidatePhoneAction::class);
    $action->handle(makeWuzService(), '5583993383823');

    expect(WuzPhoneJid::where('phone', '558393383823')->first()->jid)->toBe('5583@s.whatsapp.net');
    expect(WuzPhoneJid::where('phone', '5583993383823')->first())->toBeNull();
});

it('skips API on repeated Brazilian 13-digit calls via 12-digit cache', function () {
    Http::fake([
        '*/user/lid/558393383823' => Http::response(['data' => ['jid' => '5583@s.whatsapp.net', 'lid' => 'lid-br']], 200),
    ]);

    $action = app(ValidatePhoneAction::class);
    $wuz = makeWuzService();

    $first = $action->handle($wuz, '5583993383823');
    expect($first->phone)->toBe('558393383823');

    Http::fake([
        '*/user/lid/*' => Http::response('Should not be called', 500),
    ]);

    $second = $action->handle($wuz, '5583993383823');

    expect($second->phone)->toBe('558393383823');
    expect($second->jid)->toBe('5583@s.whatsapp.net');
});

it('returns cached 12-digit variant for Brazilian 13-digit input', function () {
    WuzPhoneJid::create([
        'phone' => '551199999999',
        'jid' => 'cached-short@s.whatsapp.net',
        'lid' => 'cached-lid',
    ]);

    $action = app(ValidatePhoneAction::class);
    $result = $action->handle(makeWuzService(), '5511999999999');

    expect($result->phone)->toBe('551199999999');
    expect($result->jid)->toBe('cached-short@s.whatsapp.net');

    Http::assertNothingSent();
});

it('throws for non-Brazilian invalid phone', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['441234567890' => [false]]),
    ]);

    $action = app(ValidatePhoneAction::class);
    $action->handle(makeWuzService(), '+441234567890');
})->throws(WuzApiException::class);

it('throws when both Brazilian attempts fail', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['551199999999' => [false], '5511999999999' => [false]]),
    ]);

    $action = app(ValidatePhoneAction::class);

    try {
        $action->handle(makeWuzService(), '5511999999999');
        $this->fail('Expected WuzApiException');
    } catch (WuzApiException $e) {
        expect($e->getMessage())->toContain('551199999999');
        expect($e->getMessage())->toContain('5511999999999');
    }
});

it('throws PhoneNotRegisteredException (a WuzApiException) for unregistered numbers', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['441234567890' => [false]]),
    ]);

    $action = app(ValidatePhoneAction::class);

    try {
        $action->handle(makeWuzService(), '+441234567890');
        $this->fail('Expected PhoneNotRegisteredException');
    } catch (WuzApiException $e) {
        expect($e)->toBeInstanceOf(PhoneNotRegisteredException::class);
    }
});

it('negative-caches an unregistered Brazilian number and skips the API within TTL', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['551199999999' => [false], '5511999999999' => [false]]),
    ]);

    $action = app(ValidatePhoneAction::class);
    $wuz = makeWuzService();

    try {
        $action->handle($wuz, '5511999999999');
        $this->fail('Expected PhoneNotRegisteredException on first lookup');
    } catch (PhoneNotRegisteredException) {
        // expected — both forms 404'd and are now negative-cached
    }

    // The number would resolve if queried now; the negative cache must short-circuit
    // so the API is never consulted and it still reports unregistered.
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => 'now@s.whatsapp.net', 'lid' => 'x']], 200),
        '*/user/check' => checkResponse(['551199999999' => [true, 'now@lid']]),
    ]);

    try {
        $action->handle($wuz, '5511999999999');
        $this->fail('Negative cache should short-circuit before hitting the API');
    } catch (PhoneNotRegisteredException) {
        // expected — served from the negative cache, API not consulted
    }

    // Both forms were recorded as negative (null jid) so future lookups short-circuit.
    expect(WuzPhoneJid::where('phone', '551199999999')->first()?->jid)->toBeNull();
    expect(WuzPhoneJid::where('phone', '5511999999999')->first()?->jid)->toBeNull();
});

it('re-checks an unregistered number once the TTL has elapsed', function () {
    // A negative-cache entry last checked well beyond the TTL window. Aged via the raw
    // query builder so Eloquent's auto-timestamp does not reset updated_at to now.
    WuzPhoneJid::create(['phone' => '441234567890', 'jid' => null, 'lid' => null]);
    \Illuminate\Support\Facades\DB::table((new WuzPhoneJid)->getTable())
        ->where('phone', '441234567890')
        ->update(['updated_at' => now()->subDays((int) config('wuz.phone.unregistered_ttl_days', 14) + 7)->toDateTimeString()]);

    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => 'back@s.whatsapp.net', 'lid' => 'y']], 200),
    ]);

    $result = app(ValidatePhoneAction::class)->handle(makeWuzService(), '+441234567890');

    expect($result->jid)->toBe('back@s.whatsapp.net');
    expect(WuzPhoneJid::where('phone', '441234567890')->first()->jid)->toBe('back@s.whatsapp.net');
});

it('does not negative-cache a transient (non-404) failure', function () {
    Http::fake([
        '*/user/lid/441234567890' => Http::response('boom', 500),
    ]);

    $action = app(ValidatePhoneAction::class);

    expect(fn () => app(ValidatePhoneAction::class)->handle(makeWuzService(), '+441234567890'))
        ->toThrow(WuzApiException::class);

    // A 500 is transient: no row should be written, so the next attempt retries the API.
    expect(WuzPhoneJid::where('phone', '441234567890')->first())->toBeNull();
});

// /user/lid only reads the session's local PN→LID store: a 404 there means "this session
// has not learned the mapping yet", never "not on WhatsApp". /user/check asks WhatsApp.

it('confirms through /user/check when the session has no LID mapping yet', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse([
            '554799991424' => [true, '61881955963051@lid'],
            '5547999991424' => [false],
        ]),
    ]);

    $result = app(ValidatePhoneAction::class)->handle(makeWuzService(), '5547999991424');

    expect($result->phone)->toBe('554799991424')
        ->and($result->jid)->toBe('61881955963051@lid')
        ->and($result->lid)->toBe('61881955963051@lid');
    expect(WuzPhoneJid::where('phone', '554799991424')->value('jid'))->toBe('61881955963051@lid');

    // One authoritative query covering both Brazilian forms, short form first.
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/user/check')
        && $request['Phone'] === ['554799991424', '5547999991424']);
});

it('keeps a phone-server JID from /user/check without calling it a LID', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['441234567890' => [true, '441234567890@s.whatsapp.net']]),
    ]);

    $result = app(ValidatePhoneAction::class)->handle(makeWuzService(), '+441234567890');

    expect($result->phone)->toBe('441234567890')
        ->and($result->jid)->toBe('441234567890@s.whatsapp.net')
        ->and($result->lid)->toBeNull();
});

it('resolves the 13-digit form when only it is on WhatsApp', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse([
            '551199999999' => [false],
            '5511999999999' => [true, '212206515970244@lid'],
        ]),
    ]);

    $result = app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999');

    expect($result->phone)->toBe('5511999999999')
        ->and($result->jid)->toBe('212206515970244@lid');
    // The form WhatsApp explicitly refused is remembered; the confirmed one is cached.
    expect(WuzPhoneJid::where('phone', '551199999999')->first()?->jid)->toBeNull()
        ->and(WuzPhoneJid::where('phone', '551199999999')->exists())->toBeTrue()
        ->and(WuzPhoneJid::where('phone', '5511999999999')->value('jid'))->toBe('212206515970244@lid');
});

it('does not remember a number as unregistered from a LID miss alone', function (int $status) {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => Http::response('nope', $status),
    ]);

    try {
        app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999');
        $this->fail('Expected WuzApiException');
    } catch (WuzApiException $e) {
        expect($e)->not->toBeInstanceOf(PhoneNotRegisteredException::class);
    }

    expect(WuzPhoneJid::count())->toBe(0);
})->with([
    'route missing' => 404,
    'server error' => 500,
    'no session' => 503,
]);

it('does not remember a number as unregistered when the gateway is unreachable', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => Http::failedConnection(),
    ]);

    expect(fn () => app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999'))
        ->toThrow(\Illuminate\Http\Client\ConnectionException::class);

    expect(WuzPhoneJid::count())->toBe(0);
});

it('treats an answer that names none of the queried forms as inconclusive', function () {
    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['999' => [true, 'someone@lid']]),
    ]);

    try {
        app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999');
        $this->fail('Expected WuzApiException');
    } catch (WuzApiException $e) {
        expect($e)->not->toBeInstanceOf(PhoneNotRegisteredException::class);
    }

    expect(WuzPhoneJid::count())->toBe(0);
});

it('lets a positive confirmation override an earlier negative entry', function () {
    // A negative entry for the short form, written before the gateway knew better and
    // already past its TTL, must not keep blocking once WhatsApp confirms the number.
    WuzPhoneJid::create(['phone' => '551199999999', 'jid' => null, 'lid' => null]);
    \Illuminate\Support\Facades\DB::table((new WuzPhoneJid)->getTable())
        ->where('phone', '551199999999')
        ->update(['updated_at' => now()->subDays((int) config('wuz.phone.unregistered_ttl_days', 14) + 1)->toDateTimeString()]);

    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['551199999999' => [true, '1234@lid'], '5511999999999' => [false]]),
    ]);

    $result = app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999');

    expect($result->phone)->toBe('551199999999');
    expect(WuzPhoneJid::where('phone', '551199999999')->value('jid'))->toBe('1234@lid');

    Http::fake(['*' => Http::response('Should not be called', 500)]);
    expect(app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999')->jid)->toBe('1234@lid');
});

it('only asks /user/check about forms not already known to be unregistered', function () {
    WuzPhoneJid::create(['phone' => '551199999999', 'jid' => null, 'lid' => null]);

    Http::fake([
        '*/user/lid/*' => lidMiss(),
        '*/user/check' => checkResponse(['5511999999999' => [true, '5511999999999@s.whatsapp.net']]),
    ]);

    $result = app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999');

    expect($result->phone)->toBe('5511999999999');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/user/check')
        && $request['Phone'] === ['5511999999999']);
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/user/lid/551199999999'));
});

it('resolves from the local LID store without asking WhatsApp when the mapping exists', function () {
    Http::fake([
        '*/user/lid/551199999999' => Http::response(['data' => ['jid' => '551199999999@s.whatsapp.net', 'lid' => '1@lid']], 200),
    ]);

    app(ValidatePhoneAction::class)->handle(makeWuzService(), '5511999999999');

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/user/check'));
});
