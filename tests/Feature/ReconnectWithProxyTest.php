<?php

use Illuminate\Support\Facades\Http;
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
