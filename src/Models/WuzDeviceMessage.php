<?php

namespace JordanMiguel\Wuz\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WuzDeviceMessage extends Model
{
    protected $fillable = [
        'wuz_device_id',
        'chat_jid',
        'sender_jid',
        'message',
        'metadata',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('wuz.table_names.device_messages', 'wuz_device_messages');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(WuzDevice::class, 'wuz_device_id');
    }
}
