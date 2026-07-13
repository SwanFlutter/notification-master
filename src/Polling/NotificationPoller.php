<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Polling;

use SwanFlutter\NotificationMaster\Contracts\PushNotificationInterface;
use SwanFlutter\NotificationMaster\Message;
use SwanFlutter\NotificationMaster\Polling\Store\PollStoreInterface;

/**
 * Polling-mode push notification dispatcher.
 *
 * Instead of sending notifications immediately, notifications are stored in a
 * `PollStoreInterface` backend (database, Redis, cache, …). Client devices
 * periodically call your API endpoint and receive any pending notifications
 * that have been queued for them.
 *
 * This is useful when:
 * - You cannot use FCM (firewalled environments, offline-first apps).
 * - You want a reliable "at least once" delivery guarantee backed by your own DB.
 * - You want to combine real-time FCM push with a polling fallback.
 *
 * ## Typical flow
 *
 * 1. **Queue** — your backend enqueues a notification:
 *    ```php
 *    $poller->enqueue('user-123', Message::create()->title('Hello')->body('World')->data(['key' => 'val']));
 *    ```
 *
 * 2. **Poll** — client device calls your endpoint, which invokes:
 *    ```php
 *    $notifications = $poller->poll('user-123');
 *    // returns array of serialised Message payloads
 *    ```
 *
 * 3. **Flush & Forward** — optionally forward pending notifications via FCM then
 *    mark them as delivered:
 *    ```php
 *    $results = $poller->flushAndSend('device-token', 'user-123', $fcmClient);
 *    ```
 */
final class NotificationPoller
{
    public function __construct(private readonly PollStoreInterface $store) {}

    // ── Enqueueing ────────────────────────────────────────────────────────────

    /**
     * Enqueue a notification for a recipient.
     *
     * @param string                  $recipientId  Arbitrary identifier (user ID, device token, …).
     * @param Message                 $message      The message to deliver.
     * @param \DateTimeInterface|null $deliverAt    Optional future delivery time.
     * @return string                               The generated notification ID.
     *
     * @example
     * ```php
     * $poller->enqueue('user-123', Message::create()->title('Hello')->body('World'));
     *
     * // Schedule for future delivery:
     * $poller->enqueue(
     *     recipientId: 'user-123',
     *     message: Message::create()->title('Reminder')->body('Your appointment is soon.'),
     *     deliverAt: new \DateTimeImmutable('+30 minutes'),
     * );
     * ```
     */
    public function enqueue(
        string $recipientId,
        Message $message,
        ?\DateTimeInterface $deliverAt = null,
    ): string {
        $id = $this->generateId();

        $this->store->push($recipientId, [
            'id'         => $id,
            'payload'    => $message->toArray(),
            'queued_at'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'deliver_at' => $deliverAt?->format(\DateTimeInterface::ATOM),
            'delivered'  => false,
        ]);

        return $id;
    }

    // ── Polling ───────────────────────────────────────────────────────────────

    /**
     * Retrieve all pending (undelivered) notifications for a recipient.
     * Marks each returned notification as delivered automatically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function poll(string $recipientId): array
    {
        $pending = $this->store->pending($recipientId);

        if ($pending === []) {
            return [];
        }

        $ids = array_column($pending, 'id');
        $this->store->markDelivered($recipientId, $ids);

        return $pending;
    }

    /**
     * Retrieve pending notifications without marking them delivered.
     * Useful for read-only inspection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function peek(string $recipientId): array
    {
        return $this->store->pending($recipientId);
    }

    // ── Flush & Forward ───────────────────────────────────────────────────────

    /**
     * Flush all pending notifications for a recipient, forward them via FCM,
     * and mark successfully sent ones as delivered.
     *
     * @return array<string, mixed> Map of notification ID → send result.
     */
    public function flushAndSend(
        string $deviceToken,
        string $recipientId,
        PushNotificationInterface $sender,
    ): array {
        $pending = $this->store->pending($recipientId);

        if ($pending === []) {
            return [];
        }

        $results = [];

        foreach ($pending as $item) {
            $notifId = $item['id'];

            try {
                $message = $this->hydrateMessage($item['payload'], $deviceToken);
                $result  = $sender->send($message);

                $this->store->markDelivered($recipientId, [$notifId]);

                $results[$notifId] = ['success' => true, 'response' => $result];
            } catch (\Throwable $e) {
                $results[$notifId] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // ── Queue Management ──────────────────────────────────────────────────────

    /**
     * Delete all pending notifications for a recipient (e.g. when they log out).
     */
    public function purge(string $recipientId): void
    {
        $this->store->purge($recipientId);
    }

    /**
     * Return the number of pending notifications for a recipient.
     */
    public function count(string $recipientId): int
    {
        return count($this->store->pending($recipientId));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Re-hydrate a stored payload array back into a Message object.
     *
     * @param array<string, mixed> $payload  Stored FCM message array.
     * @param string               $token    Device token to target.
     */
    private function hydrateMessage(array $payload, string $token): Message
    {
        $msg = Message::create()->toToken($token);

        if (isset($payload['notification']['title'])) {
            $msg = $msg->title($payload['notification']['title']);
        }
        if (isset($payload['notification']['body'])) {
            $msg = $msg->body($payload['notification']['body']);
        }
        if (isset($payload['notification']['image'])) {
            $msg = $msg->image($payload['notification']['image']);
        }
        if (!empty($payload['data'])) {
            $msg = $msg->data($payload['data']);
        }
        if (!empty($payload['android'])) {
            $msg = $msg->android($payload['android']);
        }
        if (!empty($payload['apns'])) {
            $msg = $msg->apns($payload['apns']);
        }
        if (!empty($payload['webpush'])) {
            $msg = $msg->webpush($payload['webpush']);
        }

        return $msg;
    }
}
