<?php

namespace JordanMiguel\Wuz\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $phone
 * @property string|null $jid
 * @property string|null $lid
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
