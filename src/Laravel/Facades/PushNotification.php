<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use SwanFlutter\NotificationMaster\Message;

/**
 * Laravel Facade for the FirebasePush driver.
 *
 * @method static array                send(Message $message)
 * @method static array                sendToToken(string $token, string $title, string $body, array $data = [])
 * @method static array                sendToTopic(string $topic, string $title, string $body, array $data = [])
 * @method static array<string, array> sendToMany(array $tokens, Message $message)
 * @method static array                sendRaw(array $payload)
 * @method static static               dryRun(bool $enabled = true)
 * @method static static               withRetries(int $retries, int $delayMs = 500)
 *
 * @see \SwanFlutter\NotificationMaster\FirebasePush
 */
class PushNotification extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'push-notification';
    }
}
