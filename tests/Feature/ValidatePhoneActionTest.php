<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\ValidatePhoneAction;
use JordanMiguel\Wuz\Data\ValidatedPhone;
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
        '*/user/lid/*' => Http::response('Not found', 404),
    ]);

    $action = app(ValidatePhoneAction::class);
    $action->handle(makeWuzService(), '+441234567890');
})->throws(WuzApiException::class);

it('throws when both Brazilian attempts fail', function () {
    Http::fake([
        '*/user/lid/551199999999' => Http::response('Not found', 404),
        '*/user/lid/5511999999999' => Http::response('Not found', 404),
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
