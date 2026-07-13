# swanflutter/notification-master

A modern, production-ready **PHP 8.2+ / Laravel** push notification package built on the **Firebase Cloud Messaging (FCM) HTTP v1 API**.

Supports real-time FCM delivery **and** a polling-mode fallback — all with a clean fluent API, typed exceptions, and first-class Laravel integration.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/swanflutter/notification-master.svg)](https://packagist.org/packages/swanflutter/notification-master)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
  - [Laravel Setup](#laravel-setup)
  - [Standalone PHP Setup](#standalone-php-setup)
- [Configuration](#configuration)
- [Usage — FCM Push](#usage--fcm-push)
  - [Send to a Device Token](#send-to-a-device-token)
  - [Send to a Topic](#send-to-a-topic)
  - [Send to a Condition](#send-to-a-condition)
  - [Multicast (Multiple Tokens)](#multicast-multiple-tokens)
  - [Advanced Message Builder](#advanced-message-builder)
  - [Raw Payload](#raw-payload)
  - [Dry-Run Mode](#dry-run-mode)
  - [Retry Configuration](#retry-configuration)
- [Usage — Laravel Notification Channel](#usage--laravel-notification-channel)
- [Usage — Laravel Facade](#usage--laravel-facade)
- [Usage — Polling Mode](#usage--polling-mode)
  - [How Polling Works](#how-polling-works)
  - [Enqueue a Notification](#enqueue-a-notification)
  - [Client Polling Endpoint](#client-polling-endpoint)
  - [Flush & Forward via FCM](#flush--forward-via-fcm)
  - [Custom Poll Store](#custom-poll-store)
- [Exception Handling](#exception-handling)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.2` |
| `guzzlehttp/guzzle` | `^7.9` |
| `firebase/php-jwt` | `^6.10` |
| Laravel (optional) | `^10.0 \| ^11.0 \| ^12.0` |

---

## Installation

```bash
composer require swanflutter/notification-master
```

### Laravel Setup

The package is **auto-discovered**. After installing, publish the config file:

```bash
php artisan vendor:publish --tag=push-notification-config
```

This creates `config/push-notification.php` in your application.

Set the path to your Firebase service-account JSON file in `.env`:

```dotenv
FIREBASE_CREDENTIALS=/absolute/path/to/service-account.json
```

> The service-account JSON is generated in the [Firebase Console](https://console.firebase.google.com/) under **Project Settings → Service Accounts → Generate New Private Key**.

### Standalone PHP Setup

No framework integration required — just instantiate `FirebasePush` directly:

```php
use SwanFlutter\NotificationMaster\FirebasePush;

$push = new FirebasePush('/path/to/service-account.json');

// or pass the decoded array directly:
$push = new FirebasePush([
    'type'         => 'service_account',
    'project_id'   => 'my-project',
    'private_key'  => '-----BEGIN RSA PRIVATE KEY-----...',
    'client_email' => 'firebase-adminsdk@my-project.iam.gserviceaccount.com',
]);
```

---

## Configuration

`config/push-notification.php` (after publishing):

```php
return [
    // Path to your Firebase service-account JSON (or the decoded array).
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),

    // Validate payload without sending to devices (staging/testing).
    'dry_run' => env('PUSH_DRY_RUN', false),

    // Automatic retries on transient errors (429 / 5xx). Set 0 to disable.
    'retries'        => env('PUSH_RETRIES', 1),
    'retry_delay_ms' => env('PUSH_RETRY_DELAY_MS', 500),

    // Polling mode store driver: 'cache' (default) or 'array' (in-memory).
    'poll_store' => env('PUSH_POLL_STORE', 'cache'),

    // TTL in seconds for the cache-backed poll store.
    'poll_ttl' => env('PUSH_POLL_TTL', 86400),
];
```

---

## Usage — FCM Push

### Send to a Device Token

```php
use SwanFlutter\NotificationMaster\FirebasePush;
use SwanFlutter\NotificationMaster\Message;

$push = new FirebasePush(config('push-notification.credentials'));

$push->sendToToken(token: 'device-registration-token', title: 'Hello', body: 'World');
```

### Send to a Topic

```php
$push->sendToTopic(topic: 'news', title: 'Breaking News', body: 'Something just happened.');
```

### Send to a Condition

```php
use SwanFlutter\NotificationMaster\Message;

$push->send(
    Message::create()
        ->toCondition("'sports' in topics || 'tech' in topics")
        ->title('Multi-topic alert')
        ->body('This goes to sports and tech subscribers.')
);
```

### Multicast (Multiple Tokens)

```php
$tokens = ['token-1', 'token-2', 'token-3'];

$results = $push->sendToMany($tokens, Message::create()
    ->title('Broadcast')
    ->body('Hello everyone!')
);

foreach ($results as $token => $result) {
    if ($result['success']) {
        echo "Sent to {$token}\n";
    } else {
        echo "Failed for {$token}: {$result['error']}\n";
    }
}
```

### Advanced Message Builder

```php
use SwanFlutter\NotificationMaster\Message;

$message = Message::create()
    ->toToken('device-token')
    ->title('Flash Sale!')
    ->body('50% off — today only.')
    ->image('https://cdn.example.com/banner.jpg')
    ->data([
        'route'      => 'shop',
        'product_id' => '42',
    ])
    ->ttl(3600)
    ->android([
        'priority'     => 'high',
        'notification' => [
            'channel_id' => 'promotions',
            'sound'      => 'default',
        ],
    ])
    ->apns([
        'payload' => [
            'aps' => [
                'sound' => 'default',
                'badge' => 1,
            ],
        ],
    ])
    ->webpush([
        'notification' => ['icon' => '/icon.png'],
    ])
    ->analyticsLabel('flash_sale_july');

$push->send($message);
```

### Raw Payload

Send any FCM-compatible payload directly, bypassing the builder:

```php
$push->sendRaw([
    'message' => [
        'token'        => 'device-token',
        'notification' => ['title' => 'Raw', 'body' => 'Payload'],
    ],
]);
```

### Dry-Run Mode

```php
// Messages are validated but NOT delivered.
$push->dryRun()->send($message);
```

### Retry Configuration

```php
// 3 retries, 1 second base delay (doubled each attempt).
$push->withRetries(3, 1000)->send($message);
```

---

## Usage — Laravel Notification Channel

Add `toFcm()` to your Laravel notification class:

```php
use Illuminate\Notifications\Notification;
use SwanFlutter\NotificationMaster\Message;

class OrderShipped extends Notification
{
    public function via(object $notifiable): array
    {
        return ['fcm'];
    }

    public function toFcm(object $notifiable): Message
    {
        return Message::create()
            ->title('Your order has shipped!')
            ->body("Order #{$this->order->id} is on its way.")
            ->data(['order_id' => (string) $this->order->id]);
    }
}
```

Your notifiable model must implement `routeNotificationForFcm()` if no target is set on the Message:

```php
// In your User model:
public function routeNotificationForFcm(): string
{
    return $this->fcm_token;
}
```

Then send it like any other Laravel notification:

```php
$user->notify(new OrderShipped($order));
```

---

## Usage — Laravel Facade

```php
use SwanFlutter\NotificationMaster\Laravel\Facades\PushNotification;
use SwanFlutter\NotificationMaster\Message;

PushNotification::sendToToken(token: 'device-token', title: 'Hi', body: 'Hello from facade!');

PushNotification::send(
    Message::create()->toTopic('alerts')->title('Alert')->body('Pay attention.')
);
```

---

## Usage — Polling Mode

Polling mode stores notifications server-side so that client devices can retrieve
them on demand via a regular HTTP call. No native push infrastructure required.

### How Polling Works

```
Backend                       Client Device
  │                                │
  │── enqueue(userId, message) ──▶ │  (notification stored)
  │                                │
  │◀── GET /api/notifications ──── │  (device polls every N seconds)
  │── poll(userId) ──────────────▶ │  (returns & marks as delivered)
```

### Enqueue a Notification

```php
use SwanFlutter\NotificationMaster\Polling\NotificationPoller;
use SwanFlutter\NotificationMaster\Message;

$poller = app(NotificationPoller::class);

$id = $poller->enqueue(
    recipientId: (string) $user->id,
    message: Message::create()
        ->title('New message')
        ->body('You have a new message from Alice.')
        ->data(['chat_id' => '99']),
);
```

Schedule delivery for a future time:

```php
$poller->enqueue(
    recipientId: (string) $user->id,
    message: $message,
    deliverAt: new \DateTimeImmutable('+30 minutes'),
);
```

### Client Polling Endpoint

```php
// routes/api.php
Route::middleware('auth:sanctum')->get('/notifications/poll', function (Request $request) {
    $poller        = app(\SwanFlutter\NotificationMaster\Polling\NotificationPoller::class);
    $notifications = $poller->poll((string) $request->user()->id);

    return response()->json(['notifications' => $notifications]);
});
```

### Flush & Forward via FCM

Deliver all queued notifications immediately via FCM and mark them delivered:

```php
use SwanFlutter\NotificationMaster\Contracts\PushNotificationInterface;
use SwanFlutter\NotificationMaster\Polling\NotificationPoller;

$results = app(NotificationPoller::class)->flushAndSend(
    deviceToken: $user->fcm_token,
    recipientId: (string) $user->id,
    sender:      app(PushNotificationInterface::class),
);
```

### Custom Poll Store

Implement `PollStoreInterface` to use your own storage backend (Eloquent, Redis, SQS, …):

```php
use SwanFlutter\NotificationMaster\Polling\Store\PollStoreInterface;

class DatabasePollStore implements PollStoreInterface
{
    public function push(string $recipientId, array $notification): void
    {
        DB::table('push_notifications')->insert([
            'recipient_id' => $recipientId,
            'payload'      => json_encode($notification),
            'delivered'    => false,
            'created_at'   => now(),
        ]);
    }

    public function pending(string $recipientId): array
    {
        return DB::table('push_notifications')
            ->where('recipient_id', $recipientId)
            ->where('delivered', false)
            ->get()
            ->map(fn($row) => json_decode($row->payload, true))
            ->toArray();
    }

    public function markDelivered(string $recipientId, array $ids): void
    {
        DB::table('push_notifications')
            ->where('recipient_id', $recipientId)
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id'))"), $ids)
            ->update(['delivered' => true]);
    }

    public function purge(string $recipientId): void
    {
        DB::table('push_notifications')
            ->where('recipient_id', $recipientId)
            ->delete();
    }
}
```

Bind it in your `AppServiceProvider`:

```php
use SwanFlutter\NotificationMaster\Polling\Store\PollStoreInterface;

$this->app->bind(PollStoreInterface::class, DatabasePollStore::class);
```

---

## Exception Handling

All exceptions extend `SwanFlutter\NotificationMaster\Exceptions\PushNotificationException`.

| Exception | When thrown |
|---|---|
| `InvalidCredentialsException` | Service-account file missing, unreadable, or malformed |
| `AuthenticationException` | JWT signing or Google OAuth token fetch failed |
| `InvalidMessageException` | Message missing a target or content |
| `SendingFailedException` | FCM HTTP request failed (network / FCM error); includes `getFcmResponse()` |

```php
use SwanFlutter\NotificationMaster\Exceptions\SendingFailedException;
use SwanFlutter\NotificationMaster\Exceptions\PushNotificationException;

try {
    $push->send($message);
} catch (SendingFailedException $e) {
    // FCM-level error
    logger()->error('FCM error', [
        'message' => $e->getMessage(),
        'fcm'     => $e->getFcmResponse(),
    ]);
} catch (PushNotificationException $e) {
    // Any other package error (credentials, auth, validation)
    logger()->error('Push error: ' . $e->getMessage());
}
```

---

## Testing

```bash
composer test
```

For unit tests, use `ArrayPollStore` as a lightweight in-memory poll store:

```php
use SwanFlutter\NotificationMaster\Polling\NotificationPoller;
use SwanFlutter\NotificationMaster\Polling\Store\ArrayPollStore;
use SwanFlutter\NotificationMaster\Message;

$store  = new ArrayPollStore();
$poller = new NotificationPoller($store);

$poller->enqueue('user-1', Message::create()->toToken('t')->title('Hi')->body('Test'));

$pending = $poller->poll('user-1');
assert(count($pending) === 1);
assert($poller->count('user-1') === 0); // marked delivered
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full history of changes.

---

## License

This package is open-source software licensed under the [MIT License](LICENSE).
