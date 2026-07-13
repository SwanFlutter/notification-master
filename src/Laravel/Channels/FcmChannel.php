<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Laravel\Channels;

use Illuminate\Notifications\Notification;
use SwanFlutter\NotificationMaster\Contracts\PushNotificationInterface;
use SwanFlutter\NotificationMaster\Message;

/**
 * Laravel notification channel for Firebase Cloud Messaging.
 *
 * Register via the `via()` method on your Laravel notification class:
 *
 * ```php
 * public function via(object $notifiable): array
 * {
 *     return ['fcm'];
 * }
 * ```
 *
 * Your notification class must implement a `toFcm()` method that returns
 * a `Message` instance:
 *
 * ```php
 * use SwanFlutter\NotificationMaster\Message;
 *
 * public function toFcm(object $notifiable): Message
 * {
 *     return Message::create()
 *         ->title('You have a new message')
 *         ->body('Tap to open')
 *         ->data(['route' => 'inbox']);
 * }
 * ```
 *
 * The channel resolves the device token via `routeNotificationFor('fcm')` if
 * no token/topic/condition is already set on the Message.
 */
final class FcmChannel
{
    public function __construct(private readonly PushNotificationInterface $push) {}

    /**
     * Send the notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var Message $message */
        $message = $notification->toFcm($notifiable);   // @phpstan-ignore-line

        // If the Message has no explicit target, resolve the FCM token from
        // the notifiable model via routeNotificationFor('fcm').
        if (
            $message->getToken() === null &&
            $message->getTopic() === null &&
            $message->getCondition() === null
        ) {
            $token = $notifiable->routeNotificationFor('fcm', $notification);

            if (!empty($token)) {
                $message = $message->toToken((string) $token);
            }
        }

        $this->push->send($message);
    }
}
