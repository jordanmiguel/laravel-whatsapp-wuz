<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\StoreDeviceAction;
use JordanMiguel\Wuz\Data\StoreDeviceData;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(fn () => Http::fake([
    '*/admin/users' => Http::response(['data' => ['id' => 7]], 200),
    '*/session/connect' => Http::response(['data' => []], 200),
]));

it('persists proxy fields and forwards proxyConfig to addUser', function () {
    $owner = TestOwner::create(['name' => 'Clinic']);

    $device = app(StoreDeviceAction::class)->handle($owner, new StoreDeviceData(
        name: 'Phone', proxyUrl: 'http://u:p@geo.iproyal.com:12321', proxySession: 'aB12cD34',
    ));

    expect($device->proxy_url)->toBe('http://u:p@geo.iproyal.com:12321')
        ->and($device->proxy_session)->toBe('aB12cD34');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/users')
        && ($r['proxyConfig']['proxyURL'] ?? null) === 'http://u:p@geo.iproyal.com:12321');
});
