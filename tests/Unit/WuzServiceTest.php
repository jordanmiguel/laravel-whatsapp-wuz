<?php

use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Services\WuzService;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('lists users via admin endpoint', function () {
    Http::fake([
        '*/admin/users' => Http::response(['data' => [['id' => 1, 'name' => 'test']]], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', adminToken: 'admin-token');
    $result = $service->listUsers();

    expect($result['data'])->toHaveCount(1);
});

it('adds a user via admin endpoint', function () {
    Http::fake([
        '*/admin/users' => Http::response(['data' => ['id' => 42]], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', adminToken: 'admin-token');
    $result = $service->addUser('Test Device', 'test-token', 'http://example.com/webhook');

    expect($result['data']['id'])->toBe(42);
});

it('deletes a user via admin endpoint', function () {
    Http::fake([
        '*/admin/users/42/full' => Http::response(['success' => true], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', adminToken: 'admin-token');
    $result = $service->deleteUser('42');

    expect($result['success'])->toBeTrue();
});

it('connects a session', function () {
    Http::fake([
        '*/session/connect' => Http::response(['data' => ['connected' => true]], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', userToken: 'user-token');
    $result = $service->sessionConnect();

    expect($result['data']['connected'])->toBeTrue();
});

it('gets session status', function () {
    Http::fake([
        '*/session/status' => Http::response(['data' => ['loggedIn' => true, 'jid' => '5511@s.whatsapp.net']], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', userToken: 'user-token');
    $result = $service->sessionStatus();

    expect($result['data']['loggedIn'])->toBeTrue();
    expect($result['data']['jid'])->toBe('5511@s.whatsapp.net');
});

it('sends a text message', function () {
    Http::fake([
        '*/chat/send/text' => Http::response(['data' => ['sent' => true]], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', userToken: 'user-token');
    $result = $service->sendMessageText('5511999999999', 'Hello!');

    expect($result['data']['sent'])->toBeTrue();
    Http::assertSent(fn ($request) => $request['Phone'] === '5511999999999'
        && $request['Body'] === 'Hello!'
        && isset($request['Id'])
    );
});

it('sends an image message', function () {
    Http::fake([
        '*/chat/send/image' => Http::response(['data' => ['sent' => true]], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', userToken: 'user-token');
    $service->sendMessageImage('5511999999999', 'data:image/png;base64,abc', 'test caption');

    Http::assertSent(fn ($request) => $request['Phone'] === '5511999999999'
        && $request['Caption'] === 'test caption'
    );
});

it('sends a document message', function () {
    Http::fake([
        '*/chat/send/document' => Http::response(['data' => ['sent' => true]], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', userToken: 'user-token');
    $service->sendMessageDocument('5511999999999', 'base64data', 'file.pdf');

    Http::assertSent(fn ($request) => $request['FileName'] === 'file.pdf');
});

it('resolves phone to JID', function () {
    Http::fake([
        '*/user/lid/5511999999999' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => 'lid123']], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', userToken: 'user-token');
    $result = $service->phoneToJid('5511999999999');

    expect($result['data']['jid'])->toBe('5511@s.whatsapp.net');
});

it('sets webhook events', function () {
    Http::fake([
        '*/webhook' => Http::response(['success' => true], 200),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', userToken: 'user-token');
    $result = $service->setWebhookEvents('http://example.com/webhook');

    expect($result['success'])->toBeTrue();
});

it('throws WuzApiException on failure', function () {
    Http::fake([
        '*/admin/users' => Http::response('Unauthorized', 401),
    ]);

    $service = new WuzService(apiUrl: 'http://wuz.test', adminToken: 'bad-token');
    $service->listUsers();
})->throws(WuzApiException::class);
