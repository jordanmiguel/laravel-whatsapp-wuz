<?php

namespace JordanMiguel\Wuz\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JordanMiguel\Wuz\Database\Factories\WuzDeviceFactory;

class WuzDevice extends Model
{
    use HasFactory;

    protected static function newFactory(): WuzDeviceFactory
    {
        return WuzDeviceFactory::new();
    }
    protected $fillable = [
        'owner_type',
        'owner_id',
        'device_id',
        'name',
        'token',
        'connected',
        'jid',
        'is_default',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'connected' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return config('wuz.table_names.devices', 'wuz_devices');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WuzDeviceMessage::class, 'wuz_device_id');
    }

    public function callbackLogs(): HasMany
    {
        return $this->hasMany(WuzCallbackLog::class, 'wuz_device_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(WuzDeviceWebhook::class, 'wuz_device_id');
    }
}
