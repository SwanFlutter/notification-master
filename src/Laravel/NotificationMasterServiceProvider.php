<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use SwanFlutter\NotificationMaster\Contracts\PushNotificationInterface;
use SwanFlutter\NotificationMaster\FirebasePush;
use SwanFlutter\NotificationMaster\Laravel\Channels\FcmChannel;
use SwanFlutter\NotificationMaster\Laravel\Polling\CachePollStore;
use SwanFlutter\NotificationMaster\Polling\NotificationPoller;
use SwanFlutter\NotificationMaster\Polling\Store\ArrayPollStore;
use SwanFlutter\NotificationMaster\Polling\Store\PollStoreInterface;

/**
 * Laravel service provider for the notification-master package.
 *
 * Auto-discovered via the `extra.laravel.providers` key in `composer.json`.
 *
 * Registers:
 * - `push-notification` singleton (FirebasePush driver, bound to PushNotificationInterface)
 * - `PushNotification` Facade alias
 * - `fcm` Laravel notification channel
 * - `push-notification.poller` singleton (NotificationPoller for polling mode)
 *
 * Publishes:
 * - `config/push-notification.php` → vendor tag `push-notification-config`
 */
final class NotificationMasterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/push-notification.php',
            'push-notification',
        );

        // ── FCM driver ────────────────────────────────────────────────────────
        $this->app->singleton(FirebasePush::class, function ($app): FirebasePush {
            $credentials = $app['config']->get('push-notification.credentials');
            $retries     = (int) $app['config']->get('push-notification.retries', 1);
            $retryDelay  = (int) $app['config']->get('push-notification.retry_delay_ms', 500);
            $dryRun      = (bool) $app['config']->get('push-notification.dry_run', false);

            return (new FirebasePush($credentials))
                ->withRetries($retries, $retryDelay)
                ->dryRun($dryRun);
        });

        $this->app->alias(FirebasePush::class, 'push-notification');
        $this->app->bind(PushNotificationInterface::class, FirebasePush::class);

        // ── Poll store ────────────────────────────────────────────────────────
        $this->app->bind(PollStoreInterface::class, function ($app): PollStoreInterface {
            $driver = $app['config']->get('push-notification.poll_store', 'cache');

            if ($driver === 'array') {
                return new ArrayPollStore();
            }

            // Default: Laravel Cache adapter
            return new CachePollStore(
                $app->make(CacheRepository::class),
                (int) $app['config']->get('push-notification.poll_ttl', 86400),
            );
        });

        // ── Poller ────────────────────────────────────────────────────────────
        $this->app->singleton(NotificationPoller::class, function ($app): NotificationPoller {
            return new NotificationPoller($app->make(PollStoreInterface::class));
        });

        $this->app->alias(NotificationPoller::class, 'push-notification.poller');
    }

    public function boot(): void
    {
        // ── Config publishing ─────────────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../../config/push-notification.php' => config_path('push-notification.php'),
        ], 'push-notification-config');

        // ── Register 'fcm' notification channel ───────────────────────────────
        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $manager, $app): void {
            $manager->extend('fcm', fn($app): FcmChannel => new FcmChannel(
                $app->make(PushNotificationInterface::class)
            ));
        });
    }
}
