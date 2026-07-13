<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster;

use SwanFlutter\NotificationMaster\Exceptions\InvalidMessageException;

/**
 * Fluent builder for an FCM HTTP v1 message payload.
 *
 * Usage:
 * ```php
 * $message = Message::create()
 *     ->toToken('device-token')
 *     ->title('Hello')
 *     ->body('World')
 *     ->data(['key' => 'value'])
 *     ->android(['priority' => 'high'])
 *     ->ttl(3600);
 * ```
 *
 * Only one of `toToken()`, `toTopic()`, or `toCondition()` may be set at a time.
 * At minimum, either a notification (title/body) or a data payload must be provided.
 */
final class Message
{
    // ── Notification fields ───────────────────────────────────────────────────
    private ?string $title = null;

    private ?string $body = null;

    private ?string $image = null;

    // ── Target (mutually exclusive) ───────────────────────────────────────────
    private ?string $token = null;

    private ?string $topic = null;

    private ?string $condition = null;

    // ── Payload ───────────────────────────────────────────────────────────────
    /** @var array<string, string> */
    private array $data = [];

    // ── Platform-specific overrides ───────────────────────────────────────────
    /** @var array<string, mixed> */
    private array $android = [];

    /** @var array<string, mixed> */
    private array $apns = [];

    /** @var array<string, mixed> */
    private array $webpush = [];

    /** FCM Analytics label. */
    private ?string $analyticsLabel = null;

    /** Message TTL in seconds (applied to android + webpush). */
    private ?int $ttl = null;

    // ─────────────────────────────────────────────────────────────────────────

    private function __construct() {}

    /**
     * Static factory — preferred entry point.
     */
    public static function create(): self
    {
        return new self;
    }

    // ── Notification ──────────────────────────────────────────────────────────

    public function title(string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    public function body(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function image(string $url): self
    {
        $clone = clone $this;
        $clone->image = $url;

        return $clone;
    }

    // ── Target ────────────────────────────────────────────────────────────────

    /**
     * Route the message to a single device FCM registration token.
     */
    public function toToken(string $token): self
    {
        $clone = clone $this;
        $clone->token = $token;
        $clone->topic = null;
        $clone->condition = null;

        return $clone;
    }

    /**
     * Route the message to an FCM topic (without the '/topics/' prefix).
     */
    public function toTopic(string $topic): self
    {
        $clone = clone $this;
        $clone->topic = $topic;
        $clone->token = null;
        $clone->condition = null;

        return $clone;
    }

    /**
     * Route the message to a boolean topic condition expression.
     * Example: "'dogs' in topics && 'cats' in topics"
     */
    public function toCondition(string $condition): self
    {
        $clone = clone $this;
        $clone->condition = $condition;
        $clone->token = null;
        $clone->topic = null;

        return $clone;
    }

    // ── Data payload ──────────────────────────────────────────────────────────

    /**
     * Arbitrary key/value data payload.
     * All values are cast to strings as required by the FCM HTTP v1 spec.
     *
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): self
    {
        $clone = clone $this;
        $clone->data = array_map('strval', $data);

        return $clone;
    }

    // ── Platform overrides ────────────────────────────────────────────────────

    /**
     * Android-specific message configuration.
     *
     * Common fields: priority, collapse_key, ttl, restricted_package_name,
     * notification (channel_id, sound, icon, color, …).
     *
     * @param  array<string, mixed>  $config
     *
     * @see https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages#AndroidConfig
     */
    public function android(array $config): self
    {
        $clone = clone $this;
        $clone->android = $config;

        return $clone;
    }

    /**
     * Apple (APNs) specific configuration.
     *
     * @param  array<string, mixed>  $config
     *
     * @see https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages#ApnsConfig
     */
    public function apns(array $config): self
    {
        $clone = clone $this;
        $clone->apns = $config;

        return $clone;
    }

    /**
     * Web push (Webpush) specific configuration.
     *
     * @param  array<string, mixed>  $config
     *
     * @see https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages#WebpushConfig
     */
    public function webpush(array $config): self
    {
        $clone = clone $this;
        $clone->webpush = $config;

        return $clone;
    }

    /**
     * FCM Analytics label (max 50 chars, alphanumeric + underscores).
     */
    public function analyticsLabel(string $label): self
    {
        $clone = clone $this;
        $clone->analyticsLabel = $label;

        return $clone;
    }

    /**
     * Message time-to-live in seconds.
     * Shortcut that sets `ttl` inside both the Android and Webpush configs.
     */
    public function ttl(int $seconds): self
    {
        $clone = clone $this;
        $clone->ttl = $seconds;

        return $clone;
    }

    // ── Read-only getters (used by FirebasePush for multicast cloning) ─────────

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getTopic(): ?string
    {
        return $this->topic;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    // ── Serialisation ─────────────────────────────────────────────────────────

    /**
     * Serialise to an FCM HTTP v1 `message` object.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidMessageException
     */
    public function toArray(): array
    {
        $this->validate();

        $message = [];

        // Target
        if ($this->token !== null) {
            $message['token'] = $this->token;
        } elseif ($this->topic !== null) {
            $message['topic'] = $this->topic;
        } elseif ($this->condition !== null) {
            $message['condition'] = $this->condition;
        }

        // Notification block
        $notification = array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'image' => $this->image,
        ], fn (mixed $v): bool => $v !== null);

        if ($notification !== []) {
            $message['notification'] = $notification;
        }

        // Data
        if ($this->data !== []) {
            $message['data'] = $this->data;
        }

        // Platform configs — merge TTL if set
        $android = $this->android;
        $webpush = $this->webpush;

        if ($this->ttl !== null) {
            $android['ttl'] ??= $this->ttl.'s';
            $webpush['headers']['TTL'] ??= (string) $this->ttl;
        }

        if ($android !== []) {
            $message['android'] = $android;
        }
        if ($this->apns !== []) {
            $message['apns'] = $this->apns;
        }
        if ($webpush !== []) {
            $message['webpush'] = $webpush;
        }

        if ($this->analyticsLabel !== null) {
            $message['fcm_options'] = ['analytics_label' => $this->analyticsLabel];
        }

        return $message;
    }

    /**
     * Validate that the message has a target and at least one content block.
     *
     * @throws InvalidMessageException
     */
    public function validate(): void
    {
        if ($this->token === null && $this->topic === null && $this->condition === null) {
            throw new InvalidMessageException(
                'Message must have a target: call toToken(), toTopic(), or toCondition().'
            );
        }

        if (
            $this->title === null &&
            $this->body === null &&
            $this->image === null &&
            $this->data === []
        ) {
            throw new InvalidMessageException(
                'Message must contain at least one of: title, body, image, or data.'
            );
        }
    }
}
