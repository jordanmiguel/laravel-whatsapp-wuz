<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Services\WuzService;

it('reconnects with proxy in the order disconnect, set-proxy, connect', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);
    $svc = new WuzService(apiUrl: 'http://wuz.test', userToken: 'tok');

    $svc->reconnectWithProxy('http://u:p@geo.iproyal.com:12321');

    $recorded = collect(Http::recorded());
    $paths = $recorded->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all();
    expect($paths)->toBe(['/session/disconnect', '/session/proxy', '/session/connect']);

    // set-proxy body must carry the enable flag + url
    $proxyReq = $recorded->first(fn ($pair) => str_contains($pair[0]->url(), '/session/proxy'))[0];
    expect($proxyReq['enable'])->toBeTrue()
        ->and($proxyReq['proxy_url'])->toBe('http://u:p@geo.iproyal.com:12321');
});

$failProxyFake = fn () => Http::fake(fn ($request) => str_contains($request->url(), '/session/proxy')
    ? Http::response(['error' => 'boom'], 500)
    : Http::response(['data' => []], 200));

it('stays disconnected when proxy set fails and direct fallback is disabled (default)', function () use ($failProxyFake) {
    $failProxyFake();
    $svc = new WuzService(apiUrl: 'http://wuz.test', userToken: 'tok');

    expect(fn () => $svc->reconnectWithProxy('http://u:p@geo.iproyal.com:12321'))
        ->toThrow(WuzApiException::class);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/session/connect'));
});

it('connects directly when proxy set fails and direct fallback is enabled', function () use ($failProxyFake) {
    config(['wuz.proxy.connect_directly_on_failure' => true]);
    $failProxyFake();
    $svc = new WuzService(apiUrl: 'http://wuz.test', userToken: 'tok');

    $svc->reconnectWithProxy('http://u:p@geo.iproyal.com:12321');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/session/connect'));
});
