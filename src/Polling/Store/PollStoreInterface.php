<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Polling\Store;

/**
 * Contract for the backing store used by NotificationPoller.
 *
 * Implement this interface to use any storage backend
 * (Laravel Cache, Redis, Eloquent/database, in-memory for tests, …).
 *
 * @see \SwanFlutter\NotificationMaster\Polling\Store\ArrayPollStore   In-memory reference implementation.
 * @see \SwanFlutter\NotificationMaster\Laravel\Polling\CachePollStore Laravel Cache adapter.
 */
interface PollStoreInterface
{
    /**
     * Push a notification entry into the store for a recipient.
     *
     * @param array<string, mixed> $notification Fully formed notification entry (id, payload, …).
     */
    public function push(string $recipientId, array $notification): void;

    /**
     * Return all pending (undelivered) notifications for a recipient.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(string $recipientId): array;

    /**
     * Mark a list of notification IDs as delivered for a recipient.
     *
     * @param string[] $ids
     */
    public function markDelivered(string $recipientId, array $ids): void;

    /**
     * Delete all notifications (pending and delivered) for a recipient.
     */
    public function purge(string $recipientId): void;
}
