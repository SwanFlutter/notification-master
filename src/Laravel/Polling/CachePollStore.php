<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Laravel\Polling;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use SwanFlutter\NotificationMaster\Polling\Store\PollStoreInterface;

/**
 * Laravel Cache-backed implementation of PollStoreInterface.
 *
 * Notifications are stored in your application's default cache driver
 * (Redis, Memcached, database, file, …) and automatically expire after the
 * configured TTL.
 *
 * @example
 * ```php
 * use SwanFlutter\NotificationMaster\Laravel\Polling\CachePollStore;
 * use SwanFlutter\NotificationMaster\Polling\NotificationPoller;
 *
 * $store  = new CachePollStore(cache(), ttl: 3600);
 * $poller = new NotificationPoller($store);
 * $poller->enqueue('user-1', $message);
 * ```
 */
final class CachePollStore implements PollStoreInterface
{
    private const KEY_PREFIX = 'push_poll_';

    public function __construct(
        private readonly CacheRepository $cache,
        /** Number of seconds before a recipient's queue expires automatically. */
        private readonly int $ttl = 86400,
    ) {}

    public function push(string $recipientId, array $notification): void
    {
        $key = $this->key($recipientId);
        $items = $this->cache->get($key, []);

        $items[] = $notification;

        $this->cache->put($key, $items, $this->ttl);
    }

    public function pending(string $recipientId): array
    {
        $items = $this->cache->get($this->key($recipientId), []);

        return array_values(
            array_filter($items, fn (array $n): bool => $n['delivered'] === false)
        );
    }

    public function markDelivered(string $recipientId, array $ids): void
    {
        $key = $this->key($recipientId);
        $items = $this->cache->get($key, []);

        if ($items === []) {
            return;
        }

        $idSet = array_flip($ids);

        foreach ($items as &$notification) {
            if (isset($idSet[$notification['id']])) {
                $notification['delivered'] = true;
            }
        }
        unset($notification);

        $this->cache->put($key, $items, $this->ttl);
    }

    public function purge(string $recipientId): void
    {
        $this->cache->forget($this->key($recipientId));
    }

    private function key(string $recipientId): string
    {
        return self::KEY_PREFIX.$recipientId;
    }
}
