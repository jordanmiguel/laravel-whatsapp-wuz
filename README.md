# Laravel WhatsApp Wuz

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jordanmiguel/laravel-whatsapp-wuz.svg?style=flat-square)](https://packagist.org/packages/jordanmiguel/laravel-whatsapp-wuz)
[![Total Downloads](https://img.shields.io/packagist/dt/jordanmiguel/laravel-whatsapp-wuz.svg?style=flat-square)](https://packagist.org/packages/jordanmiguel/laravel-whatsapp-wuz)
[![License](https://img.shields.io/packagist/l/jordanmiguel/laravel-whatsapp-wuz.svg?style=flat-square)](https://packagist.org/packages/jordanmiguel/laravel-whatsapp-wuz)
[![Buy me a coffee](https://img.shields.io/badge/donate-PayPal-blue.svg?style=flat-square)](https://www.paypal.com/donate/?hosted_button_id=6PAXAFGESHQDY)

A Laravel package for managing WhatsApp devices through [WuzAPI](https://github.com/asternic/wuzapi). Connect multiple WhatsApp numbers to your application with multi-owner, multi-device support, Laravel notifications, and automatic webhook handling.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick Start](#quick-start)
- [Core Concepts](#core-concepts)
- [Usage](#usage)
- [Notification Channel](#notification-channel)
- [Webhooks & Events](#webhooks--events)
- [Multi-Device Management](#multi-device-management)
- [Facade](#facade)
- [Testing](#testing)
- [License](#license)

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- A running [WuzAPI](https://github.com/asternic/wuzapi) instance

## Installation

```bash
composer require jordanmiguel/laravel-whatsapp-wuz
```

Publish the config and migrations, then run them:

```bash
php artisan vendor:publish --tag="laravel-whatsapp-wuz-config"
php artisan vendor:publish --tag="laravel-whatsapp-wuz-migrations"
php artisan migrate
```

## Configuration

Add these variables to your `.env` file:

```env
WUZ_ENABLED=true
WUZ_API_URL=http://localhost:8080
WUZ_ADMIN_TOKEN=your-admin-token
WUZ_DEFAULT_COUNTRY_CODE=55
WUZ_DOWNLOAD_MEDIA=false
WUZ_DEBUG=false
WUZ_DEBUG_TO=
```

| Variable | Description |
|----------|-------------|
| `WUZ_ENABLED` | Enable or disable the package globally |
| `WUZ_API_URL` | URL of your WuzAPI instance |
| `WUZ_ADMIN_TOKEN` | Admin token for managing devices via the WuzAPI |
| `WUZ_DEFAULT_COUNTRY_CODE` | Default country code for phone number normalization |
| `WUZ_DOWNLOAD_MEDIA` | Automatically download media from incoming messages |
| `WUZ_DEBUG` | Enable debug mode — redirects or skips all outgoing messages |
| `WUZ_DEBUG_TO` | Phone number to redirect all messages to (leave empty to log-only) |

See `config/wuz.php` for additional options like custom table names, webhook path, and middleware.

## Debug Mode

When `WUZ_DEBUG=true`, all outgoing messages are intercepted before sending. This lets you test the full pipeline in development/staging without messaging real users.

- **With `WUZ_DEBUG_TO` set**: All messages are redirected to that phone number, going through the same validation and sending pipeline.
- **With `WUZ_DEBUG_TO` empty**: Messages are logged via `Log::info()` and skipped entirely — no API call, no database record.

## Quick Start

**1. Add the trait and interface to your owner model** (any Eloquent model that owns WhatsApp devices):

```php
use JordanMiguel\Wuz\Contracts\HasWuzDevices as HasWuzDevicesContract;
use JordanMiguel\Wuz\Traits\HasWuzDevices;

class Clinic extends Model implements HasWuzDevicesContract
{
    use HasWuzDevices;
}
```

**2. Create a device:**

```php
use JordanMiguel\Wuz\Actions\StoreDeviceAction;
use JordanMiguel\Wuz\Data\StoreDeviceData;

$device = app(StoreDeviceAction::class)->handle(
    owner: $clinic,
    data: new StoreDeviceData(name: 'Reception Phone'),
    createdBy: auth()->id(),
);
```

**3. Get the QR code and scan it with WhatsApp:**

```php
use JordanMiguel\Wuz\Actions\GetDeviceStatusAction;

$status = app(GetDeviceStatusAction::class)->handle($device);
// $status->status   => 'connected' | 'qr' | 'disconnected'
// $status->qr_code  => base64 QR data (when status is 'qr')
```

**4. Send a message:**

```php
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;

app(SendMessageAction::class)->handle($device, new SendMessageData(
    phone: '5511999999999',
    type: 'text',
    message: 'Hello from Laravel!',
));
```

## Core Concepts

### Owners & Devices

Any Eloquent model can own WhatsApp devices by implementing the `HasWuzDevices` interface. This uses a polymorphic relationship, so a `Clinic`, `Organization`, `Team`, or `User` model can all own devices independently.

Each owner can have multiple devices. One device per owner is always marked as the **default** — this is the device used when sending notifications.

### Device Lifecycle

```
Create → Connect → Scan QR → Connected → Send/Receive Messages
```

1. **Create** — Registers a new device with WuzAPI and stores it in your database
2. **Connect** — Initiates the connection (happens automatically on create)
3. **Scan QR** — Use `GetDeviceStatusAction` to retrieve the QR code for the user to scan with their WhatsApp mobile app
4. **Connected** — Once scanned, the device is connected and ready to send/receive messages
5. **Send/Receive** — Send messages via `SendMessageAction`, receive via webhooks

## Usage

### Managing Devices

#### Create a Device

```php
use JordanMiguel\Wuz\Actions\StoreDeviceAction;
use JordanMiguel\Wuz\Data\StoreDeviceData;

$device = app(StoreDeviceAction::class)->handle(
    owner: $clinic,
    data: new StoreDeviceData(name: 'Reception Phone'),
    createdBy: auth()->id(),
);
```

The first device per owner is automatically set as default.

#### Check Device Status / Get QR Code

```php
use JordanMiguel\Wuz\Actions\GetDeviceStatusAction;

$status = app(GetDeviceStatusAction::class)->handle($device);
// $status->status   => 'connected' | 'qr' | 'disconnected'
// $status->qr_code  => base64 QR data (when status is 'qr')
```

#### Disconnect a Device

```php
use JordanMiguel\Wuz\Actions\DisconnectDeviceAction;

app(DisconnectDeviceAction::class)->handle($device);
```

#### Delete a Device

```php
use JordanMiguel\Wuz\Actions\DeleteDeviceAction;

app(DeleteDeviceAction::class)->handle($device);
```

When you delete the default device, the next oldest device is automatically promoted to default.

### Sending Messages

```php
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;

$message = app(SendMessageAction::class)->handle($device, new SendMessageData(
    phone: '5511999999999',
    type: 'text',
    message: 'Hello from Laravel!',
));
```

Supported message types: `text`, `image`, `video`, `document`, `button`.

## Notification Channel

Send WhatsApp messages through Laravel's notification system.

### Define a Notification

```php
use JordanMiguel\Wuz\Notifications\WuzMessage;

class AppointmentReminder extends Notification
{
    public function via($notifiable): array
    {
        return ['wuz'];
    }

    public function toWuz($notifiable): WuzMessage
    {
        return WuzMessage::create('Your appointment is tomorrow at 10am.');
    }
}
```

### Set Up the Notifiable Model

The notifiable model needs the `InteractsWithWuz` trait to resolve which device should send the message:

```php
use JordanMiguel\Wuz\Traits\InteractsWithWuz;

class ClientProfile extends Model
{
    use InteractsWithWuz, Notifiable;

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    public function resolveWuzOwner(): mixed
    {
        return $this->clinic;
    }
}
```

The channel resolves the device by calling `resolveWuzOwner()` on the notifiable, then uses that owner's default device to send the message.

## Webhooks & Events

Incoming WhatsApp events are received at `POST /api/wuz/webhook/{token}` (configurable in `config/wuz.php`).

### Handled Events

| Webhook Event | What Happens | Event Dispatched |
|---------------|--------------|------------------|
| **Message** | Stores the incoming message in `wuz_device_messages` | `MessageReceived` |
| **Disconnected** | Updates the device's connected state | `DeviceDisconnected` |
| **LoggedOut** | Clears the device's JID and marks as disconnected | `DeviceDisconnected` |

All callbacks also dispatch a `WebhookReceived` event and are logged in the `wuz_callback_logs` table.

### Listening to Events

Register listeners in your `EventServiceProvider` or use Laravel's event discovery:

```php
use JordanMiguel\Wuz\Events\MessageReceived;

class HandleIncomingMessage
{
    public function handle(MessageReceived $event): void
    {
        $device = $event->device;
        $message = $event->message;
        // Process incoming message...
    }
}
```

## Multi-Device Management

Each owner can have multiple WhatsApp devices. The package enforces that exactly one device per owner is the default.

| Rule | Behavior |
|------|----------|
| Multiple devices per owner | Supported via polymorphic relationship |
| One default per owner | Enforced via `is_default` flag |
| First device auto-default | Handled by `StoreDeviceAction` |
| Delete promotes next | Handled by `DeleteDeviceAction` |
| Explicit switch | `$owner->setDefaultWuzDevice($device)` |
| Notification routing | `WuzChannel` uses the owner's default device |

## Facade

```php
use JordanMiguel\Wuz\Facades\Wuz;

$service = Wuz::make($device); // device-scoped WuzService
$admin   = Wuz::admin();       // admin-scoped WuzService
```

## Testing

Mock the WuzAPI in your application tests using Laravel's HTTP fakes:

```php
Http::fake([
    '*/session/status' => Http::response(['data' => ['loggedIn' => true]], 200),
    '*/chat/send/text' => Http::response(['data' => ['sent' => true]], 200),
]);
```

Run the package tests:

```bash
composer test
```

## License

MIT
