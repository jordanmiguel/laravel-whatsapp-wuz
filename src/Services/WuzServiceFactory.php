<?php

namespace JordanMiguel\Wuz\Services;

use JordanMiguel\Wuz\Models\WuzDevice;

class WuzServiceFactory
{
    public function make(WuzDevice $device): WuzService
    {
        return new WuzService(
            apiUrl: config('wuz.api_url'),
            userToken: $device->token,
        );
    }

    public function admin(): WuzService
    {
        return new WuzService(
            apiUrl: config('wuz.api_url'),
            adminToken: config('wuz.admin_token'),
        );
    }
}
