# Selective Event Logging & Event-Driven Message Pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the design at `docs/superpowers/specs/2026-05-02-selective-event-logging-design.md` — per-event-type allowlists for callback log storage and `WebhookReceived` dispatch, drop the `WuzDeviceMessage` model + table to make the package event-driven for messages, align `WuzEventType` with what WUZ actually emits, isolate listener exceptions, and ship narrow defaults that fix the production volume problem on upgrade.

**Architecture:** Add gating predicates on the `WuzEventType` enum that read two new config keys; reorder the inbound action so state mutations run before observability dispatches; wrap log inserts and event dispatches in a `safely()` helper that reports throwables instead of propagating; replace `WuzDeviceMessage` persistence with parsed-field `MessageReceived` / `MessageSent` events. Move the `ALL` subscription sentinel out of `WuzEventType` into a new `WuzEventSubscription` enum. Drop media auto-download — consumers call `WuzService::download*` from listeners.

**Tech Stack:** PHP 8.2+, Laravel 11/12, Pest 3, spatie/laravel-package-tools, spatie/laravel-data, Orchestra Testbench, sqlite (in-memory) for tests.

---

## File Structure

**New files:**
- `src/Enums/WuzEventSubscription.php` — subscription-side strings WUZ accepts in its `events` column (sentinel + per-event opt-ins).
- `src/Events/MessageSent.php` — outbound event dispatched after a successful (HTTP 2xx) WUZ send.
- `tests/Unit/WuzEventTypeGatingTest.php` — predicate methods + defaults regression guards.
- `tests/Unit/WuzEventSubscriptionTest.php` — new enum coverage.
- `tests/Feature/CallbackLogGatingTest.php` — storage allowlist behaviour at the action level.
- `tests/Feature/WebhookEventGatingTest.php` — dispatch allowlist behaviour at the action level.
- `tests/Feature/MessageReceivedEventTest.php` — inbound event signature + parsing coverage.
- `tests/Feature/MessageSentEventTest.php` — outbound event coverage.
- `tests/Feature/DispatchOrderTest.php` — listener-isolation, log-failure isolation, ordering.
- `UPGRADING.md` — repo-root migration guide for v2 consumers.

**Modified files:**
- `src/Enums/WuzEventType.php` — remove 7 dead cases + label arms; remove `ALL`; add `QR_TIMEOUT`; harden `detect()`; add `shouldLog()`, `shouldDispatch()`, `isAllowedBy()`, `defaultLoggingTypes()`, `defaultDispatchTypes()`.
- `src/Actions/HandleWebhookCallbackAction.php` — add `safely(\Closure)` helper; reorder (state → log → dispatch); wrap log + every event dispatch with eager construction; wire gating; rewrite `handleMessage()` to drop persistence and media download; remove `downloadMedia()` private method; remove `WuzDeviceMessage` import.
- `src/Actions/SendMessageAction.php` — return `?array`; remove `DB::transaction` wrapper; remove `WuzDeviceMessage::create`; add `safely(\Closure)` helper; eagerly construct `MessageSent` then dispatch safely; keep debug-skip behaviour.
- `src/Events/MessageReceived.php` — change constructor to flat parsed fields (`type`, `chatJid`, `senderJid`, `content`) plus raw `payload`; drop `WuzDeviceMessage` import.
- `src/Models/WuzDevice.php` — remove `messages()` relationship + `WuzDeviceMessage` import.
- `src/Services/WuzService.php` — replace hardcoded `'All'` with `WuzEventSubscription::ALL->value` at the two callsites.
- `src/WuzServiceProvider.php` — drop `'create_wuz_device_messages_table'` from the `hasMigrations(...)` array.
- `config/wuz.php` — add `logging.event_types` + `webhook_event.event_types`; remove `download_media`; remove `table_names.device_messages`.
- `tests/TestCase.php` — drop the `create_wuz_device_messages_table.php.stub` include.
- `tests/Feature/WebhookCallbackTest.php` — switch the `Receipt` payload at line 19 to `Connected`; rewrite assertions that depend on `WuzDeviceMessage` and the old `MessageReceived` shape.
- `tests/Unit/WuzEventTypeTest.php` — drop the `Receipt` and `All` data rows; add `QRTimeout`.
- `tests/Feature/SendMessageActionTest.php` — assert `?array` return shape; drop `WuzDeviceMessage::count()` assertions; add `MessageSent` event assertions.
- `README.md` — new "Configuration — selective logging and event-driven message pipeline" subsection.

**Deleted files:**
- `src/Models/WuzDeviceMessage.php`
- `database/migrations/create_wuz_device_messages_table.php.stub`

**Per-task ordering rationale:** each task leaves the test suite green. Foundational additions come first (enum hardening, new subscription enum, predicates). Behaviour-changing refactors come next, paired tightly with their test updates. Surface deletions land at the end once nothing references them.

---

### Task 1: Harden `WuzEventType::detect()` for non-string `type`

**Files:**
- Modify: `src/Enums/WuzEventType.php` — `detect()` method
- Modify: `tests/Unit/WuzEventTypeTest.php` — add coverage

**Why:** `detect()` currently passes `$data['type']` straight to `tryFrom()`. PHP's null-coercion in non-strict mode hides this for `null`/missing keys, but arrays/objects throw `TypeError`. Spec calls for an explicit guard.

- [ ] **Step 1: Write failing tests for non-string handling**

Append to `tests/Unit/WuzEventTypeTest.php`:

```php
it('falls back to UNKNOWN when type is an array', function () {
    expect(WuzEventType::detect(['type' => ['nested']]))->toBe(WuzEventType::UNKNOWN);
});

it('falls back to UNKNOWN when type is an integer', function () {
    expect(WuzEventType::detect(['type' => 42]))->toBe(WuzEventType::UNKNOWN);
});

it('falls back to UNKNOWN when type is an object', function () {
    expect(WuzEventType::detect(['type' => new \stdClass()]))->toBe(WuzEventType::UNKNOWN);
});

it('falls back to UNKNOWN when type is explicitly null', function () {
    expect(WuzEventType::detect(['type' => null]))->toBe(WuzEventType::UNKNOWN);
});
```

- [ ] **Step 2: Run tests to verify the array/object cases fail**

Run: `vendor/bin/pest tests/Unit/WuzEventTypeTest.php -v`
Expected: array and object cases FAIL with `TypeError`. Integer and null cases may pass (PHP coercion).

- [ ] **Step 3: Harden `detect()` in `src/Enums/WuzEventType.php`**

Replace:

```php
public static function detect(array $data): self
{
    $type = $data['type'] ?? null;

    return self::tryFrom($type) ?? self::UNKNOWN;
}
```

With:

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

- [ ] **Step 4: Run tests to verify all cases pass**

Run: `vendor/bin/pest tests/Unit/WuzEventTypeTest.php -v`
Expected: all 15 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Enums/WuzEventType.php tests/Unit/WuzEventTypeTest.php
git commit -m "fix: harden WuzEventType::detect against non-string type"
```

---

### Task 2: Create `WuzEventSubscription` enum

**Files:**
- Create: `src/Enums/WuzEventSubscription.php`
- Create: `tests/Unit/WuzEventSubscriptionTest.php`

**Why:** `'All'` is a subscription-side sentinel WUZ recognises in user records; mixing it into `WuzEventType` (emitted-events enum) is misleading. Splitting now is cheap because we're already shipping a major.

- [ ] **Step 1: Write failing test for the new enum**

Create `tests/Unit/WuzEventSubscriptionTest.php`:

```php
<?php

use JordanMiguel\Wuz\Enums\WuzEventSubscription;

it('exposes All as a sentinel case', function () {
    expect(WuzEventSubscription::ALL->value)->toBe('All');
});

it('exposes the subscription strings WUZ recognises', function (string $value, WuzEventSubscription $expected) {
    expect(WuzEventSubscription::tryFrom($value))->toBe($expected);
})->with([
    ['All', WuzEventSubscription::ALL],
    ['Message', WuzEventSubscription::MESSAGE],
    ['ReadReceipt', WuzEventSubscription::READ_RECEIPT],
    ['Presence', WuzEventSubscription::PRESENCE],
    ['HistorySync', WuzEventSubscription::HISTORY_SYNC],
    ['ChatPresence', WuzEventSubscription::CHAT_PRESENCE],
]);

it('returns null for unrecognised subscription values', function () {
    expect(WuzEventSubscription::tryFrom('Nope'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/WuzEventSubscriptionTest.php -v`
Expected: FAIL — `Class "JordanMiguel\Wuz\Enums\WuzEventSubscription" not found`.

- [ ] **Step 3: Create the enum**

Create `src/Enums/WuzEventSubscription.php`:

```php
<?php

namespace JordanMiguel\Wuz\Enums;

/**
 * Strings WUZ accepts in its per-user `events` subscription column.
 *
 * Distinct from {@see WuzEventType}, which represents the types WUZ emits in
 * webhook payloads. `All` is a subscription sentinel meaning "all events";
 * it is never emitted.
 */
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

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/WuzEventSubscriptionTest.php -v`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Enums/WuzEventSubscription.php tests/Unit/WuzEventSubscriptionTest.php
git commit -m "feat: add WuzEventSubscription enum for subscription-side strings"
```

---

### Task 3: Update `WuzService` callsites to use `WuzEventSubscription::ALL->value`

**Files:**
- Modify: `src/Services/WuzService.php` — lines 41 and 184

**Why:** Replace the two hardcoded `'All'` strings with the new enum reference. Functionally equivalent (same string value), but tightens the dependency graph and surfaces the subscription/emitted distinction.

- [ ] **Step 1: Confirm the existing test suite is green before changes**

Run: `vendor/bin/pest`
Expected: all tests PASS.

- [ ] **Step 2: Add the import to `src/Services/WuzService.php`**

After the existing `use` statements at the top of the file, add:

```php
use JordanMiguel\Wuz\Enums\WuzEventSubscription;
```

- [ ] **Step 3: Replace `'events' => 'All'` in `addUser()` (around line 41)**

Find:

```php
'events' => 'All',
```

Replace with:

```php
'events' => WuzEventSubscription::ALL->value,
```

- [ ] **Step 4: Replace `'events' => ['All']` in `setWebhookEvents()` (around line 184)**

Find:

```php
'events' => ['All'],
```

Replace with:

```php
'events' => [WuzEventSubscription::ALL->value],
```

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/pest`
Expected: all tests PASS — string value is unchanged, behaviour is identical.

- [ ] **Step 6: Commit**

```bash
git add src/Services/WuzService.php
git commit -m "refactor: use WuzEventSubscription::ALL in WuzService callsites"
```

---

### Task 4: Clean up `WuzEventType` — remove dead cases, add `QR_TIMEOUT`, remove `ALL`

**Files:**
- Modify: `src/Enums/WuzEventType.php`
- Modify: `tests/Unit/WuzEventTypeTest.php`
- Modify: `tests/Feature/WebhookCallbackTest.php` — line 19 payload swap

**Why:** 7 cases never fire (WUZ remaps or swallows them upstream); 1 emitted type is missing. `ALL` is moved out per Task 2. Update the dependent tests in lockstep so the suite stays green.

- [ ] **Step 1: Update `tests/Unit/WuzEventTypeTest.php` — drop dead-case rows, add `QR_TIMEOUT`**

Replace the data array on the `it('detects known event types from payload', ...)` test:

```php
])->with([
    ['Message', WuzEventType::MESSAGE],
    ['Disconnected', WuzEventType::DISCONNECTED],
    ['LoggedOut', WuzEventType::LOGGED_OUT],
    ['Connected', WuzEventType::CONNECTED],
    ['QR', WuzEventType::QR],
    ['Receipt', WuzEventType::RECEIPT],
    ['All', WuzEventType::ALL],
]);
```

With:

```php
])->with([
    ['Message', WuzEventType::MESSAGE],
    ['Disconnected', WuzEventType::DISCONNECTED],
    ['LoggedOut', WuzEventType::LOGGED_OUT],
    ['Connected', WuzEventType::CONNECTED],
    ['QR', WuzEventType::QR],
    ['QRTimeout', WuzEventType::QR_TIMEOUT],
    ['ReadReceipt', WuzEventType::READ_RECEIPT],
]);
```

Also append a regression-guard test at the end of the file:

```php
it('returns null for tryFrom of removed dead cases', function (string $value) {
    expect(WuzEventType::tryFrom($value))->toBeNull();
})->with([
    ['Receipt'],
    ['StreamReplaced'],
    ['AppState'],
    ['AppStateSyncComplete'],
    ['PushNameSetting'],
    ['QRScannedWithoutMultidevice'],
    ['CATRefreshError'],
    ['All'],
]);
```

- [ ] **Step 2: Update `tests/Feature/WebhookCallbackTest.php` — switch payload at line 19**

Find the test `it('logs callbacks and dispatches WebhookReceived event', ...)` (around line 19). Replace its payload from `'Receipt'` to `'Connected'` so the test exercises a real, default-allowed event type:

Find:

```php
$action->handle('callback-token-123', ['type' => 'Receipt'], '127.0.0.1', 'TestAgent');

expect(WuzCallbackLog::count())->toBe(1);
expect(WuzCallbackLog::first()->event_type)->toBe('Receipt');
```

Replace with:

```php
$action->handle('callback-token-123', ['type' => 'Connected'], '127.0.0.1', 'TestAgent');

expect(WuzCallbackLog::count())->toBe(1);
expect(WuzCallbackLog::first()->event_type)->toBe('Connected');
```

- [ ] **Step 3: Run tests to verify they fail correctly against the un-edited enum**

Run: `vendor/bin/pest tests/Unit/WuzEventTypeTest.php tests/Feature/WebhookCallbackTest.php -v`
Expected: data-row tests for `QRTimeout`/`ReadReceipt` may pass already (those enum cases exist); the regression guard FAILS because the dead cases still exist; the WebhookCallbackTest passes because the action still logs every type.

- [ ] **Step 4: Edit `src/Enums/WuzEventType.php` — remove the 7 dead cases and the `ALL` case**

Delete these case lines:

```php
case RECEIPT = 'Receipt';
case STREAM_REPLACED = 'StreamReplaced';
case QR_SCANNED_WITHOUT_MULTIDEVICE = 'QRScannedWithoutMultidevice';
case PUSH_NAME_SETTING = 'PushNameSetting';
case APP_STATE = 'AppState';
case APP_STATE_SYNC_COMPLETE = 'AppStateSyncComplete';
case CAT_REFRESH_ERROR = 'CATRefreshError';
case ALL = 'All';
```

- [ ] **Step 5: Add `QR_TIMEOUT` near `QR`**

In the case list, after the `QR` case, add:

```php
case QR_TIMEOUT = 'QRTimeout';
```

- [ ] **Step 6: Update the `label()` match to drop dead arms and add the `QR_TIMEOUT` label**

In the `label()` method, remove these `match` arms:

```php
self::RECEIPT => 'Receipt',
self::STREAM_REPLACED => 'Stream Replaced',
self::QR_SCANNED_WITHOUT_MULTIDEVICE => 'QR Scanned (No Multi-Device)',
self::PUSH_NAME_SETTING => 'Push Name Setting',
self::APP_STATE => 'App State',
self::APP_STATE_SYNC_COMPLETE => 'App State Sync Complete',
self::CAT_REFRESH_ERROR => 'CAT Refresh Error',
self::ALL => 'All Events',
```

After the `self::QR => 'QR Code',` arm, add:

```php
self::QR_TIMEOUT => 'QR Timeout',
```

- [ ] **Step 7: Run the full suite to verify enum cleanup**

Run: `vendor/bin/pest`
Expected: all tests PASS — including the new regression-guard tests for the removed cases.

- [ ] **Step 8: Commit**

```bash
git add src/Enums/WuzEventType.php tests/Unit/WuzEventTypeTest.php tests/Feature/WebhookCallbackTest.php
git commit -m "refactor: align WuzEventType with WUZ-emitted types

Remove 7 dead cases that WUZ never emits, add QR_TIMEOUT (emitted at
wmiau.go:541), and remove ALL (now in WuzEventSubscription)."
```

---

### Task 5: Add `WuzEventType` predicate methods + defaults

**Files:**
- Modify: `src/Enums/WuzEventType.php` — add static methods and predicates
- Create: `tests/Unit/WuzEventTypeGatingTest.php`

**Why:** These are the gating primitives the action will call in Task 7. Pure addition — no existing code uses them yet.

- [ ] **Step 1: Write the failing test file**

Create `tests/Unit/WuzEventTypeGatingTest.php`:

```php
<?php

use JordanMiguel\Wuz\Enums\WuzEventType;

afterEach(function () {
    config()->set('wuz.logging.event_types', null);
    config()->set('wuz.webhook_event.event_types', null);
});

it('defaultLoggingTypes excludes MESSAGE', function () {
    expect(WuzEventType::defaultLoggingTypes())
        ->not->toContain(WuzEventType::MESSAGE);
});

it('defaultLoggingTypes includes QR_TIMEOUT', function () {
    expect(WuzEventType::defaultLoggingTypes())
        ->toContain(WuzEventType::QR_TIMEOUT);
});

it('defaultLoggingTypes contains lifecycle, pairing, error, and UNKNOWN types', function () {
    $defaults = WuzEventType::defaultLoggingTypes();
    expect($defaults)->toContain(
        WuzEventType::CONNECTED,
        WuzEventType::DISCONNECTED,
        WuzEventType::LOGGED_OUT,
        WuzEventType::PAIR_SUCCESS,
        WuzEventType::PAIR_ERROR,
        WuzEventType::QR,
        WuzEventType::QR_TIMEOUT,
        WuzEventType::CONNECT_FAILURE,
        WuzEventType::STREAM_ERROR,
        WuzEventType::TEMPORARY_BAN,
        WuzEventType::CLIENT_OUTDATED,
        WuzEventType::UNKNOWN,
    );
});

it('defaultDispatchTypes is the four lifecycle + MESSAGE types', function () {
    expect(WuzEventType::defaultDispatchTypes())->toEqualCanonicalizing([
        WuzEventType::MESSAGE,
        WuzEventType::CONNECTED,
        WuzEventType::DISCONNECTED,
        WuzEventType::LOGGED_OUT,
    ]);
});

it('shouldLog returns true when the case is in the configured allowlist', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::MESSAGE]);
    expect(WuzEventType::MESSAGE->shouldLog())->toBeTrue();
    expect(WuzEventType::CONNECTED->shouldLog())->toBeFalse();
});

it('shouldLog accepts string entries that match the case value', function () {
    config()->set('wuz.logging.event_types', ['Message']);
    expect(WuzEventType::MESSAGE->shouldLog())->toBeTrue();
});

it('shouldLog accepts the wildcard *', function () {
    config()->set('wuz.logging.event_types', ['*']);
    expect(WuzEventType::CHAT_PRESENCE->shouldLog())->toBeTrue();
});

it('shouldLog returns false for an empty allowlist', function () {
    config()->set('wuz.logging.event_types', []);
    expect(WuzEventType::MESSAGE->shouldLog())->toBeFalse();
});

it('shouldLog falls back to defaultLoggingTypes when config is missing', function () {
    config()->set('wuz.logging.event_types', null);
    expect(WuzEventType::CONNECTED->shouldLog())->toBeTrue();
    expect(WuzEventType::MESSAGE->shouldLog())->toBeFalse();
});

it('shouldLog matches the raw payload string when no enum case exists for it', function () {
    config()->set('wuz.logging.event_types', ['FooEvent']);
    expect(WuzEventType::UNKNOWN->shouldLog('FooEvent'))->toBeTrue();
    expect(WuzEventType::UNKNOWN->shouldLog('OtherEvent'))->toBeFalse();
    expect(WuzEventType::UNKNOWN->shouldLog(null))->toBeFalse();
});

it('shouldDispatch is symmetric with shouldLog', function () {
    config()->set('wuz.webhook_event.event_types', [WuzEventType::CONNECTED]);
    expect(WuzEventType::CONNECTED->shouldDispatch())->toBeTrue();
    expect(WuzEventType::DISCONNECTED->shouldDispatch())->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/WuzEventTypeGatingTest.php -v`
Expected: FAIL — `Method "defaultLoggingTypes" does not exist` (or similar).

- [ ] **Step 3: Add the predicate methods to `src/Enums/WuzEventType.php`**

Inside the enum body, after the existing `detect()` method, append:

```php
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

public function shouldLog(?string $rawType = null): bool
{
    // Use null-coalesce, not config()'s default arg: a config key that is
    // explicitly set to null (e.g. via config()->set in tests) returns null,
    // which would TypeError into isAllowedBy(array $allowed). The ??
    // pattern handles both "key absent" and "key set to null".
    return $this->isAllowedBy(
        config('wuz.logging.event_types') ?? self::defaultLoggingTypes(),
        $rawType,
    );
}

public function shouldDispatch(?string $rawType = null): bool
{
    return $this->isAllowedBy(
        config('wuz.webhook_event.event_types') ?? self::defaultDispatchTypes(),
        $rawType,
    );
}

/**
 * @param  array<int, self|string>  $allowed
 */
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

- [ ] **Step 4: Run the gating test to verify it passes**

Run: `vendor/bin/pest tests/Unit/WuzEventTypeGatingTest.php -v`
Expected: all tests PASS.

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/pest`
Expected: all tests PASS — predicates are unused by application code so existing tests are unaffected.

- [ ] **Step 6: Commit**

```bash
git add src/Enums/WuzEventType.php tests/Unit/WuzEventTypeGatingTest.php
git commit -m "feat: add WuzEventType gating predicates and defaults"
```

---

### Task 6: Add `logging` and `webhook_event` config keys

**Files:**
- Modify: `config/wuz.php`

**Why:** Wires the published config to the new defaults. No consumer code reads these keys yet — that's Task 7's webhook action refactor.

- [ ] **Step 1: Add the two new keys to `config/wuz.php`**

Open `config/wuz.php`. After the existing `'webhook'` block, before the `'debug'` block, add:

```php
    'logging' => [
        'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultLoggingTypes(),
    ],

    'webhook_event' => [
        'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultDispatchTypes(),
    ],
```

(Use the FQCN inline rather than a `use` import — Laravel package config files conventionally avoid imports.)

- [ ] **Step 2: Run the suite to confirm nothing regressed**

Run: `vendor/bin/pest`
Expected: all tests PASS.

- [ ] **Step 3: Commit**

```bash
git add config/wuz.php
git commit -m "feat: add logging and webhook_event config keys"
```

---

### Task 7: Refactor inbound flow — `MessageReceived` signature + `handleMessage` event-driven

**Files:**
- Modify: `src/Events/MessageReceived.php` — constructor signature
- Modify: `src/Actions/HandleWebhookCallbackAction.php` — `handleMessage()` only
- Modify: `tests/Feature/WebhookCallbackTest.php` — message-related assertions
- Create: `tests/Feature/MessageReceivedEventTest.php`

**Why:** This is the first behaviour-changing task. Drop `WuzDeviceMessage::create` and the auto-`downloadMedia` call from `handleMessage`. Reshape `MessageReceived` to carry parsed fields + raw payload so listeners can persist or download as they like. The rest of the action (gating, safety wrapping, dispatch order) stays unchanged in this task — Task 9 handles that.

This task is naturally large because the event signature change and the action change are inseparable. Steps below isolate atomic changes.

- [ ] **Step 1: Write the new feature test for `MessageReceived`**

Create `tests/Feature/MessageReceivedEventTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

function messagePayload(array $message): array
{
    return [
        'type' => 'Message',
        'Info' => [
            'RemoteJid' => '5511@s.whatsapp.net',
            'Sender' => ['User' => '5511999999999'],
        ],
        'Message' => $message,
    ];
}

it('dispatches MessageReceived with parsed text fields', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'msg-token']);

    app(HandleWebhookCallbackAction::class)->handle(
        'msg-token',
        messagePayload(['conversation' => 'Hello!']),
    );

    Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) use ($device) {
        return $event->device->id === $device->id
            && $event->type === 'text'
            && $event->chatJid === '5511@s.whatsapp.net'
            && $event->senderJid === '5511999999999'
            && $event->content === 'Hello!'
            && is_array($event->payload);
    });
});

it('parses extendedTextMessage as text', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['extendedTextMessage' => ['text' => 'extended hello']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'text' && $e->content === 'extended hello'
    );
});

it('parses imageMessage with caption', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['imageMessage' => ['caption' => 'a photo', 'url' => 'https://x']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'image' && $e->content === 'a photo'
    );
});

it('parses videoMessage with caption', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['videoMessage' => ['caption' => 'a clip']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'video' && $e->content === 'a clip'
    );
});

it('parses documentMessage with filename', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    app(HandleWebhookCallbackAction::class)->handle(
        't',
        messagePayload(['documentMessage' => ['fileName' => 'report.pdf']]),
    );

    Event::assertDispatched(MessageReceived::class, fn ($e) =>
        $e->type === 'document' && $e->content === 'report.pdf'
    );
});

it('does not dispatch MessageReceived when RemoteJid is missing', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    WuzDevice::factory()->for($owner, 'owner')->create(['token' => 't']);

    $payload = [
        'type' => 'Message',
        'Info' => ['Sender' => ['User' => '5511999999999']],
        'Message' => ['conversation' => 'orphan'],
    ];

    app(HandleWebhookCallbackAction::class)->handle('t', $payload);

    Event::assertNotDispatched(MessageReceived::class);
});
```

- [ ] **Step 2: Update `tests/Feature/WebhookCallbackTest.php` — rewrite the MESSAGE test**

Find the test `it('handles MESSAGE events and stores device messages', ...)` (around line 31). Rewrite to match the new event-driven contract — drop `WuzDeviceMessage::count()` assertions, assert on the new `MessageReceived` payload shape:

Replace:

```php
it('handles MESSAGE events and stores device messages', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'msg-token']);

    $payload = [
        'type' => 'Message',
        'Info' => [
            'RemoteJid' => '5511@s.whatsapp.net',
            'Sender' => ['User' => '5511999999999'],
        ],
        'Message' => [
            'conversation' => 'Hello from WhatsApp!',
        ],
    ];

    app(HandleWebhookCallbackAction::class)->handle('msg-token', $payload);

    expect(WuzDeviceMessage::count())->toBe(1);
    $msg = WuzDeviceMessage::first();
    expect($msg->message)->toBe('Hello from WhatsApp!');
    expect($msg->type)->toBe('text');
    expect($msg->chat_jid)->toBe('5511@s.whatsapp.net');

    Event::assertDispatched(MessageReceived::class);
});
```

With:

```php
it('dispatches MessageReceived with parsed text fields for MESSAGE webhooks', function () {
    $owner = TestOwner::create(['name' => 'Test']);
    $device = WuzDevice::factory()->for($owner, 'owner')->create(['token' => 'msg-token']);

    $payload = [
        'type' => 'Message',
        'Info' => [
            'RemoteJid' => '5511@s.whatsapp.net',
            'Sender' => ['User' => '5511999999999'],
        ],
        'Message' => [
            'conversation' => 'Hello from WhatsApp!',
        ],
    ];

    app(HandleWebhookCallbackAction::class)->handle('msg-token', $payload);

    Event::assertDispatched(MessageReceived::class, function (MessageReceived $event) use ($device) {
        return $event->device->id === $device->id
            && $event->type === 'text'
            && $event->chatJid === '5511@s.whatsapp.net'
            && $event->senderJid === '5511999999999'
            && $event->content === 'Hello from WhatsApp!';
    });
});
```

**Also update the second `WuzDeviceMessage` assertion in `WebhookCallbackTest.php`** — the test `it('handles extended text messages', ...)` (around line 85). Replace its body's assertion:

```php
expect(WuzDeviceMessage::first()->message)->toBe('Extended text with link');
```

With:

```php
Event::assertDispatched(MessageReceived::class, fn (MessageReceived $e) =>
    $e->type === 'text' && $e->content === 'Extended text with link'
);
```

After both rewrites, remove the `use JordanMiguel\Wuz\Models\WuzDeviceMessage;` import at the top of the file. Verify with:

```bash
grep -n "WuzDeviceMessage" tests/Feature/WebhookCallbackTest.php
```

Expected: zero matches.

- [ ] **Step 3: Run new + updated tests; expect failure**

Run: `vendor/bin/pest tests/Feature/MessageReceivedEventTest.php tests/Feature/WebhookCallbackTest.php -v`
Expected: FAIL — the assertion on `$event->type` etc. fails because `MessageReceived` still carries the old `$message` property.

- [ ] **Step 4: Update `src/Events/MessageReceived.php` constructor signature**

Replace the entire file with:

```php
<?php

namespace JordanMiguel\Wuz\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JordanMiguel\Wuz\Models\WuzDevice;

class MessageReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param  string  $type  'text' | 'image' | 'video' | 'document'
     * @param  array<string, mixed>  $payload  raw WUZ webhook payload
     */
    public function __construct(
        public readonly WuzDevice $device,
        public readonly string $type,
        public readonly string $chatJid,
        public readonly ?string $senderJid,
        public readonly ?string $content,
        public readonly array $payload,
    ) {}
}
```

- [ ] **Step 5: Refactor `handleMessage()` and remove the `downloadMedia` call**

Open `src/Actions/HandleWebhookCallbackAction.php`. Replace the existing `handleMessage()` method (around lines 50-91) with:

```php
private function handleMessage(WuzDevice $device, array $payload): void
{
    $info = $payload['Info'] ?? [];
    $message = $payload['Message'] ?? [];

    $chatJid = $info['RemoteJid'] ?? null;

    if (! is_string($chatJid) || $chatJid === '') {
        return;
    }

    $senderJid = $info['Sender']['User'] ?? null;
    [$type, $content] = $this->parseMessage($message);

    MessageReceived::dispatch($device, $type, $chatJid, $senderJid, $content, $payload);
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
        return [
            'document',
            $message['documentMessage']['fileName'] ?? $message['documentMessage']['title'] ?? null,
        ];
    }

    return ['text', null];
}
```

Also remove from the action's `use` block (top of the file): `use JordanMiguel\Wuz\Models\WuzDeviceMessage;` and `use Illuminate\Support\Facades\Storage;`.

The private `downloadMedia()` method is now unused. **Leave it in place for this task** — Task 9 will remove it alongside the broader action refactor. (Removing it now would expand this task's scope past the inbound-message focus.)

- [ ] **Step 6: Run all affected tests**

Run: `vendor/bin/pest tests/Feature/MessageReceivedEventTest.php tests/Feature/WebhookCallbackTest.php -v`
Expected: all tests PASS.

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/pest`
Expected: all tests PASS. (`SendMessageActionTest` and others still use `WuzDeviceMessage` because outbound is still on the old contract — Task 8 fixes that.)

- [ ] **Step 8: Commit**

```bash
git add src/Events/MessageReceived.php src/Actions/HandleWebhookCallbackAction.php \
  tests/Feature/WebhookCallbackTest.php tests/Feature/MessageReceivedEventTest.php
git commit -m "refactor: make inbound messages event-driven via MessageReceived

Drop WuzDeviceMessage persistence from handleMessage; reshape
MessageReceived to carry parsed fields + raw payload. Consumers persist
or download media via listeners."
```

---

### Task 8: Refactor outbound flow — `SendMessageAction` returns array, dispatches `MessageSent`

**Files:**
- Create: `src/Events/MessageSent.php`
- Modify: `src/Actions/SendMessageAction.php`
- Modify: `tests/Feature/SendMessageActionTest.php`
- Modify: `tests/Feature/WuzChannelTest.php`
- Create: `tests/Feature/MessageSentEventTest.php`

**Why:** Outbound symmetric to inbound — drop the `WuzDeviceMessage::create` write, return the API response, dispatch `MessageSent` for observability. Removes the `DB::transaction` wrapper because the only remaining DB write (`WuzPhoneJid` cache) is intentionally independent of send outcome (per spec rationale).

- [ ] **Step 1: Create `MessageSent` event**

Create `src/Events/MessageSent.php`:

```php
<?php

namespace JordanMiguel\Wuz\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JordanMiguel\Wuz\Models\WuzDevice;

/**
 * Fires when WUZ accepted an outbound send (HTTP 2xx).
 *
 * Does not assert delivery confirmation — WhatsApp delivery confirmation
 * arrives later as a separate ReadReceipt webhook. Failed sends throw
 * WuzApiException; this event does not fire in that path.
 */
class MessageSent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $apiResponse  raw WUZ API response body
     */
    public function __construct(
        public readonly WuzDevice $device,
        public readonly string $type,
        public readonly string $phone,
        public readonly ?string $content,
        public readonly array $apiResponse,
    ) {}
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/MessageSentEventTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Events\MessageSent;
use JordanMiguel\Wuz\Exceptions\WuzApiException;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Models\WuzPhoneJid;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
});

// Note: Event::fake() is called per-test rather than in beforeEach because the
// listener-isolation test needs the real dispatcher to actually run the listener.

function fakeWuzPhoneAndSend(): void
{
    Http::fake([
        '*/user/lid/*' => Http::response([
            'data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null],
        ], 200),
        '*/chat/send/text' => Http::response([
            'code' => 200,
            'data' => ['id' => 'wamid.123'],
        ], 200),
    ]);
}

// Tests use 12-digit numbers that bypass BrazilianPhoneFallback (which
// only triggers for 13-digit Brazilian mobile numbers via `.isBrazilian()`).
// This keeps phone resolution single-step and assertions deterministic.

it('dispatches MessageSent with the API response on a successful text send', function () {
    Event::fake();
    fakeWuzPhoneAndSend();
    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $response = app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    expect($response)->toBeArray()
        ->and($response['data']['id'] ?? null)->toBe('wamid.123');

    Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($device) {
        return $e->device->id === $device->id
            && $e->type === 'text'
            && $e->phone === '551199999999'
            && $e->content === 'hi';
    });
});

it('dispatches MessageSent for image, video, and document sends', function () {
    Event::fake();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null]], 200),
        '*/chat/send/image' => Http::response(['data' => ['id' => 'img-1']], 200),
        '*/chat/send/video' => Http::response(['data' => ['id' => 'vid-1']], 200),
        '*/chat/send/document' => Http::response(['data' => ['id' => 'doc-1']], 200),
    ]);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999', type: 'image', message: '', caption: 'photo', media: 'data:image/jpeg;base64,AAA',
    ));
    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999', type: 'video', message: '', caption: 'clip', media: 'data:video/mp4;base64,AAA',
    ));
    app(SendMessageAction::class)->handle($device, new SendMessageData(
        phone: '551199999999', type: 'document', message: '', media: 'data:application/pdf;base64,AAA',
    ));

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->type === 'image' && $e->content === 'photo');
    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->type === 'video' && $e->content === 'clip');
    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->type === 'document');
});

it('does not dispatch MessageSent in debug-skip mode', function () {
    Event::fake();
    config()->set('wuz.debug.enabled', true);
    config()->set('wuz.debug.to', null);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $response = app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    expect($response)->toBeNull();
    Event::assertNotDispatched(MessageSent::class);
});

it('dispatches MessageSent with the redirected phone in debug-redirect mode', function () {
    Event::fake();
    fakeWuzPhoneAndSend();
    config()->set('wuz.debug.enabled', true);
    config()->set('wuz.debug.to', '552188888888');

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) =>
        $e->phone === '552188888888'
    );
});

it('does not dispatch MessageSent and propagates WuzApiException on API failure', function () {
    Event::fake();
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null]], 200),
        '*/chat/send/text' => Http::response(['error' => 'rate limit'], 429),
    ]);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    expect(fn () => app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    ))->toThrow(WuzApiException::class);

    Event::assertNotDispatched(MessageSent::class);
});

it('persists the WuzPhoneJid cache row even when the send fails', function () {
    Http::fake([
        '*/user/lid/*' => Http::response(['data' => ['jid' => '5511@s.whatsapp.net', 'lid' => null]], 200),
        '*/chat/send/text' => Http::response(['error' => 'rate limit'], 429),
    ]);

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    try {
        app(SendMessageAction::class)->handle(
            $device,
            new SendMessageData(phone: '551199999999', message: 'hi'),
        );
    } catch (WuzApiException) {
        // expected
    }

    expect(WuzPhoneJid::where('phone', '551199999999')->count())->toBe(1);
});

it('does not propagate MessageSent listener exceptions', function () {
    // Note: NO Event::fake() — the real dispatcher must run so the listener
    // fires and the safely() catch-and-report path is exercised.
    fakeWuzPhoneAndSend();
    \Illuminate\Support\Facades\Exceptions::fake();

    \Illuminate\Support\Facades\Event::listen(MessageSent::class, function () {
        throw new RuntimeException('listener boom');
    });

    $owner = TestOwner::create(['name' => 'O']);
    $device = WuzDevice::factory()->for($owner, 'owner')->connected()->create();

    $response = app(SendMessageAction::class)->handle(
        $device,
        new SendMessageData(phone: '551199999999', message: 'hi'),
    );

    expect($response)->toBeArray()
        ->and($response['data']['id'] ?? null)->toBe('wamid.123');

    \Illuminate\Support\Facades\Exceptions::assertReported(RuntimeException::class);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/MessageSentEventTest.php -v`
Expected: FAIL — `MessageSent` not dispatched (action still creates `WuzDeviceMessage` and never fires the new event).

- [ ] **Step 4: Refactor `src/Actions/SendMessageAction.php`**

Replace the entire file with:

```php
<?php

namespace JordanMiguel\Wuz\Actions;

use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Data\SendMessageData;
use JordanMiguel\Wuz\Events\MessageSent;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Services\WuzService;
use JordanMiguel\Wuz\Services\WuzServiceFactory;

class SendMessageAction
{
    public function __construct(
        private readonly WuzServiceFactory $factory,
        private readonly ValidatePhoneAction $validatePhone,
    ) {}

    /**
     * @return array<string, mixed>|null  WUZ API response body, or null when debug-skipped.
     */
    public function handle(WuzDevice $device, SendMessageData $data): ?array
    {
        if (config('wuz.debug.enabled')) {
            $debugTo = config('wuz.debug.to');

            if (empty($debugTo)) {
                Log::info('Wuz debug: message skipped', [
                    'phone' => $data->phone,
                    'type' => $data->type,
                    'message' => $data->message,
                ]);

                return null;
            }

            $data = new SendMessageData(
                phone: $debugTo,
                type: $data->type,
                message: $data->message,
                caption: $data->caption,
                media: $data->media,
                buttons: $data->buttons,
                link_preview: $data->link_preview,
            );
        }

        $wuz = $this->factory->make($device);
        $validated = $this->validatePhone->handle($wuz, $data->phone);
        $phone = $validated->phone;

        [$response, $messageContent] = $this->dispatchToWuz($wuz, $phone, $data);

        $event = new MessageSent($device, $data->type, $phone, $messageContent, $response);
        $this->safely(fn () => event($event));

        return $response;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function dispatchToWuz(WuzService $wuz, string $phone, SendMessageData $data): array
    {
        return match ($data->type) {
            'text' => [
                $wuz->sendMessageText($phone, $data->message, $data->link_preview),
                $data->message,
            ],
            'image' => [
                $wuz->sendMessageImage($phone, $this->encodeMedia($data->media), $data->caption ?? ''),
                $data->caption ?? 'Image',
            ],
            'video' => [
                $wuz->sendMessageVideo($phone, $this->encodeMedia($data->media), $data->caption ?? ''),
                $data->caption ?? 'Video',
            ],
            'document' => [
                $wuz->sendMessageDocument(
                    $phone,
                    $this->encodeMedia($data->media),
                    is_object($data->media) && method_exists($data->media, 'getClientOriginalName')
                        ? $data->media->getClientOriginalName()
                        : 'document',
                ),
                is_object($data->media) && method_exists($data->media, 'getClientOriginalName')
                    ? $data->media->getClientOriginalName()
                    : 'document',
            ],
            'button' => [
                $wuz->sendMessageButton($phone, $data->message, $data->buttons ?? []),
                $data->message,
            ],
        };
    }

    private function encodeMedia(mixed $media): string
    {
        if (is_string($media)) {
            return $media;
        }

        if (is_object($media) && method_exists($media, 'getRealPath')) {
            $content = base64_encode(file_get_contents($media->getRealPath()));
            $mimeType = $media->getMimeType();

            return "data:{$mimeType};base64,{$content}";
        }

        return '';
    }

    private function safely(\Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
```

(Note: removed `Illuminate\Support\Facades\DB` and `JordanMiguel\Wuz\Models\WuzDeviceMessage` imports; added `MessageSent` and `WuzService`.)

- [ ] **Step 5: Update `tests/Feature/SendMessageActionTest.php`**

The existing file has 7 tests, several asserting on `WuzDeviceMessage` instances. Note the existing mock returns use `data.sent`/`data.id` (no top-level `code` key) — assertions below use `data.id` to match.

**Update the file's imports.** At the top, change:

```php
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
```

To:

```php
use Illuminate\Support\Facades\Event;
use JordanMiguel\Wuz\Events\MessageSent;
```

Add at the very top (after existing `use` lines), if not present:

```php
beforeEach(function () {
    Event::fake();
});
```

**Test 1 (`it('sends a text message and stores it', ...)` around line 13):** rename and rewrite the assertion block. Replace lines 30-33:

```php
expect($message)->toBeInstanceOf(WuzDeviceMessage::class);
expect($message->message)->toBe('Hello from test!');
expect($message->type)->toBe('text');
expect($message->wuz_device_id)->toBe($device->id);
```

With:

```php
expect($message)->toBeArray()
    ->and($message['data']['id'] ?? null)->toBe('msg-1');

Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($device) {
    return $e->device->id === $device->id
        && $e->type === 'text'
        && $e->content === 'Hello from test!';
});
```

Also rename the test description from `'sends a text message and stores it'` to `'sends a text message and dispatches MessageSent'`.

**Test 3 (`it('sends a button message', ...)` around line 56):** replace the trailing assertions (lines 73-74):

```php
expect($message->type)->toBe('button');
expect($message->message)->toBe('Choose an option');
```

With:

```php
expect($message)->toBeArray();

Event::assertDispatched(MessageSent::class, fn (MessageSent $e) =>
    $e->type === 'button' && $e->content === 'Choose an option'
);
```

**Test 5 (`it('redirects message to debug phone when WUZ_DEBUG is enabled', ...)` around line 94):** replace lines 116-117:

```php
expect($message)->toBeInstanceOf(WuzDeviceMessage::class);
expect($message->message)->toBe('Hello debug!');
```

With:

```php
expect($message)->toBeArray();

Event::assertDispatched(MessageSent::class, fn (MessageSent $e) =>
    $e->phone === '552188888888' && $e->content === 'Hello debug!'
);
```

**Test 6 (`it('logs and skips sending when WUZ_DEBUG is enabled without WUZ_DEBUG_TO', ...)` around line 120):** the assertion `expect($result)->toBeNull()` already matches the new contract — keep it. Replace this line:

```php
expect(WuzDeviceMessage::count())->toBe(0);
```

With:

```php
Event::assertNotDispatched(MessageSent::class);
```

**Test 7 (`it('sends normally when WUZ_DEBUG is disabled', ...)` around line 146):** replace line 165:

```php
expect($message)->toBeInstanceOf(WuzDeviceMessage::class);
```

With:

```php
expect($message)->toBeArray();
Event::assertDispatched(MessageSent::class);
```

(Test 2 — `'normalizes phone and resolves JID'` — and Test 4 — `'throws when phone is not registered'` — do not touch `WuzDeviceMessage`. Leave them alone.)

- [ ] **Step 5b: Update `tests/Feature/WuzChannelTest.php`**

The first test at line 38 (`'sends a message via the WuzChannel'`) asserts on `WuzDeviceMessage`. The channel itself doesn't change, but it now drives the new `MessageSent` event. Update:

Remove the import at line 6:

```php
use JordanMiguel\Wuz\Models\WuzDeviceMessage;
```

Add at the top (after the existing `use` lines):

```php
use Illuminate\Support\Facades\Event;
use JordanMiguel\Wuz\Events\MessageSent;
```

Add (or merge into existing) at the top of the file, before `class TestWuzNotification`:

```php
beforeEach(function () {
    Event::fake();
});
```

In the `'sends a message via the WuzChannel'` test, replace lines 58-61:

```php
$message = WuzDeviceMessage::where('wuz_device_id', $device->id)->first();
expect($message)->not->toBeNull()
    ->and($message->message)->toBe('Hello from notification!')
    ->and($message->type)->toBe('text');
```

With:

```php
Event::assertDispatched(MessageSent::class, fn (MessageSent $e) =>
    $e->device->id === $device->id
        && $e->type === 'text'
        && $e->content === 'Hello from notification!'
);
```

Verify with:

```bash
grep -n "WuzDeviceMessage" tests/Feature/WuzChannelTest.php
```

Expected: zero matches.

- [ ] **Step 6: Run the targeted tests**

Run: `vendor/bin/pest tests/Feature/MessageSentEventTest.php tests/Feature/SendMessageActionTest.php tests/Feature/WuzChannelTest.php -v`
Expected: all tests PASS.

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/pest`
Expected: all tests PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Events/MessageSent.php src/Actions/SendMessageAction.php \
  tests/Feature/SendMessageActionTest.php tests/Feature/MessageSentEventTest.php \
  tests/Feature/WuzChannelTest.php
git commit -m "refactor: outbound sends dispatch MessageSent and return ?array

Drop WuzDeviceMessage::create from SendMessageAction; remove DB
transaction wrapper (WuzPhoneJid cache writes intentionally independent
of send outcome). Listener exceptions reported via safely()."
```

---

### Task 9: Refactor `HandleWebhookCallbackAction` — gating, safety, dispatch order

**Files:**
- Modify: `src/Actions/HandleWebhookCallbackAction.php`
- Create: `tests/Feature/CallbackLogGatingTest.php`
- Create: `tests/Feature/WebhookEventGatingTest.php`
- Create: `tests/Feature/DispatchOrderTest.php`

**Why:** Wires up the gating predicates (Task 5) into the action, reorders so state mutations precede observability dispatches, isolates listener and log exceptions through `safely()`. Removes the now-unused `downloadMedia()` private method and constructs every event eagerly so package-side type errors propagate.

- [ ] **Step 1: Write the gating feature tests**

Create `tests/Feature/CallbackLogGatingTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

function deviceWithToken(string $token): WuzDevice
{
    $owner = TestOwner::create(['name' => 'O']);
    return WuzDevice::factory()->for($owner, 'owner')->create(['token' => $token]);
}

it('inserts a log row when the type is in the allowlist', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::CONNECTED]);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    expect(WuzCallbackLog::count())->toBe(1);
});

it('skips log insertion when the type is not in the allowlist', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::CONNECTED]);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    expect(WuzCallbackLog::count())->toBe(0);
});

it('logs every type when the allowlist is the wildcard', function () {
    config()->set('wuz.logging.event_types', ['*']);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    expect(WuzCallbackLog::count())->toBe(1);
});

it('logs nothing when the allowlist is empty', function () {
    config()->set('wuz.logging.event_types', []);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    expect(WuzCallbackLog::count())->toBe(0);
});

it('accepts string entries alongside enum cases', function () {
    config()->set('wuz.logging.event_types', ['Connected', WuzEventType::DISCONNECTED]);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect(WuzCallbackLog::count())->toBe(2);
});

it('falls back to defaultLoggingTypes when the config key is null', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    // CONNECTED is in defaults, ChatPresence is not.
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    expect(WuzCallbackLog::count())->toBe(1);
});

it('does not log MESSAGE under defaults', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', [
        'type' => 'Message',
        'Info' => ['RemoteJid' => 'x@s.whatsapp.net'],
        'Message' => ['conversation' => 'hi'],
    ]);

    expect(WuzCallbackLog::count())->toBe(0);
});

it('logs UNKNOWN payload types under defaults and preserves the raw type', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'BrandNewEvent']);

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('BrandNewEvent');
});

it('matches a raw-string allowlist entry against the payload type', function () {
    config()->set('wuz.logging.event_types', ['FooEvent']);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'FooEvent']);

    expect(WuzCallbackLog::count())->toBe(1);
    expect(WuzCallbackLog::first()->event_type)->toBe('FooEvent');
});

it('does not throw and logs UNKNOWN for malformed type fields', function () {
    config()->set('wuz.logging.event_types', null);
    deviceWithToken('t');

    app(HandleWebhookCallbackAction::class)->handle('t', []);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => null]);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 42]);
    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => ['x']]);

    expect(WuzCallbackLog::count())->toBe(4);
    expect(WuzCallbackLog::all()->pluck('event_type')->all())->each->toBe('Unknown');
});
```

Create `tests/Feature/WebhookEventGatingTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
    Event::fake();
});

function gatingDevice(string $token = 't'): WuzDevice
{
    $owner = TestOwner::create(['name' => 'O']);
    return WuzDevice::factory()->for($owner, 'owner')->create(['token' => $token]);
}

it('dispatches WebhookReceived when the type is in the allowlist', function () {
    config()->set('wuz.webhook_event.event_types', [WuzEventType::CONNECTED]);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    Event::assertDispatched(WebhookReceived::class);
});

it('does not dispatch WebhookReceived when the type is not in the allowlist', function () {
    config()->set('wuz.webhook_event.event_types', [WuzEventType::CONNECTED]);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'ChatPresence']);

    Event::assertNotDispatched(WebhookReceived::class);
});

it('keeps logging and dispatch allowlists independent', function () {
    config()->set('wuz.logging.event_types', [WuzEventType::CONNECTED]);
    config()->set('wuz.webhook_event.event_types', []);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Connected']);

    Event::assertNotDispatched(WebhookReceived::class);
    expect(\JordanMiguel\Wuz\Models\WuzCallbackLog::count())->toBe(1);
});

it('matches a raw-string allowlist entry against the payload type', function () {
    config()->set('wuz.webhook_event.event_types', ['FooEvent']);
    gatingDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'FooEvent']);

    Event::assertDispatched(WebhookReceived::class);
});
```

Create `tests/Feature/DispatchOrderTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JordanMiguel\Wuz\Actions\HandleWebhookCallbackAction;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;
use JordanMiguel\Wuz\Tests\Fixtures\TestOwner;

beforeEach(function () {
    Http::preventStrayRequests();
});

function orderDevice(string $token = 't'): WuzDevice
{
    $owner = TestOwner::create(['name' => 'O']);
    return WuzDevice::factory()->for($owner, 'owner')->connected()->create(['token' => $token]);
}

it('does not propagate WebhookReceived listener exceptions', function () {
    config()->set('wuz.webhook_event.event_types', ['*']);
    Event::listen(WebhookReceived::class, fn () => throw new RuntimeException('boom'));
    $device = orderDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
});

it('does not propagate DeviceDisconnected listener exceptions', function () {
    Event::listen(DeviceDisconnected::class, fn () => throw new RuntimeException('boom'));
    $device = orderDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
});

it('does not propagate MessageReceived listener exceptions and still inserts the log row when applicable', function () {
    config()->set('wuz.logging.event_types', ['*']);
    Event::listen(MessageReceived::class, fn () => throw new RuntimeException('boom'));
    orderDevice();

    app(HandleWebhookCallbackAction::class)->handle('t', [
        'type' => 'Message',
        'Info' => ['RemoteJid' => 'x@s.whatsapp.net'],
        'Message' => ['conversation' => 'hi'],
    ]);

    expect(WuzCallbackLog::count())->toBe(1);
});

it('keeps state mutations on Disconnected even when log insert throws', function () {
    config()->set('wuz.logging.event_types', ['*']);
    $device = orderDevice();

    // Drop the table to force WuzCallbackLog::create() to throw.
    $device->getConnection()->getSchemaBuilder()->drop(
        config('wuz.table_names.callback_logs', 'wuz_callback_logs'),
    );

    app(HandleWebhookCallbackAction::class)->handle('t', ['type' => 'Disconnected']);

    expect($device->fresh()->connected)->toBeFalse();
});
```

- [ ] **Step 2: Run new tests; expect failures**

Run: `vendor/bin/pest tests/Feature/CallbackLogGatingTest.php tests/Feature/WebhookEventGatingTest.php tests/Feature/DispatchOrderTest.php -v`
Expected: many tests FAIL — gating isn't wired up; the action still logs/dispatches every type and propagates listener exceptions.

- [ ] **Step 3: Refactor `src/Actions/HandleWebhookCallbackAction.php`**

Replace the entire file with the version below. Note: the constructor (which previously injected `WuzServiceFactory` only for `downloadMedia()`) is removed because `downloadMedia()` is gone — the action now has no dependencies.

```php
<?php

namespace JordanMiguel\Wuz\Actions;

use JordanMiguel\Wuz\Enums\WuzEventType;
use JordanMiguel\Wuz\Events\DeviceDisconnected;
use JordanMiguel\Wuz\Events\MessageReceived;
use JordanMiguel\Wuz\Events\WebhookReceived;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use JordanMiguel\Wuz\Models\WuzDevice;

class HandleWebhookCallbackAction
{
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

        // 2. Best-effort log insert.
        if ($eventType->shouldLog($rawType)) {
            $this->safely(fn () => WuzCallbackLog::create([
                'wuz_device_id' => $device->id,
                'event_type' => $rawType ?? $eventType->value,
                'payload' => $payload,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]));
        }

        // 3. Best-effort generic WebhookReceived dispatch.
        if ($eventType->shouldDispatch($rawType)) {
            $event = new WebhookReceived($device, $eventType, $payload);
            $this->safely(fn () => event($event));
        }
    }

    private function handleMessage(WuzDevice $device, array $payload): void
    {
        $info = $payload['Info'] ?? [];
        $message = $payload['Message'] ?? [];

        $chatJid = $info['RemoteJid'] ?? null;

        if (! is_string($chatJid) || $chatJid === '') {
            return;
        }

        $senderJid = $info['Sender']['User'] ?? null;
        [$type, $content] = $this->parseMessage($message);

        $event = new MessageReceived($device, $type, $chatJid, $senderJid, $content, $payload);
        $this->safely(fn () => event($event));
    }

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

    /**
     * @return array{0: string, 1: ?string}
     */
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
            return [
                'document',
                $message['documentMessage']['fileName'] ?? $message['documentMessage']['title'] ?? null,
            ];
        }

        return ['text', null];
    }

    private function safely(\Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
```

The replacement above removes: `downloadMedia()` private method, the `Storage`/`Log`/`WuzDeviceMessage`/`WuzServiceFactory` imports, and the constructor (which existed only to inject the now-unneeded factory).

- [ ] **Step 4: Run all targeted tests**

Run: `vendor/bin/pest tests/Feature/CallbackLogGatingTest.php tests/Feature/WebhookEventGatingTest.php tests/Feature/DispatchOrderTest.php tests/Feature/WebhookCallbackTest.php tests/Feature/MessageReceivedEventTest.php -v`
Expected: all tests PASS.

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/pest`
Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Actions/HandleWebhookCallbackAction.php \
  tests/Feature/CallbackLogGatingTest.php tests/Feature/WebhookEventGatingTest.php \
  tests/Feature/DispatchOrderTest.php
git commit -m "refactor: gate, isolate, and reorder HandleWebhookCallbackAction

State mutations run before log insert and event dispatch. Log writes
and event dispatches wrapped in safely() so consumer listener bugs and
DB blips do not fail the webhook route (which would trigger WUZ
retries and duplicate side effects). Events constructed eagerly so
package-side type errors propagate."
```

---

### Task 10: Drop `WuzDeviceMessage` and remove `download_media`

**Files:**
- Modify: `src/Models/WuzDevice.php`
- Delete: `src/Models/WuzDeviceMessage.php`
- Delete: `database/migrations/create_wuz_device_messages_table.php.stub`
- Modify: `src/WuzServiceProvider.php`
- Modify: `tests/TestCase.php`
- Modify: `config/wuz.php`

**Why:** Surface-deletion task. Nothing in the codebase has referenced `WuzDeviceMessage` or `download_media` since Tasks 7-9 completed. Now safe to delete.

- [ ] **Step 1: Verify nothing references the to-be-deleted symbols**

Run:

```bash
grep -rn "WuzDeviceMessage" src/ tests/ database/ config/
grep -rn "download_media\|WUZ_DOWNLOAD_MEDIA\|downloadMedia" src/Actions/HandleWebhookCallbackAction.php
```

Expected: zero matches. If any appear, an earlier task left a residue — fix that first, do not proceed.

- [ ] **Step 2: Remove `messages()` from `src/Models/WuzDevice.php`**

Delete this block (lines 49-52):

```php
public function messages(): HasMany
{
    return $this->hasMany(WuzDeviceMessage::class, 'wuz_device_id');
}
```

Also remove the `use JordanMiguel\Wuz\Models\WuzDeviceMessage;` import if it exists (it shouldn't now, but verify).

- [ ] **Step 3: Update `src/WuzServiceProvider.php`**

Find the `hasMigrations(...)` array and remove `'create_wuz_device_messages_table',`:

```php
->hasMigrations([
    'create_wuz_devices_table',
    'create_wuz_device_messages_table',  // <-- REMOVE THIS LINE
    'create_wuz_callback_logs_table',
    'create_wuz_device_webhooks_table',
    'create_wuz_phone_jids_table',
])
```

- [ ] **Step 4: Update `tests/TestCase.php`**

In `setUpDatabase()` (around lines 45-46), delete this block:

```php
$migration = include __DIR__ . '/../database/migrations/create_wuz_device_messages_table.php.stub';
$migration->up();
```

- [ ] **Step 5: Update `config/wuz.php` — remove `download_media` and the `device_messages` table-name entry**

Delete the line:

```php
'download_media' => env('WUZ_DOWNLOAD_MEDIA', false),
```

In the `'table_names'` array, delete:

```php
'device_messages' => 'wuz_device_messages',
```

- [ ] **Step 6: Delete the model and migration files**

Run:

```bash
rm src/Models/WuzDeviceMessage.php
rm database/migrations/create_wuz_device_messages_table.php.stub
```

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/pest`
Expected: all tests PASS.

- [ ] **Step 8: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors. (If phpstan complains about now-unused imports anywhere, clean them.)

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor: drop WuzDeviceMessage and download_media surface

Model + migration + relationship deleted; download_media config removed.
Consumers persist messages and download media via event listeners."
```

---

### Task 11: Write `UPGRADING.md`

**Files:**
- Create: `UPGRADING.md`

**Why:** Major-version migration guide for v1 consumers. Spec dictates contents.

- [ ] **Step 1: Create `UPGRADING.md` with the full migration guide**

Create `/UPGRADING.md` (repo root):

```markdown
# Upgrading from v1.x to v2.x

v2 is a breaking-change release that addresses a production-volume issue: every webhook callback was being logged in full and dispatched as a generic event, regardless of type. With ~40 distinct WUZ event types — many of them very high-volume — this generated unmanageable amounts of `wuz_callback_logs` data.

This guide walks through every breaking change.

## TL;DR

- **Logging is now allowlisted.** Only ~12 event types are logged by default. `MESSAGE` is **not** in defaults — see "Selective logging" below.
- **`WebhookReceived` dispatch is now allowlisted.** Only the four lifecycle types fire it by default.
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
```

- [ ] **Step 2: Verify the file lints (markdown)**

Run: `cat UPGRADING.md | head -20`
Expected: file exists, content matches above.

- [ ] **Step 3: Commit**

```bash
git add UPGRADING.md
git commit -m "docs: add UPGRADING.md for v2.x migration guide"
```

---

### Task 12: README "Configuration" subsection

**Files:**
- Modify: `README.md`

**Why:** Surface the new config keys and event-driven contract for first-time consumers.

- [ ] **Step 1: Locate the existing README.md and identify a sensible insertion point**

Open `README.md`. The existing structure references `Webhooks & Events` around line 260. Add a new subsection immediately above it.

- [ ] **Step 2: Add the "Configuration — Selective Logging and Event-Driven Messages" subsection**

Insert before the `## Webhooks & Events` section:

```markdown
## Configuration — Selective Logging and Event-Driven Messages

`config/wuz.php` exposes three knobs for the webhook pipeline:

```php
'logging' => [
    // Allowlist of event types to insert into wuz_callback_logs.
    // Use ['*'] for all, [] for none, or any mix of WuzEventType cases / strings.
    'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultLoggingTypes(),
],

'webhook_event' => [
    // Allowlist of event types that dispatch the generic WebhookReceived event.
    'event_types' => \JordanMiguel\Wuz\Enums\WuzEventType::defaultDispatchTypes(),
],
```

**Defaults are conservative.** `MESSAGE` is **not** logged by default — opt in if you want raw payload rows. Lifecycle, pairing, and error events are logged so you have a forensic trail when things break.

**Inbound messages are event-driven.** Subscribe to `JordanMiguel\Wuz\Events\MessageReceived` and persist however your domain needs:

```php
class StoreIncomingMessage
{
    public function handle(\JordanMiguel\Wuz\Events\MessageReceived $event): void
    {
        // $event->device, $event->type, $event->chatJid,
        // $event->senderJid, $event->content, $event->payload (raw)
    }
}
```

`MessageSent` fires symmetrically for outbound (HTTP 2xx WUZ responses only).

For media: call `WuzService::downloadImage/downloadVideo/downloadDocument` from inside a `MessageReceived` listener. The package no longer auto-downloads.

See `UPGRADING.md` for the v1→v2 migration guide.

```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: add Configuration section for v2 selective logging"
```

---

## Final Verification

- [ ] **Run the full test suite**

Run: `vendor/bin/pest`
Expected: all tests PASS.

- [ ] **Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no errors.

- [ ] **Confirm no orphan references**

Run:

```bash
grep -rn "WuzDeviceMessage\|download_media\|WUZ_DOWNLOAD_MEDIA\|downloadMedia\|table_names\.device_messages" \
  src/ tests/ database/ config/ README.md UPGRADING.md
```

Expected: only references inside `UPGRADING.md` (documenting the removal). No matches in `src/`, `tests/`, `database/`, `config/`.

- [ ] **Confirm enum is clean**

Run: `grep -E "case (RECEIPT|STREAM_REPLACED|APP_STATE|APP_STATE_SYNC_COMPLETE|PUSH_NAME_SETTING|QR_SCANNED_WITHOUT_MULTIDEVICE|CAT_REFRESH_ERROR|ALL)\\b" src/Enums/WuzEventType.php`
Expected: no matches. (Word-boundary anchor `\b` and the `case ` prefix prevent `READ_RECEIPT` from matching the `RECEIPT` alternative.)

- [ ] **Smoke-test the webhook route end-to-end**

```bash
php -S localhost:8000 -t public/  # if applicable for testbench, otherwise rely on Pest
```

(Optional manual check.)

- [ ] **Compare against the design spec**

Open `docs/superpowers/specs/2026-05-02-selective-event-logging-design.md`. Walk each section, confirm a matching change landed in the codebase. Look for any "Files Touched" entry in the spec without a corresponding plan task.
