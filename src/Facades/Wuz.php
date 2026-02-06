<?php

namespace JordanMiguel\Wuz\Facades;

use Illuminate\Support\Facades\Facade;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

/**
 * @method static \JordanMiguel\Wuz\Services\WuzService make(\JordanMiguel\Wuz\Models\WuzDevice $device)
 * @method static \JordanMiguel\Wuz\Services\WuzService admin()
 *
 * @see \JordanMiguel\Wuz\Services\WuzServiceFactory
 */
class Wuz extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WuzServiceFactory::class;
    }
}
