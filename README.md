# Laravel WhatsApp Wuz

Laravel package for WhatsApp device management via [WuzAPI](https://github.com/asternic/wuzapi) with multi-tenant, multi-device support.

## Installation

```bash
composer require jordanmiguel/laravel-whatsapp-wuz
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag="laravel-whatsapp-wuz-config"
php artisan vendor:publish --tag="laravel-whatsapp-wuz-migrations"
php artisan migrate
```

## Configuration

Add to your `.env`:

```env
WUZ_ENABLED=true
WUZ_API_URL=http://localhost:8080
WUZ_ADMIN_TOKEN=your-admin-token
WUZ_DEFAULT_COUNTRY_CODE=55
WUZ_DOWNLOAD_MEDIA=false
```

Full config in `config/wuz.php` — table names, webhook path, and middleware are all customizable.

## Setup

Add the trait and interface to your tenant model (e.g., `Clinic`, `Organization`):

```php
use JordanMiguel\Wuz\Contracts\HasWuzDevices as HasWuzDevicesContract;
use JordanMiguel\Wuz\Traits\HasWuzDevices;

class Clinic extends Model implements HasWuzDevicesContract
{
    use HasWuzDevices;
}
```

## Usage

### Create a Device

```php
use JordanMiguel\Wuz\Actions\StoreDeviceAction;
use JordanMiguel\Wuz\Data\StoreDeviceData;

$device = app(StoreDeviceAction::class)->handle(
    tenant: $clinic,
    data: new StoreDeviceData(name: 'Reception Phone'),
    createdBy: auth()->id(),
);
```

The first device per tenant is automatically set as default.

### Check Device Status / Get QR Code

```php
use JordanMiguel\Wuz\Actions\GetDeviceStatusAction;

$status = app(GetDeviceStatusAction::class)->handle($device);
// $status->status   => 'connected' | 'qr' | 'disconnected'
// $status->qr_code  => base64 QR data (when status is 'qr')
```

### Send a Message

```php
use JordanMiguel\Wuz\Actions\SendMessageAction;
use JordanMiguel\Wuz\Data\SendMessageData;

$message = app(SendMessageAction::class)->handle($device, new SendMessageData(
    phone: '5511999999999',
    type: 'text',
    message: 'Hello from Laravel!',
));
```

Supported types: `text`, `image`, `video`, `document`, `button`.

### Disconnect / Delete

```php
use JordanMiguel\Wuz\Actions\DisconnectDeviceAction;
use JordanMiguel\Wuz\Actions\DeleteDeviceAction;

app(DisconnectDeviceAction::class)->handle($device);
app(DeleteDeviceAction::class)->handle($device); // promotes next device to default
```

## Notification Channel

Send WhatsApp messages via Laravel's notification system:

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

The notifiable model needs the `InteractsWithWuz` trait:

```php
use JordanMiguel\Wuz\Traits\InteractsWithWuz;

class ClientProfile extends Model
{
    use InteractsWithWuz, Notifiable;

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    public function resolveWuzTenant(): mixed
    {
        return $this->clinic;
    }
}
```

## Webhooks

Incoming WhatsApp events are received at `POST /api/wuz/webhook/{token}` (configurable). The package handles:

- **Message** — stores incoming messages, dispatches `MessageReceived`
- **Disconnected** — updates device state, dispatches `DeviceDisconnected`
- **LoggedOut** — clears JID, dispatches `DeviceDisconnected`

All callbacks dispatch `WebhookReceived` and are logged in `wuz_callback_logs`.

### Listening to Events

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

## Multi-Device

| Rule | Behavior |
|------|----------|
| Multiple devices per tenant | Supported |
| One default per tenant | Enforced via `is_default` flag |
| First device auto-default | Handled by `StoreDeviceAction` |
| Delete promotes next | Handled by `DeleteDeviceAction` |
| Explicit switch | `$tenant->setDefaultWuzDevice($device)` |
| Notification picks default | `WuzChannel` resolves via tenant |

## Facade

```php
use JordanMiguel\Wuz\Facades\Wuz;

$service = Wuz::make($device); // device-scoped WuzService
$admin = Wuz::admin();         // admin-scoped WuzService
```

## Testing

Mock the WuzAPI in your tests:

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
