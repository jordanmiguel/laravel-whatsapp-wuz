<?php

use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

it('stores an encrypted proxy_url and a proxy_session', function () {
    $owner = TestOwner::create(['name' => 'Clinic']);
    $device = $owner->wuzDevices()->create([
        'name' => 'Phone', 'token' => 't', 'proxy_url' => 'http://u:p@geo.iproyal.com:12321', 'proxy_session' => 'aB12cD34',
    ]);

    expect($device->fresh()->proxy_url)->toBe('http://u:p@geo.iproyal.com:12321')
        ->and($device->fresh()->proxy_session)->toBe('aB12cD34');

    // stored ciphertext is not the plaintext
    $raw = \Illuminate\Support\Facades\DB::table('wuz_devices')->where('id', $device->id)->value('proxy_url');
    expect($raw)->not->toBe('http://u:p@geo.iproyal.com:12321');
});
