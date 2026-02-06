<?php

namespace JordanMiguel\Wuz\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JordanMiguel\Wuz\Contracts\HasWuzDevices as HasWuzDevicesContract;
use JordanMiguel\Wuz\Traits\HasWuzDevices;

class TestTenant extends Model implements HasWuzDevicesContract
{
    use HasWuzDevices;

    protected $table = 'test_tenants';

    protected $fillable = ['name'];
}
