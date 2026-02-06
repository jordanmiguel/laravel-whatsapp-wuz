<?php

use JordanMiguel\Wuz\Notifications\WuzMessage;

it('creates a message with content', function () {
    $message = new WuzMessage('Hello World');

    expect($message->content)->toBe('Hello World');
    expect($message->mediaUrl)->toBeNull();
});

it('creates via static factory', function () {
    $message = WuzMessage::create('Test message');

    expect($message->content)->toBe('Test message');
});

it('supports media URL', function () {
    $message = WuzMessage::create('Caption')->media('http://example.com/image.png');

    expect($message->content)->toBe('Caption');
    expect($message->mediaUrl)->toBe('http://example.com/image.png');
});
