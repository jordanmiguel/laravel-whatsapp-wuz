<?php

namespace JordanMiguel\Wuz\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;

class WuzWebhookController
{
    public function __invoke(Request $request, string $token, HandleWebhookCallbackAction $action): JsonResponse
    {
        $action->handle(
            token: $token,
            payload: $request->all(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['status' => 'ok']);
    }
}
