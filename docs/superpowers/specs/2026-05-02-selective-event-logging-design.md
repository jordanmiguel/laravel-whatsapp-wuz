# Selective Event Logging & Event-Driven Message Pipeline

**Date:** 2026-05-02
**Status:** Draft — pending implementation
**Type:** Breaking change (major version bump)

## Problem

Today, every webhook callback the package receives produces:

1. A `wuz_callback_logs` row (full JSON payload + IP + user-agent).
2. A `WebhookReceived` event dispatch.
3. Type-specific side effects: `WuzDeviceMessage` row + `MessageReceived` for `MESSAGE`; `device.connected` mutation + `DeviceDisconnected` for `DISCONNECTED`/`LOGGED_OUT`; optional media download to `Storage::disk('public')` when `download_media=true`.

There are ~40 distinct WUZ event types. Several are extremely high-volume (`READ_RECEIPT`, `PRESENCE`, `CHAT_PRESENCE`, `HISTORY_SYNC`, `KEEP_ALIVE_*`, `OFFLINE_SYNC*`). In production this generates an unmanageable volume of `wuz_callback_logs` rows and `WebhookReceived` dispatches. Consumers have **no knobs** to opt out.

Adjacent issues surfaced during research and review:

- **`WuzEventType` is out of sync with WUZ.** 7 enum cases never fire (WUZ remaps or swallows them); 1 emitted type (`QRTimeout`) is missing.
- **The package owns a domain decision it shouldn't.** `WuzDeviceMessage` (a model + table the package writes to from both inbound webhooks and outbound sends) imposes the package's persistence shape on every consumer. Consumers wanting different storage shape, no storage, or storage in a different system have no clean opt-out.
- **Dispatch order is unsafe.** `WebhookReceived` fires *before* state-mutating side effects, so a throwing listener can block message persistence and connection-state updates.
- **Hardcoded media path.** The action writes to `Storage::disk('public')->put('wuz-media/{device_id}/...')` with no retention, no configurable disk, no configurable path. Files accumulate indefinitely.

## Goals

- Per-event-type allowlists for **what is logged** to `wuz_callback_logs` and **what triggers `WebhookReceived`**, independent.
- Make the package **event-driven for messages** — emit `MessageReceived` (inbound) and `MessageSent` (outbound) with parsed data; let consumers persist however they like.
- Ship safe, narrow defaults so the volume problem is fixed on upgrade without consumer action.
- Align `WuzEventType` with the set of types WUZ actually emits.
- Separate emitted-event types from subscription sentinel values so the public API is honest.
- Reorder the action so consumer listener exceptions cannot block core state mutations.

## Non-goals

- Per-device override of these settings. (Future extension.)
- Closure / predicate filters beyond event-type. (Future extension.)
- Payload trimming or sanitization within stored rows beyond dropping `MESSAGE` from default logging. (See "Known follow-up".)
- Cleanup / retention for `storage/app/public/wuz-media/` files written by v1. (Different mechanism, follow-up.)
- Migration / cleanup of existing `wuz_device_messages` data. The package stops shipping the migration; consumer-side data is left alone.
- Migration of existing `wuz_callback_logs` rows. They remain readable; rows whose `event_type` matches a removed enum string still load (column is plain `string`, not enum-cast).

## Upstream context (WUZ server)

Verified against [`asternic/wuzapi`](https://github.com/asternic/wuzapi) `main`:

- WUZ does **not** persist webhook events server-side. Events are forwarded to the per-user `webhook` URL (and optionally a global webhook / RabbitMQ queue if `RABBITMQ_URL` is set). Logs go to stdout/stderr via zerolog.
- The only event-related persistence on the WUZ side is the `message_history` table — opt-in per user via the `history` integer column (default `0` = disabled).
- Media on the WUZ side can be `base64` (default; delivered inline in webhooks) or `s3` (per-user S3 config with `s3_retention_days`, default 30).

**Implication:** the volume problem is squarely on the consumer side. This design fixes it where it lives.

**Known follow-up:** with `media_delivery=base64` (WUZ default), `MESSAGE` event payloads include the base64 file inline. Dropping `MESSAGE` from default logging (see Defaults) handles the worst case out of the box. A future spec can add payload sanitization for consumers who explicitly opt `MESSAGE` back into logging.

## Enum alignment

Cross-referencing every `postmap["type"] = "..."` site in WUZ's `wmiau.go` against `WuzEventType` revealed drift in both directions, plus a semantic confusion between emitted events and subscription sentinels.

### Cases removed from `WuzEventType` (dead — WUZ never emits)

| Enum case | Reason |
|---|---|
| `RECEIPT = 'Receipt'` | WUZ remaps whatsmeow's `*events.Receipt` to **`ReadReceipt`** (`wmiau.go:1058-1059`). `READ_RECEIPT` is the real case. |
| `STREAM_REPLACED = 'StreamReplaced'` | Logged internally and `return`s — no webhook (`wmiau.go:819-821`). |
| `APP_STATE = 'AppState'` | Logged internally, no webhook (`wmiau.go:1361`). |
| `APP_STATE_SYNC_COMPLETE = 'AppStateSyncComplete'` | Handled internally, no webhook (`wmiau.go:685`). |
| `PUSH_NAME_SETTING = 'PushNameSetting'` | Bundled with `Connected` in WUZ's switch — emitted as `Connected`. |
| `QR_SCANNED_WITHOUT_MULTIDEVICE = 'QRScannedWithoutMultidevice'` | Not handled by WUZ. |
| `CAT_REFRESH_ERROR = 'CATRefreshError'` | Not handled by WUZ. |

The matching arms in `WuzEventType::label()` are dropped with the cases. Code referencing a removed case produces a fatal error after upgrade. Documented in `UPGRADING.md`.

### Case added to `WuzEventType`

- `QR_TIMEOUT = 'QRTimeout'` — emitted at `wmiau.go:541` when the QR pairing window expires. Belongs in the same family as `PAIR_ERROR` and `CONNECT_FAILURE`. Added to `defaultLoggingTypes()`.

### `ALL` moved to a new enum: `WuzEventSubscription`

`'All'` is **not an emitted event type**. It's the sentinel WUZ accepts in its per-user `events` subscription column to mean "subscribe to everything." Mixing it into `WuzEventType` (which `detect()` returns from incoming webhooks) was muddled.

New file: `src/Enums/WuzEventSubscription.php`. Initial cases — the strings WUZ recognises in subscription configuration:

```php
enum WuzEventSubscription: string
{
    case ALL = 'All';
    case MESSAGE = 'Message';
    case READ_RECEIPT = 'ReadReceipt';
    case PRESENCE = 'Presence';
    case HISTORY_SYNC = 'HistorySync';
    case CHAT_PRESENCE = 'ChatPresence';
}
```

(WUZ's README documents these as the recognised subscription values.)

The two callsites in `WuzService` that currently hardcode the string `'All'` switch to `WuzEventSubscription::ALL->value`:

- `WuzService::addUser()` line 41 — `'events' => 'All'` → `'events' => WuzEventSubscription::ALL->value`
- `WuzService::setWebhookEvents()` line 184 — `'events' => ['All']` → `'events' => [WuzEventSubscription::ALL->value]`

`WuzEventType` shrinks to **only emitted event types** plus the internal `UNKNOWN` fallback.

## Design

### Public API — `config/wuz.php`

```php
'logging' => [
    'event_types' => WuzEventType::defaultLoggingTypes(),
],

'webhook_event' => [
    'event_types' => WuzEventType::defaultDispatchTypes(),
],
```

(No `messages` block — see "Removed surface" below.)

The keys' meanings are scoped narrowly and named accordingly:

- `logging.event_types` gates **only** insertion of `wuz_callback_logs` rows.
- `webhook_event.event_types` gates **only** dispatch of the generic `WebhookReceived` Laravel event.

Typed events (`MessageReceived`, `MessageSent`, `DeviceDisconnected`) are **not gated** — they are part of the package's behavioural contract.

Both lists accept either `WuzEventType` cases or string values; `'*'` is a wildcard meaning "all"; `[]` means "none". String entries match against the **raw** payload `type`, so future event names WUZ ships before the enum is updated work without code changes.

### Defaults (single source of truth on the enum)

```php
// src/Enums/WuzEventType.php

/** @return array<int, self> */
public static function defaultLoggingTypes(): array
{
    return [
        self::CONNECTED,
        self::DISCONNECTED,
        self::LOGGED_OUT,
        self::PAIR_SUCCESS,
        self::PAIR_ERROR,
        self::QR,
        self::QR_TIMEOUT,
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
    return [
        self::MESSAGE,
        self::CONNECTED,
        self::DISCONNECTED,
        self::LOGGED_OUT,
    ];
}
```

**Rationale:**

- Logging defaults cover lifecycle (`CONNECTED`/`DISCONNECTED`/`LOGGED_OUT`), pairing (`PAIR_SUCCESS`/`PAIR_ERROR`/`QR`/`QR_TIMEOUT`), and error states (`CONNECT_FAILURE`, `STREAM_ERROR`, `TEMPORARY_BAN`, `CLIENT_OUTDATED`). `UNKNOWN` is included so unrecognized upstream payloads aren't silently dropped.
- **`MESSAGE` is intentionally excluded from default logging.** With WUZ-side `media_delivery=base64`, logging `MESSAGE` payloads inflates `wuz_callback_logs.payload` to the size of every media file. Default-out is the only honest choice. Consumers who want raw `MESSAGE` rows opt in explicitly. Trade-off documented in `UPGRADING.md`.
- Dispatch defaults are the four types with typed events in the package, so a consumer subscribing to `WebhookReceived` for those types sees the same surface they get from the typed events plus the raw payload.

### Predicate methods on the enum

```php
// src/Enums/WuzEventType.php

public function shouldLog(?string $rawType = null): bool
{
    return $this->isAllowedBy(
        config('wuz.logging.event_types', self::defaultLoggingTypes()),
        $rawType,
    );
}

public function shouldDispatch(?string $rawType = null): bool
{
    return $this->isAllowedBy(
        config('wuz.webhook_event.event_types', self::defaultDispatchTypes()),
        $rawType,
    );
}

private function isAllowedBy(array $allowed, ?string $rawType): bool
{
    if (in_array('*', $allowed, true)) {
        return true;
    }

    foreach ($allowed as $entry) {
        if ($entry instanceof self && $entry === $this) {
            return true;
        }

        if (is_string($entry)) {
            if ($entry === $this->value) {
                return true;
            }

            if ($rawType !== null && $entry === $rawType) {
                return true;
            }
        }
    }

    return false;
}
```

The `$rawType` parameter is the original payload's `type` string — so a consumer can allowlist a string like `'FooEvent'` for a future WUZ event type that doesn't yet have an enum case. The resolved enum value would be `UNKNOWN`, but the raw string still matches.

### Gating + dispatch order + best-effort observability in `HandleWebhookCallbackAction::handle()`

Three concerns to address together:

1. **Order:** state mutations must run before any event dispatch so a synchronous listener exception cannot block them.
2. **Isolation of listener exceptions:** Laravel's synchronous event dispatcher propagates listener exceptions to the caller. The webhook route is the caller — if it fails, WUZ retries the webhook (`asternic/wuzapi/helpers.go`), causing duplicate processing of an event whose state mutations have already committed.
3. **Isolation of log-insert exceptions:** `WuzCallbackLog::create()` after side effects has the same retry/duplication risk if the DB throws (connection drop, constraint violation, etc.). Logging must be best-effort.

A single helper covers both observational concerns. Event objects are **constructed eagerly** before the safe boundary so that constructor `TypeError`s (package bugs) propagate normally and surface in tests; only the act of *running* the dispatch (and listeners) is wrapped:

```php
public function handle(string $token, array $payload, ?string $ipAddress = null, ?string $userAgent = null): void
{
    $device = WuzDevice::where('token', $token)->first();

    if (! $device) {
        return;
    }

    $rawType = is_string($payload['type'] ?? null) ? $payload['type'] : null;
    $eventType = WuzEventType::detect($payload);

    // 1. Run state-mutating side effects FIRST (the package's behavioural job).
    match ($eventType) {
        WuzEventType::MESSAGE => $this->handleMessage($device, $payload),
        WuzEventType::DISCONNECTED => $this->handleDisconnected($device),
        WuzEventType::LOGGED_OUT => $this->handleLoggedOut($device),
        default => null,
    };

    // 2. Best-effort log insert. DB failure is reported but does not fail the
    //    webhook route (which would cause WUZ to retry and duplicate side effects).
    if ($eventType->shouldLog($rawType)) {
        $this->safely(fn () => WuzCallbackLog::create([
            'wuz_device_id' => $device->id,
            'event_type' => $rawType ?? $eventType->value,
            'payload' => $payload,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]));
    }

    // 3. Best-effort generic WebhookReceived dispatch. Event constructed first so
    //    package-side TypeErrors propagate; only listener execution is wrapped.
    if ($eventType->shouldDispatch($rawType)) {
        $event = new WebhookReceived($device, $eventType, $payload);
        $this->safely(fn () => event($event));
    }
}

private function safely(\Closure $action): void
{
    try {
        $action();
    } catch (\Throwable $e) {
        report($e);
    }
}
```

The same eager-construct + `safely()`-dispatch pattern wraps `MessageReceived` (in `handleMessage`) and `DeviceDisconnected` (in `handleDisconnected`/`handleLoggedOut`):

```php
private function handleDisconnected(WuzDevice $device): void
{
    $device->update(['connected' => false]);
    $event = new DeviceDisconnected($device);
    $this->safely(fn () => event($event));
}

private function handleLoggedOut(WuzDevice $device): void
{
    $device->update(['connected' => false, 'jid' => null]);
    $event = new DeviceDisconnected($device);
    $this->safely(fn () => event($event));
}
```

**Trade-off note:** the failure mode for an event-constructor bug (a package-side regression) is now: constructor throws → action throws → webhook route 5xx → WUZ retries → state mutation re-runs. This is intentional — package-side type errors are loud bugs that should be caught in CI before reaching production, not silently reported. Listener exceptions in consumer code are the common case and are correctly isolated.

`event_type` on the log row stores the raw payload type when present, so unknown-type rows carry the original string for forensics rather than collapsing to `'Unknown'`.

### `WuzEventType::detect()` hardening

The current implementation passes `$data['type']` straight to `tryFrom()`, which `TypeError`s on `null` or non-string. Updated:

```php
public static function detect(array $data): self
{
    $type = $data['type'] ?? null;

    if (! is_string($type)) {
        return self::UNKNOWN;
    }

    return self::tryFrom($type) ?? self::UNKNOWN;
}
```

Pre-existing latent bug; fixed as part of this work since the test suite gains coverage here anyway.

`handleMessage` no longer touches `WuzDeviceMessage` and no longer calls `downloadMedia`. It parses the payload and dispatches `MessageReceived`:

```php
private function handleMessage(WuzDevice $device, array $payload): void
{
    $info = $payload['Info'] ?? [];
    $message = $payload['Message'] ?? [];

    $chatJid = $info['RemoteJid'] ?? null;
    $senderJid = $info['Sender']['User'] ?? null;

    [$type, $content] = $this->parseMessage($message);

    if ($chatJid === null) {
        return;
    }

    $event = new MessageReceived($device, $type, $chatJid, $senderJid, $content, $payload);
    $this->safely(fn () => event($event));
}

private function parseMessage(array $message): array
{
    if (isset($message['conversation'])) {
        return ['text', $message['conversation']];
    }
    if (isset($message['extendedTextMessage'])) {
        return ['text', $message['extendedTextMessage']['text'] ?? null];
    }
    if (isset($message['imageMessage'])) {
        return ['image', $message['imageMessage']['caption'] ?? null];
    }
    if (isset($message['videoMessage'])) {
        return ['video', $message['videoMessage']['caption'] ?? null];
    }
    if (isset($message['documentMessage'])) {
        return ['document', $message['documentMessage']['fileName'] ?? $message['documentMessage']['title'] ?? null];
    }

    return ['text', null];
}
```

### Updated `MessageReceived` event signature

```php
class MessageReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WuzDevice $device,
        public readonly string $type,        // 'text' | 'image' | 'video' | 'document'
        public readonly string $chatJid,
        public readonly ?string $senderJid,
        public readonly ?string $content,    // text body or media caption/filename
        public readonly array $payload,      // raw payload for advanced use (e.g. media URL/key)
    ) {}
}
```

Consumers who want to download media call `WuzService::downloadImage()` / `downloadVideo()` / `downloadDocument()` from a `MessageReceived` listener, passing the keys from `$payload['Message'][...]`. The package no longer does this automatically. The `download_media` config and `HandleWebhookCallbackAction::downloadMedia()` are removed.

### `SendMessageAction` and the new `MessageSent` event

`SendMessageAction::handle()` no longer writes to `WuzDeviceMessage`. The action returns the WUZ API response array, or `null` in debug-skip mode.

**On the `DB::transaction(...)` wrapper:** the action *does* still trigger a DB write — `ValidatePhoneAction::resolveAndCache()` calls `WuzPhoneJid::updateOrCreate(...)` (`src/Actions/ValidatePhoneAction.php:56`). Removing the transaction means that cache write commits even if the subsequent WUZ send call throws `WuzApiException`. **This is intentional and correct:** a successful phone-JID resolution is independently valid — the mapping is stable regardless of whether *this* send succeeds, and caching it benefits future sends. The original transaction was overly defensive (it protected the now-removed `WuzDeviceMessage::create`). Removed; partial-commit semantics are documented and tested.

```php
public function handle(WuzDevice $device, SendMessageData $data): ?array
{
    if (config('wuz.debug.enabled')) {
        $debugTo = config('wuz.debug.to');
        if (empty($debugTo)) {
            Log::info('Wuz debug: message skipped', [
                'phone' => $data->phone, 'type' => $data->type, 'message' => $data->message,
            ]);
            return null;
        }
        $data = new SendMessageData(/* re-routed to debug phone, fields preserved */);
    }

    $wuz = $this->factory->make($device);
    $validated = $this->validatePhone->handle($wuz, $data->phone);

    // Throws WuzApiException on non-2xx — propagation is intentional so callers
    // can react to send failures. No MessageSent dispatch in that path.
    [$response, $messageContent] = $this->dispatchToWuz($wuz, $validated->phone, $data);

    // Send succeeded (HTTP 2xx). Event is constructed eagerly so a constructor
    // TypeError (package bug) propagates and surfaces in tests; only listener
    // execution is wrapped so a failing consumer listener cannot cause the
    // caller's job to retry and re-send the WhatsApp message.
    $event = new MessageSent($device, $data->type, $validated->phone, $messageContent, $response);
    $this->safely(fn () => event($event));

    return $response;
}

private function safely(\Closure $action): void
{
    try {
        $action();
    } catch (\Throwable $e) {
        report($e);
    }
}
```

`MessageSent` event:

```php
class MessageSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WuzDevice $device,
        public readonly string $type,
        public readonly string $phone,
        public readonly ?string $content,
        public readonly array $apiResponse,  // raw WUZ API response (HTTP 2xx body)
    ) {}
}
```

**Semantic:** `MessageSent` fires when WUZ accepted the request (HTTP 2xx response). It does **not** fire on:

- Debug-skip mode (`debug.enabled=true && debug.to is empty`) — no API call was made.
- API failures — `WuzService::request()` throws `WuzApiException` on non-2xx (`src/Services/WuzService.php:214-221`); the exception propagates, and the dispatch site is unreachable. Callers who care about failures handle the exception. (No separate `MessageSendFailed` event; consumers can wrap their action call in try/catch if they need that signal.)

`MessageSent` does not assert WhatsApp delivery confirmation — that arrives later as a separate `READ_RECEIPT` webhook.

`Notifications/WuzChannel::send()` discards the return value already (`WuzChannel.php:49-52`); no change needed.

### What stays always-on

- `match` arm side effects in the action: state mutations on `device` for `DISCONNECTED`/`LOGGED_OUT`, message parsing for `MESSAGE`.
- Typed events: `MessageReceived` for every `MESSAGE`, `DeviceDisconnected` for every `DISCONNECTED`/`LOGGED_OUT`, `MessageSent` after every successful send.

These are not gated by any allowlist. (`DeviceConnected` is dispatched from `GetDeviceStatusAction`, not from the webhook flow.)

### Removed surface

- `src/Models/WuzDeviceMessage.php`
- `database/migrations/create_wuz_device_messages_table.php.stub`
- `WuzDevice::messages()` `hasMany` relationship.
- `config('wuz.table_names.device_messages')` entry (table is gone).
- `download_media` config key + `WUZ_DOWNLOAD_MEDIA` env var.
- `HandleWebhookCallbackAction::downloadMedia()` method (~70 LOC).
- `Storage::disk('public')` / `wuz-media/` references in the action (file writes — consumers do their own).
- `DB::transaction(...)` wrapper in `SendMessageAction::handle()`.
- 7 dead `WuzEventType` cases (per Enum alignment).

`WuzService::downloadImage()` / `downloadVideo()` / `downloadDocument()` are **kept** — consumers call them directly from a `MessageReceived` listener if they want media.

## Performance

The gating logic is O(n) per webhook where n is the allowlist length (~13 in defaults). `config()` reads are O(1) array lookups against Laravel's repository cache. `tryFrom()` uses the enum's internal lookup table. Net: microseconds per webhook. The dominant saved cost is the avoided `WuzCallbackLog::create()` insert and `WebhookReceived::dispatch()` for filtered types.

## Migration

This is a **breaking change** on multiple axes:

1. **Defaults narrowed** — current consumers logging everything will start logging only ~12 types (and `MESSAGE` is no longer in defaults).
2. **`WuzEventType` cleanup** — 7 cases removed, 1 added, `ALL` moved to a new enum.
3. **`WuzDeviceMessage` removed** — model, table, relationship gone. `MessageReceived` event signature changed to carry parsed fields directly.
4. **`SendMessageAction::handle()` returns `?array`** instead of `?WuzDeviceMessage`. The `DB::transaction(...)` wrapper is gone — `WuzPhoneJid` cache writes commit independently of send outcome (see SendMessageAction section for rationale).
5. **`download_media` config + auto-media-download removed.**
6. **Dispatch order changed** — `WebhookReceived` now fires after state mutations and log inserts. **Listener exception isolation** — listener throws are caught and `report()`ed; they no longer propagate to the webhook route caller. Consumers who relied on listener exceptions to fail the webhook (and trigger WUZ retries) lose that behaviour. Errors are still visible via the configured exception handler (Sentry, log channel, etc.).

- Major version bump.
- New `UPGRADING.md` at repo root documenting:
  - All six axes above with before/after snippets.
  - Restore-previous-behaviour for log/dispatch volume:
    ```php
    'logging'       => ['event_types' => ['*']],
    'webhook_event' => ['event_types' => ['*']],
    ```
  - Removed enum cases table — for each removed case, the consumer action:
    - `RECEIPT` → use `READ_RECEIPT` (WUZ remaps internally).
    - The other 6 → no action; they never fired.
    - `ALL` references → switch to `WuzEventSubscription::ALL` (subscription contexts only).
  - **`WuzDeviceMessage` removal guidance**:
    - The `wuz_device_messages` table is no longer maintained. The package no longer ships its migration.
    - Existing tables in consumer DBs are left untouched. To clean up, delete the published migration file at `database/migrations/*_create_wuz_device_messages_table.php` and drop the table when ready.
    - Code referencing `WuzDeviceMessage`, `$device->messages()`, or the old `MessageReceived $message` property must be migrated to the new event signature.
    - Sample listener for re-implementing storage:
      ```php
      class StoreIncomingMessage
      {
          public function handle(MessageReceived $event): void
          {
              YourMessage::create([
                  'device_id' => $event->device->id,
                  'chat_jid'  => $event->chatJid,
                  'sender'    => $event->senderJid,
                  'content'   => $event->content,
                  'type'      => $event->type,
                  'payload'   => $event->payload,
              ]);
          }
      }
      ```
  - **`SendMessageAction` return type change** — sample migration of caller code from `$message = $action->handle(...)` to `$response = $action->handle(...)`.
  - **Media download migration** — sample listener that calls `WuzService` after a `MessageReceived` event:
    ```php
    public function handle(MessageReceived $event): void
    {
        if ($event->type !== 'image') return;
        $img = $event->payload['Message']['imageMessage'] ?? null;
        if (! $img) return;
        $wuz = app(WuzServiceFactory::class)->make($event->device);
        $result = $wuz->downloadImage(/* ... keys from $img ... */);
        // store $result however you like
    }
    ```
  - Note about WUZ-side `media_delivery=base64` inflating logged `MESSAGE` payloads if the consumer opts `MESSAGE` back into `logging.event_types`. Recommendation: switch upstream to `media_delivery=s3`.
- `README.md` gains a "Configuration — selective logging and event-driven message pipeline" subsection.
- No data migration. Existing `wuz_callback_logs` rows are untouched. Existing `wuz_device_messages` rows are untouched (orphaned but readable).

## Testing

### New test files

**`tests/Unit/WuzEventTypeGatingTest.php`** — predicate methods in isolation:

- `shouldLog($raw)` returns true/false correctly across config shapes (enum cases, string values, raw-string fallback for unknown payload types, `'*'`, empty, missing key).
- `shouldDispatch($raw)` symmetric.
- `defaultLoggingTypes()` and `defaultDispatchTypes()` return the documented sets — regression-guards against accidental edits.
- `defaultLoggingTypes()` includes `QR_TIMEOUT` and excludes `MESSAGE` (regression-guards on the volume-fix decisions).
- `tryFrom('Receipt')` returns `null` (regression guard for dead-case removal).
- Allowlist with raw-string entry `'FooEvent'` matches when payload `type='FooEvent'` even though no enum case exists.
- `detect(['type' => null])`, `detect([])`, `detect(['type' => 42])`, `detect(['type' => ['array']])` all return `WuzEventType::UNKNOWN` without throwing (regression guard for the `detect()` hardening).

**`tests/Unit/WuzEventSubscriptionTest.php`** — enum value coverage and a regression guard that `WuzEventSubscription::tryFrom('All')` returns the `ALL` case.

**`tests/Feature/CallbackLogGatingTest.php`** — storage allowlist:

- Type in `logging.event_types` → row created.
- Type not in list → no row.
- `'*'` wildcard → row for any type.
- Empty array → no row for any type.
- String values accepted alongside enum cases.
- Default config (key absent) → falls back to `defaultLoggingTypes()`.
- `UNKNOWN` type → logged under defaults; the raw payload type is preserved in `event_type` column.
- `MESSAGE` payload → **not** logged under defaults (regression guard for codex MED-5(i)).
- **Raw-string allowlist (action-level):** with `logging.event_types = ['FooEvent']` and a payload whose `type='FooEvent'` (no enum case exists), the action still inserts a log row whose `event_type` is `'FooEvent'`. Proves the action passes `$rawType` into `shouldLog()`.
- **Malformed `type` field:** payload with no `type` key, `type=null`, `type=42` → no `TypeError`; row inserts under defaults (since `UNKNOWN` is in defaults) with `event_type = 'Unknown'`.

**`tests/Feature/WebhookEventGatingTest.php`** — dispatch allowlist:

- Type in `webhook_event.event_types` → `WebhookReceived` dispatched.
- Type not in list → not dispatched.
- Storage and dispatch allowlists are independent (set differently in one test; assert both behaviours separately).
- **Raw-string allowlist (action-level):** with `webhook_event.event_types = ['FooEvent']` and payload `type='FooEvent'`, `WebhookReceived` is dispatched. Symmetric to the logging case.

**`tests/Feature/MessageReceivedEventTest.php`** — the new inbound event:

- A `MESSAGE` text payload dispatches `MessageReceived` with the correct `type`, `chatJid`, `senderJid`, `content`, and full `payload`.
- Image, video, document payloads dispatch with appropriate `type` and `content` (caption / filename).
- A `MESSAGE` payload with no `RemoteJid` is a noop (no dispatch).
- No `WuzDeviceMessage::class` row is written (regression guard — the model is gone).

**`tests/Feature/MessageSentEventTest.php`** — outbound event:

- Successful text send (HTTP 2xx mocked) dispatches `MessageSent` carrying the API response.
- Image / video / document sends dispatch with correct `type` and content.
- Debug-skip mode (no `debug.to`) does **not** dispatch `MessageSent`.
- Debug-redirect mode dispatches `MessageSent` with the redirected `phone`.
- **API failure does not dispatch `MessageSent`:** mock `Http::fake()` to return non-2xx; assert `WuzApiException` thrown and `Event::assertNotDispatched(MessageSent::class)`.
- **Phone JID cache persists when send fails:** mock phone resolution success + send failure; assert `WuzPhoneJid` row exists for the phone after the exception (regression guard for the partial-commit decision).
- **Listener exception does not propagate:** install a listener for `MessageSent` that throws; the action still returns the API response normally; the throwable is reported (assert via `Log::shouldReceive('error')` or by spying on `report()` if the test harness allows).

**`tests/Feature/DispatchOrderTest.php`** — order, listener isolation, and best-effort logging:

- A `WebhookReceived` listener that throws: the webhook action returns normally (does not propagate); `device.connected` is still flipped on `Disconnected`; `DeviceDisconnected` typed event still fired.
- A `MessageReceived` listener that throws: the webhook action returns normally; the `WuzCallbackLog` row was inserted (if applicable to the type).
- A `DeviceDisconnected` listener that throws: the action returns normally; `device.connected` already flipped.
- **Log insert failure does not block side effects:** simulate a DB failure on `WuzCallbackLog::create()` (e.g. drop the table mid-test or bind a model that throws). The action returns normally, state mutations have committed, typed events fired, generic `WebhookReceived` dispatched. The throwable is reported.
- **Event-constructor errors DO propagate (regression guard for the trade-off note):** if a hypothetical refactor breaks `MessageReceived`'s constructor signature, the action throws — confirming package-side type errors are not silently swallowed. Skip if too brittle to express.
- The reported throwables are observable via `Exceptions::fake()` (Laravel 11+) or by substituting the `ExceptionHandler` binding.

### Existing test changes

**`tests/Feature/WebhookCallbackTest.php`:**

- Line 19 — `Receipt`-payload test is doubly wrong: `Receipt` is not in default allowlists *and* WUZ never emits it. Switch payload to `Connected`. Replace `WuzCallbackLog::count()` and `WuzCallbackLog::first()->event_type` assertions accordingly.
- Lines covering `WuzDeviceMessage::count()` and `MessageReceived` carrying a `WuzDeviceMessage` model — rewrite against the new event signature (parsed fields, no model).

**`tests/Unit/WuzEventTypeTest.php`:**

- Drop the `['Receipt', WuzEventType::RECEIPT]` row.
- Drop the `['All', WuzEventType::ALL]` row.
- Add `['QRTimeout', WuzEventType::QR_TIMEOUT]`.

**`tests/Feature/SendMessageActionTest.php`:**

- Update assertions: instead of `expect($result)->toBeInstanceOf(WuzDeviceMessage::class)`, assert `expect($result)->toBeArray()->toHaveKey('code')` against the mocked WUZ response.
- Drop `WuzDeviceMessage::count()` assertions.
- Add `Event::assertDispatched(MessageSent::class, ...)` for the new event.

**`tests/Feature/WuzChannelTest.php`** and **`tests/Unit/WuzMessageTest.php`:**

- Verify channel still works end-to-end through `SendMessageAction`'s new return type. The channel discards the return, so most assertions remain.

## Files Touched

- `config/wuz.php` — add `logging` and `webhook_event` keys; remove `download_media`; remove `table_names.device_messages` entry.
- `src/Enums/WuzEventType.php` — remove 7 dead cases + their `label()` arms; add `QR_TIMEOUT` + label; remove `ALL`; add `shouldLog()`, `shouldDispatch()`, `isAllowedBy()`, `defaultLoggingTypes()`, `defaultDispatchTypes()`; harden `detect()` to handle non-string `type` without `TypeError`.
- `src/Enums/WuzEventSubscription.php` — **new**.
- `src/Actions/HandleWebhookCallbackAction.php` — reorder (state → log → event); add private `safely(\Closure)` helper wrapping log insert and every event dispatch in try/catch + `report()`; events are constructed eagerly before the safe boundary so package-side constructor errors propagate; remove `downloadMedia()` private method; remove media download imports/calls; rewrite `handleMessage()` to parse payload and dispatch `MessageReceived` without persisting; remove `WuzDeviceMessage` import.
- `src/Actions/SendMessageAction.php` — change return type to `?array`; remove `DB::transaction` wrapper (rationale: `WuzPhoneJid` cache write is intentionally independent of send outcome); remove `WuzDeviceMessage::create` write; add private `safely(\Closure)` helper; eagerly construct `MessageSent` then safely dispatch after successful API call (HTTP 2xx); keep debug-skip behaviour.
- `src/Events/MessageReceived.php` — change constructor signature to flat parsed fields + raw payload; drop `WuzDeviceMessage` import.
- `src/Events/MessageSent.php` — **new**.
- `src/Models/WuzDeviceMessage.php` — **delete**.
- `src/Models/WuzDevice.php` — remove `messages()` `hasMany` relationship; remove `WuzDeviceMessage` import.
- `src/Services/WuzService.php` — replace hardcoded `'All'` at line 41 (`addUser`) and line 184 (`setWebhookEvents`) with `WuzEventSubscription::ALL->value`.
- `database/migrations/create_wuz_device_messages_table.php.stub` — **delete**.
- `src/WuzServiceProvider.php` — remove the `device_messages` migration registration (currently lines around the loadMigrationsFrom block).
- `tests/TestCase.php` — remove the `create_wuz_device_messages_table.php.stub` include at lines 45-46 (stub is deleted).
- `tests/Feature/WebhookCallbackTest.php` — update Receipt → Connected; rewrite message-related assertions.
- `tests/Unit/WuzEventTypeTest.php` — drop Receipt + All rows; add QRTimeout.
- `tests/Feature/SendMessageActionTest.php` — update return-type and event assertions.
- `tests/Unit/WuzEventTypeGatingTest.php` — **new**.
- `tests/Unit/WuzEventSubscriptionTest.php` — **new**.
- `tests/Feature/CallbackLogGatingTest.php` — **new**.
- `tests/Feature/WebhookEventGatingTest.php` — **new**.
- `tests/Feature/MessageReceivedEventTest.php` — **new**.
- `tests/Feature/MessageSentEventTest.php` — **new**.
- `tests/Feature/DispatchOrderTest.php` — **new**.
- `UPGRADING.md` — **new**.
- `README.md` — new "Configuration" subsection.
