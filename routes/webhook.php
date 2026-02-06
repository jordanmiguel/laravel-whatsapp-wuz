<?php

use Illuminate\Support\Facades\Route;
use JordanMiguel\Wuz\Http\Controllers\WuzWebhookController;

Route::post(config('wuz.webhook.path', 'api/wuz/webhook/{token}'), WuzWebhookController::class)
    ->name('wuz.webhook')
    ->middleware(config('wuz.webhook.middleware', []));
