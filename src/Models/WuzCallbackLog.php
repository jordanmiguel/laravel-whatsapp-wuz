<?php

namespace JordanMiguel\Wuz\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WuzCallbackLog extends Model
{
    protected $fillable = [
        'wuz_device_id',
        'event_type',
        'payload',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('wuz.table_names.callback_logs', 'wuz_callback_logs');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(WuzDevice::class, 'wuz_device_id');
    }
}
