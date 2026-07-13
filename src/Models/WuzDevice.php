<?php

namespace JordanMiguel\Wuz\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JordanMiguel\Wuz\Database\Factories\WuzDeviceFactory;

/**
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string|null $device_id
 * @property string $name
 * @property string|null $token
 * @property bool $connected WuzAPI's `loggedIn`: the session is authenticated
 * @property \Illuminate\Support\Carbon|null $disconnected_at When it went down; null while up or never paired
 * @property string|null $jid
 * @property string|null $proxy_url
 * @property string|null $proxy_session
 * @property bool $is_default
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
        'disconnected_at',
        'jid',
        'is_default',
        'created_by',
        'proxy_url',
        'proxy_session',
    ];

    protected function casts(): array
    {
        return [
            'connected' => 'boolean',
            'disconnected_at' => 'datetime',
            'is_default' => 'boolean',
            'proxy_url' => 'encrypted',
        ];
    }

    public function getTable(): string
    {
        return config('wuz.table_names.devices', 'wuz_devices');
    }

    /**
     * The session is authenticated. Stops the downtime clock.
     *
     * The jid is only ever written from a live session: WuzAPI keeps serving the last known jid
     * long after a logout, so writing it from a down session would resurrect a number the
     * LoggedOut handler had deliberately cleared.
     */
    public function markConnected(?string $jid = null): void
    {
        $this->update([
            'connected' => true,
            'disconnected_at' => null,
            ...($jid !== null ? ['jid' => $jid] : []),
        ]);
    }

    /**
     * The session is down. Starts the downtime clock, and never restarts one already running —
     * a socket that drops five times in an hour has been down since the first drop, not the last.
     *
     * `$unlinked` is the terminal case (LoggedOut): the phone is gone and the jid with it.
     */
    public function markDisconnected(bool $unlinked = false): void
    {
        // A device that never paired is not "down", it is unconfigured. Stamping it would make
        // it look like a session worth waiting for.
        $everPaired = $this->connected || $this->jid !== null || $this->disconnected_at !== null;

        $this->update([
            'connected' => false,
            'disconnected_at' => $everPaired ? ($this->disconnected_at ?? now()) : null,
            ...($unlinked ? ['jid' => null] : []),
        ]);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
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
