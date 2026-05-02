# Selective Event Logging & Dispatch Controls

**Date:** 2026-05-02
**Status:** Draft — pending implementation
**Type:** Breaking change (major version bump)

## Problem

Today, every webhook callback the package receives produces:

1. A `wuz_callback_logs` row (full JSON payload + IP + user-agent).
2. A `WebhookReceived` event dispatch.
3. Type-specific side effects (`WuzDeviceMessage` row + `MessageReceived` for `MESSAGE`; `device.connected` mutation + `DeviceDisconnected` for `DISCONNECTED`/`LOGGED_OUT`).

There are 47 distinct `WuzEventType` cases. Several are extremely high-volume (`RECEIPT`, `READ_RECEIPT`, `PRESENCE`, `CHAT_PRESENCE`, `HISTORY_SYNC`, `APP_STATE*`, `OFFLINE_SYNC*`, `CALL_RELAY_LATENCY`, `KEEP_ALIVE_*`). In production this generates an unmanageable volume of `wuz_callback_logs` rows and `WebhookReceived` dispatches.

Package consumers currently have **no knobs** to opt out of any of this.

## Goals

- Give consumers per-event-type control over **what is logged** to `wuz_callback_logs`.
- Give consumers per-event-type control over **what triggers `WebhookReceived`**.
- Give consumers a toggle for **whether incoming messages are persisted** to `wuz_device_messages`.
- Ship safe, narrow defaults so the volume problem is fixed on upgrade without consumer action.
- Preserve the package's *behavioural* job (turning webhooks into messages and connection state) as reliable and not toggleable per-type.

## Non-goals

- Per-device override of these settings. (Possible future extension; out of scope here.)
- Closure / predicate filters beyond event-type. (Same.)
- Payload trimming or sanitization within stored rows. (See "Known follow-up" below — `media_delivery=base64` on the WUZ side can inflate logged `MESSAGE` rows; a future spec can address.)
- Changes to the existing `download_media` flag, which already defaults to `false` in `config/wuz.php` (line 7: `env('WUZ_DOWNLOAD_MEDIA', false)`). Documenting this in `UPGRADING.md` is in scope; changing the default is not (it already is `false`).
- Cleanup / retention for `storage/app/public/wuz-media/` files written when `download_media=true`. (Different mechanism, follow-up spec.)
- Migration of existing `wuz_callback_logs` rows.

## Upstream context (WUZ server)

Verified against [`asternic/wuzapi`](https://github.com/asternic/wuzapi) `main` branch:

- WUZ does **not** persist webhook events server-side. Events are forwarded to the per-user `webhook` URL (and optionally a global webhook / RabbitMQ queue if `RABBITMQ_URL` is set). Logs go to stdout/stderr via zerolog.
- The only event-related persistence on the WUZ side is the `message_history` table, which is **opt-in per user** via the `history` integer column on `users` (default `0` = disabled). When set to N, WUZ retains the last N messages per chat with text + media link, trimmed automatically.
- Media on the WUZ side can be `base64` (default; delivered inline in webhooks) or `s3` (per-user S3 config with `s3_retention_days`, default 30).

**Implication:** the volume problem is squarely on the consumer side. This design fixes it where it lives.

**Known follow-up:** with `media_delivery=base64` (WUZ default), `MESSAGE` event payloads for media include the base64-encoded file inline. When `MESSAGE` is in `logging.event_types` (which it is by default), each media message inflates the `wuz_callback_logs.payload` JSON column to the file size. Mitigations are out of scope for this spec but worth a follow-up: either payload sanitization in the action, or guidance to switch the WUZ-side `media_delivery` to `s3`.

## Design

### Public API — three config keys in `config/wuz.php`

```php
'logging' => [
    'event_types' => WuzEventType::defaultLoggingTypes(),
],

'events' => [
    'event_types' => WuzEventType::defaultDispatchTypes(),
],

'messages' => [
    'store' => true,
],
```

Allowlists accept either `WuzEventType` enum cases or string values (`'Message'`). The wildcard string `'*'` means "all types" — the escape hatch for restoring legacy behaviour. Empty array `[]` means "none".

### Defaults (single source of truth on the enum)

```php
// src/Enums/WuzEventType.php

/** @return array<int, self> */
public static function defaultLoggingTypes(): array
{
    return [
        self::MESSAGE,
        self::CONNECTED,
        self::DISCONNECTED,
        self::LOGGED_OUT,
        self::PAIR_SUCCESS,
        self::PAIR_ERROR,
        self::QR,
        self::CONNECT_FAILURE,
        self::STREAM_ERROR,
        self::TEMPORARY_BAN,
        self::CLIENT_OUTDATED,
        self::UNKNOWN,
    ];
}

/** @return array<int, self> */
public static function defaultDispatchTypes(): array
{
    return [self::MESSAGE, self::CONNECTED, self::DISCONNECTED, self::LOGGED_OUT];
}
```

**Rationale:**

- Logging defaults include the business-critical event (`MESSAGE`), full lifecycle (`CONNECTED`/`DISCONNECTED`/`LOGGED_OUT`), pairing (`PAIR_SUCCESS`/`PAIR_ERROR`/`QR`), and error states worth a forensic trail (`CONNECT_FAILURE`, `STREAM_ERROR`, `TEMPORARY_BAN`, `CLIENT_OUTDATED`). `UNKNOWN` is included so unrecognized upstream payloads aren't silently dropped.
- Dispatch defaults are the four types that already have typed events in the package (`MessageReceived`, `DeviceConnected`, `DeviceDisconnected`). Anything else — opt in.
- `messages.store = true` preserves message persistence; the only volume scope is `MESSAGE` events, which is bounded.

### Predicate methods on the enum

```php
// src/Enums/WuzEventType.php

public function shouldLog(): bool
{
    return $this->isAllowedBy(config('wuz.logging.event_types', self::defaultLoggingTypes()));
}

public function shouldDispatch(): bool
{
    return $this->isAllowedBy(config('wuz.events.event_types', self::defaultDispatchTypes()));
}

private function isAllowedBy(array $allowed): bool
{
    if (in_array('*', $allowed, true)) {
        return true;
    }

    foreach ($allowed as $entry) {
        $case = $entry instanceof self ? $entry : self::tryFrom($entry);
        if ($case === $this) {
            return true;
        }
    }

    return false;
}
```

The fallback to `self::default*Types()` inside `config()` calls means consumers who upgrade without re-publishing `config/wuz.php` automatically inherit the new safe defaults — they don't keep the v1 firehose by accident.

### Gating in `HandleWebhookCallbackAction::handle()`

```php
$eventType = WuzEventType::detect($payload);

if ($eventType->shouldLog()) {
    WuzCallbackLog::create([
        'wuz_device_id' => $device->id,
        'event_type' => $eventType->value,
        'payload' => $payload,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
    ]);
}

if ($eventType->shouldDispatch()) {
    WebhookReceived::dispatch($device, $eventType, $payload);
}

match ($eventType) {
    WuzEventType::MESSAGE => $this->handleMessage($device, $payload),
    WuzEventType::DISCONNECTED => $this->handleDisconnected($device),
    WuzEventType::LOGGED_OUT => $this->handleLoggedOut($device),
    default => null,
};
```

`handleMessage` early-returns when storage is off:

```php
private function handleMessage(WuzDevice $device, array $data): void
{
    if (! config('wuz.messages.store', true)) {
        return;
    }
    // ... existing parsing + WuzDeviceMessage::create + MessageReceived::dispatch unchanged
}
```

When `messages.store = false`:

- No `WuzDeviceMessage` row written.
- No `MessageReceived` dispatch (consumers wanting message data subscribe to `WebhookReceived` for `MESSAGE` and parse the payload themselves).
- Media download is naturally skipped (the existing `download_media` flag still nests inside `handleMessage`).

### What stays always-on

- Connection state mutations: `$device->update(['connected' => false])` in `handleDisconnected`/`handleLoggedOut`. These reflect the device's real state and are core to the package's job, not observability.
- `DeviceDisconnected` dispatch when `DISCONNECTED`/`LOGGED_OUT` arrive (paired with the state mutation above).

These are not gated by any allowlist. (`DeviceConnected` is dispatched from `GetDeviceStatusAction`, not from the webhook flow, so it is unaffected by this design.)

## Migration

This is a **breaking change** — current consumers logging everything will start logging only ~12 types after upgrade.

- Major version bump.
- New `UPGRADING.md` at repo root documenting:
  - What changed and why (storage volume).
  - Restore-previous-behaviour snippet:
    ```php
    'logging' => ['event_types' => ['*']],
    'events'  => ['event_types' => ['*']],
    ```
  - Pointer to `WuzEventType::cases()` for the full type list and to `WuzEventType::defaultLoggingTypes()` / `defaultDispatchTypes()` for the new defaults.
  - Reminder that `download_media` already defaults to `false` and remains so in this release — consumers must opt in via `WUZ_DOWNLOAD_MEDIA=true`. Files written when enabled accumulate in `storage/app/public/wuz-media/{device_id}/` with no cleanup; a retention strategy is a follow-up.
  - Note about WUZ-side `media_delivery=base64` inflating logged `MESSAGE` payloads (see Upstream context section) and the recommendation to set `media_delivery=s3` upstream if media volume is a concern.
- `README.md` gains a "Configuration — selective logging and events" subsection.
- No data migration. Existing `wuz_callback_logs` rows are untouched; new rows simply stop being written for excluded types from upgrade-time onward.

## Testing

### New test files

**`tests/Unit/WuzEventTypeGatingTest.php`** — predicate methods in isolation:

- `shouldLog()` returns true/false correctly across config shapes (enum cases, strings, `'*'`, empty, missing key).
- `shouldDispatch()` symmetric.
- `defaultLoggingTypes()` and `defaultDispatchTypes()` return the documented sets — regression-guards against accidental edits.

**`tests/Feature/CallbackLogGatingTest.php`** — storage allowlist:

- Type in `logging.event_types` → row created.
- Type not in list → no row.
- `'*'` wildcard → row created for any type.
- Empty array → no row for any type.
- String values accepted alongside enum cases.
- Default config (key absent) → falls back to `defaultLoggingTypes()`.
- `UNKNOWN` type → logged under defaults.

**`tests/Feature/WebhookEventGatingTest.php`** — dispatch allowlist:

- Type in `events.event_types` → `WebhookReceived` dispatched.
- Type not in list → not dispatched.
- Storage and dispatch allowlists are independent (set differently in one test; assert both behaviours separately).

**`tests/Feature/MessageStorageToggleTest.php`** — `messages.store`:

- `messages.store = true` (default) → `WuzDeviceMessage` row created, `MessageReceived` dispatched.
- `messages.store = false` → no row, no `MessageReceived` dispatch.
- `messages.store = false` → media download endpoint is not called.
- `messages.store = false` does **not** affect connection state: a `Disconnected` payload still flips `device.connected` and dispatches `DeviceDisconnected`.

### Existing test changes

**`tests/Feature/WebhookCallbackTest.php`:**

- The `Receipt`-payload test at line 19 fails under new defaults. Switch it to a default-allowed type (`Connected` or `Message`) so it continues to assert the happy-path log + dispatch behaviour. The wildcard / opt-in case is covered explicitly in `CallbackLogGatingTest`.
- Other tests already use `Message`, `Disconnected`, `LoggedOut` — all in default allowlists; unaffected.

## Files Touched

- `config/wuz.php` — three new keys (`logging`, `events`, `messages`).
- `src/Enums/WuzEventType.php` — `shouldLog()`, `shouldDispatch()`, `isAllowedBy()`, `defaultLoggingTypes()`, `defaultDispatchTypes()`.
- `src/Actions/HandleWebhookCallbackAction.php` — gating in `handle()` and early-return in `handleMessage()`.
- `tests/Feature/WebhookCallbackTest.php` — update one test (Receipt → default-allowed type).
- `tests/Unit/WuzEventTypeGatingTest.php` — new.
- `tests/Feature/CallbackLogGatingTest.php` — new.
- `tests/Feature/WebhookEventGatingTest.php` — new.
- `tests/Feature/MessageStorageToggleTest.php` — new.
- `UPGRADING.md` — new.
- `README.md` — new subsection.
