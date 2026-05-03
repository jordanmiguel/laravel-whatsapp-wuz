# Upgrading from v1.x to v2.x

v2 is a breaking-change release that addresses a production-volume issue: every webhook callback was being logged in full and dispatched as a generic event, regardless of type. With ~40 distinct WUZ event types — many of them very high-volume — this generated unmanageable amounts of `wuz_callback_logs` data.

This guide walks through every breaking change.

## TL;DR

- **Logging is now allowlisted.** Only ~12 event types are logged by default. `MESSAGE` is **not** in defaults — see "Selective logging" below.
- **`WebhookReceived` dispatch is now allowlisted.** By default it fires for `MESSAGE`, `CONNECTED`, `DISCONNECTED`, and `LOGGED_OUT`.
- **`WuzDeviceMessage` model + table are gone.** The package is now event-driven for messages: subscribe to `MessageReceived` (inbound) and `MessageSent` (outbound) to persist however you like.
- **Auto media-download is gone.** Call `WuzService::downloadImage/downloadVideo/downloadDocument` from a `MessageReceived` listener if you need media.
- **`SendMessageAction::handle()` returns `?array`** (the WUZ API response) instead of `?WuzDeviceMessage`.
- **Listener exceptions no longer fail the webhook route.** Reported via the standard exception handler instead.

## Selective logging and dispatch

Two new config keys in `config/wuz.php`:

```php
'logging' => [
    'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultLoggingTypes(),
],

'webhook_event' => [
    'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultDispatchTypes(),
],
```

Both accept arrays of `WuzEventType` cases or string values. The wildcard `'*'` means "all types"; `[]` means none.

### Restoring v1 firehose behaviour

If you depended on logging every event and dispatching `WebhookReceived` for every event:

```php
'logging'       => ['event_types' => ['*']],
'webhook_event' => ['event_types' => ['*']],
```

### Defaults

`WuzEventType::defaultLoggingTypes()` covers lifecycle (`CONNECTED`, `DISCONNECTED`, `LOGGED_OUT`), pairing (`PAIR_SUCCESS`, `PAIR_ERROR`, `QR`, `QR_TIMEOUT`), error states (`CONNECT_FAILURE`, `STREAM_ERROR`, `TEMPORARY_BAN`, `CLIENT_OUTDATED`), and `UNKNOWN`. **`MESSAGE` is intentionally excluded** — with WUZ-side `media_delivery=base64` (the default), logging `MESSAGE` payloads inflates the `payload` JSON column to the size of every media file. Opt in only if you accept that trade-off.

`WuzEventType::defaultDispatchTypes()` is `[MESSAGE, CONNECTED, DISCONNECTED, LOGGED_OUT]`.

## `WuzEventType` enum cleanup

Seven cases were removed because WUZ never emits them (cross-referenced against `wmiau.go`):

| Removed | What to do |
|---|---|
| `RECEIPT` | Use `READ_RECEIPT`. WUZ remaps `*events.Receipt` to `ReadReceipt` internally. |
| `STREAM_REPLACED` | No action — never fired in v1 either. |
| `APP_STATE` | Same. |
| `APP_STATE_SYNC_COMPLETE` | Same. |
| `PUSH_NAME_SETTING` | WUZ bundles this into `Connected`. Listen to `CONNECTED`. |
| `QR_SCANNED_WITHOUT_MULTIDEVICE` | Same — never fired. |
| `CAT_REFRESH_ERROR` | Same. |

One case was added: **`QR_TIMEOUT`** — emitted when the QR pairing window expires. Included in default logging.

The `ALL` case moved to a new enum `WuzEventSubscription` (since it's a subscription-side sentinel, not an emitted event). If your code references `WuzEventType::ALL`, switch to `\JordanMiguel\Wuz\Enums\WuzEventSubscription::ALL`.

## `WuzDeviceMessage` removal

The `wuz_device_messages` table is no longer maintained. The package no longer ships its migration.

**Existing tables are left untouched.** Your data is safe. To clean up:

1. Delete the published migration in your app at `database/migrations/*_create_wuz_device_messages_table.php`.
2. Drop the `wuz_device_messages` table when ready (optional — empty unused tables are harmless).

### Re-implementing message storage in your own schema

Subscribe to `MessageReceived` and persist however you like:

```php
class StoreIncomingMessage
{
    public function handle(\JordanMiguel\Wuz\Events\MessageReceived $event): void
    {
        \App\Models\Message::create([
            'device_id' => $event->device->id,
            'chat_jid'  => $event->chatJid,
            'sender'    => $event->senderJid,
            'content'   => $event->content,
            'type'      => $event->type,  // 'text' | 'image' | 'video' | 'document'
            'payload'   => $event->payload,  // raw WUZ webhook for advanced cases
        ]);
    }
}
```

For outbound, subscribe to `MessageSent` symmetrically.

## `MessageReceived` constructor signature change

v1: a single `WuzDeviceMessage` field — `public WuzDeviceMessage $message`.

v2: flat parsed fields plus the raw payload:

```php
public readonly WuzDevice $device;
public readonly string $type;       // 'text' | 'image' | 'video' | 'document'
public readonly string $chatJid;
public readonly ?string $senderJid;
public readonly ?string $content;
public readonly array $payload;     // raw WUZ webhook payload
```

Existing listeners reading `$event->message->...` will TypeError at runtime — rewrite them to read the flat fields directly. See the `StoreIncomingMessage` example in the "WuzDeviceMessage removal" section above.

## `SendMessageAction` return type change

```diff
- $message = app(SendMessageAction::class)->handle($device, $data);
- $messageId = $message?->id;
+ $response = app(SendMessageAction::class)->handle($device, $data);
+ $waMessageId = $response['data']['id'] ?? null;
```

`null` is still returned in debug-skip mode (when `wuz.debug.enabled=true` and `wuz.debug.to` is empty). Failed sends throw `WuzApiException` as before.

## Media download migration

If you depended on `download_media=true` and the package writing files to `Storage::disk('public')`:

```php
class DownloadIncomingMedia
{
    public function handle(\JordanMiguel\Wuz\Events\MessageReceived $event): void
    {
        if ($event->type !== 'image') {
            return;
        }

        $img = $event->payload['Message']['imageMessage'] ?? null;
        if (! $img) {
            return;
        }

        $wuz = app(\JordanMiguel\Wuz\Services\WuzServiceFactory::class)->make($event->device);

        $result = $wuz->downloadImage(
            $img['url'] ?? '',
            $img['directPath'] ?? '',
            // ... encode media keys, see WuzService for full signature
        );

        // Store the result wherever you like.
    }
}
```

The `WuzService::downloadImage/downloadVideo/downloadDocument` methods are unchanged. Files are no longer auto-written to `storage/app/public/wuz-media/`.

## Listener exception isolation

A consumer listener that throws on `WebhookReceived`, `MessageReceived`, `DeviceDisconnected`, or `MessageSent` is now reported via Laravel's exception handler (Sentry, log, etc.) rather than propagating to the action's caller.

**Why:** if a listener exception fails the webhook route, WUZ retries the webhook — re-running side effects (state mutations, message dispatches) that already committed. That duplication is worse than a missed observer. Errors are still visible via the configured exception reporter.

If you depended on a listener exception to fail the webhook, restructure: handle errors inside the listener and use your own retry mechanism (queue + retries) for any work that can fail.

## WUZ-side recommendation

If you opt `MESSAGE` back into `logging.event_types`, consider switching the WUZ-side `media_delivery` from `base64` (default) to `s3`. With `base64`, every media `MESSAGE` payload carries the file inline and inflates `wuz_callback_logs.payload` to the file size.
