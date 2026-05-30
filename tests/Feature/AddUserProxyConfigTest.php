<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Facades\Wuz;

beforeEach(fn () => Http::fake(['*/admin/users' => Http::response(['data' => ['id' => 1]], 200)]));

it('includes proxyConfig when a proxy url is given', function () {
    Wuz::admin()->addUser('Clinic', 'tok', 'https://hook', proxyUrl: 'http://u:p@geo.iproyal.com:12321');

    Http::assertSent(fn ($r) => $r['proxyConfig']['enabled'] === true
        && $r['proxyConfig']['proxyURL'] === 'http://u:p@geo.iproyal.com:12321');
});

it('omits proxyConfig when no proxy url is given (backward compatible)', function () {
    Wuz::admin()->addUser('Clinic', 'tok', 'https://hook');

    Http::assertSent(fn ($r) => ! isset($r['proxyConfig']));
});
