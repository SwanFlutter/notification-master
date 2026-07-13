<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Contracts;

use SwanFlutter\NotificationMaster\Message;

/**
 * Contract that all push notification drivers must implement.
 */
interface PushNotificationInterface
{
    /**
     * Send a single notification message.
     *
     * @return array<string, mixed> FCM response payload.
     */
    public function send(Message $message): array;

    /**
     * Convenience helper — send to a single device token.
     *
     * @param  array<string, string>  $data  Optional data payload (string key→value).
     * @return array<string, mixed>
     *
     * @example
     * ```php
     * $push->sendToToken(token: 'device-token', title: 'Hello', body: 'World');
     * $push->sendToToken(token: 'device-token', title: 'Hello', body: 'World', data: ['key' => 'value']);
     * ```
     */
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = [],
    ): array;

    /**
     * Convenience helper — send to an FCM topic.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     *
     * @example
     * ```php
     * $push->sendToTopic(topic: 'news', title: 'Breaking News', body: 'Something just happened.');
     * $push->sendToTopic(topic: 'news', title: 'Alert', body: 'Details here.', data: ['url' => 'https://example.com']);
     * ```
     */
    public function sendToTopic(
        string $topic,
        string $title,
        string $body,
        array $data = [],
    ): array;

    /**
     * Send the same message to multiple device tokens (multicast).
     *
     * Returns a map of token → result array.
     * Each result has the shape:
     *   ['success' => true,  'response' => array]
     *   ['success' => false, 'error'    => string]
     *
     * @param  string[]  $tokens
     * @return array<string, array<string, mixed>>
     */
    public function sendToMany(array $tokens, Message $message): array;

    /**
     * Send a raw FCM payload without any wrapping.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendRaw(array $payload): array;
}
