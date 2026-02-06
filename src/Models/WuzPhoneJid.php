<?php

namespace JordanMiguel\Wuz\Models;

use Illuminate\Database\Eloquent\Model;

class WuzPhoneJid extends Model
{
    protected $fillable = [
        'phone',
        'jid',
        'lid',
    ];

    public function getTable(): string
    {
        return config('wuz.table_names.phone_jids', 'wuz_phone_jids');
    }
}
