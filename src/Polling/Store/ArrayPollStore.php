<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Polling\Store;

/**
 * In-memory `PollStoreInterface` implementation.
 *
 * Useful for unit tests and local development.
 * Data is NOT persisted across requests.
 *
 * @example
 * ```php
 * use SwanFlutter\NotificationMaster\Polling\Store\ArrayPollStore;
 * use SwanFlutter\NotificationMaster\Polling\NotificationPoller;
 *
 * $store  = new ArrayPollStore();
 * $poller = new NotificationPoller($store);
 * ```
 */
final class ArrayPollStore implements PollStoreInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $store = [];

    public function push(string $recipientId, array $notification): void
    {
        $this->store[$recipientId][] = $notification;
    }

    public function pending(string $recipientId): array
    {
        return array_values(
            array_filter(
                $this->store[$recipientId] ?? [],
                fn (array $n): bool => $n['delivered'] === false,
            )
        );
    }

    public function markDelivered(string $recipientId, array $ids): void
    {
        if (! isset($this->store[$recipientId])) {
            return;
        }

        $idSet = array_flip($ids);

        foreach ($this->store[$recipientId] as &$notification) {
            if (isset($idSet[$notification['id']])) {
                $notification['delivered'] = true;
            }
        }
        unset($notification);
    }

    public function purge(string $recipientId): void
    {
        unset($this->store[$recipientId]);
    }

    /**
     * Return all stored notifications for a recipient (including delivered).
     * Useful for assertions in tests.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(string $recipientId): array
    {
        return $this->store[$recipientId] ?? [];
    }
}
