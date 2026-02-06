<?php

namespace JordanMiguel\Wuz\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WuzDeviceWebhook extends Model
{
    protected $fillable = [
        'wuz_device_id',
        'event',
        'url',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return config('wuz.table_names.device_webhooks', 'wuz_device_webhooks');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(WuzDevice::class, 'wuz_device_id');
    }
}
