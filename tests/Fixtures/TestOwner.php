<?php

namespace JordanMiguel\Wuz\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use JordanMiguel\Wuz\Contracts\HasWuzDevices as HasWuzDevicesContract;
use JordanMiguel\Wuz\Traits\HasWuzDevices;

class TestOwner extends Model implements HasWuzDevicesContract
{
    use HasWuzDevices;

    protected $table = 'test_owners';

    protected $fillable = ['name'];
}
