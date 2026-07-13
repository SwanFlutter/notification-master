<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SwanFlutter\NotificationMaster\Auth\OAuthToken;
use SwanFlutter\NotificationMaster\Contracts\PushNotificationInterface;
use SwanFlutter\NotificationMaster\Exceptions\InvalidCredentialsException;
use SwanFlutter\NotificationMaster\Exceptions\SendingFailedException;

/**
 * Firebase Cloud Messaging (FCM) HTTP v1 client.
 *
 * Supports:
 * - Single device (token), topic, and condition targeting
 * - Multicast (sendToMany) via sequential token sends
 * - Automatic OAuth2 token refresh
 * - Configurable retry on transient errors (429 / 5xx)
 * - Dry-run (validate_only) mode
 *
 * @example
 * ```php
 * use SwanFlutter\NotificationMaster\FirebasePush;
 * use SwanFlutter\NotificationMaster\Message;
 *
 * $push = new FirebasePush('/path/to/service-account.json');
 *
 * $push->sendToToken(token: 'device-token', title: 'Hello', body: 'World');
 *
 * $push->sendToTopic(topic: 'news', title: 'Breaking', body: 'Something happened');
 *
 * $push->send(
 *     Message::create()
 *         ->toTopic('news')
 *         ->title('Breaking')
 *         ->body('Something happened')
 *         ->ttl(3600)
 * );
 * ```
 */
final class FirebasePush implements PushNotificationInterface
{
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /** Retry on these HTTP status codes (transient). */
    private const RETRYABLE_CODES = [429, 500, 502, 503, 504];

    private readonly OAuthToken $auth;
    private readonly Client $http;
    private readonly string $endpoint;

    /** When true, messages are validated but not actually delivered. */
    private bool $dryRun = false;

    /** Number of automatic retries on transient failures (0 = no retry). */
    private int $retries = 1;

    /** Base delay between retries in milliseconds. */
    private int $retryDelayMs = 500;

    /**
     * @param string|array<string, mixed> $serviceAccount
     *   Path to a Firebase service-account JSON file, or the decoded array.
     *
     * @throws InvalidCredentialsException
     */
    public function __construct(string|array $serviceAccount)
    {
        $credentials    = $this->resolveCredentials($serviceAccount);
        $this->auth     = new OAuthToken($credentials);
        $this->http     = new Client(['timeout' => 30, 'connect_timeout' => 10]);
        $this->endpoint = sprintf(self::FCM_ENDPOINT, $this->auth->getProjectId());
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    /**
     * Enable/disable FCM dry-run (validate_only) mode.
     * In dry-run mode messages are validated but not sent to devices.
     */
    public function dryRun(bool $enabled = true): self
    {
        $clone         = clone $this;
        $clone->dryRun = $enabled;
        return $clone;
    }

    /**
     * Set the number of automatic retries on transient HTTP errors (429/5xx).
     * Default: 1.  Set to 0 to disable retries.
     */
    public function withRetries(int $retries, int $delayMs = 500): self
    {
        $clone               = clone $this;
        $clone->retries      = max(0, $retries);
        $clone->retryDelayMs = max(0, $delayMs);
        return $clone;
    }

    // ── PushNotificationInterface ─────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function send(Message $message): array
    {
        return $this->sendRaw(['message' => $message->toArray()]);
    }

    /**
     * {@inheritdoc}
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
    ): array {
        return $this->send(
            Message::create()
                ->toToken($token)
                ->title($title)
                ->body($body)
                ->data($data)
        );
    }

    /**
     * {@inheritdoc}
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
    ): array {
        return $this->send(
            Message::create()
                ->toTopic($topic)
                ->title($title)
                ->body($body)
                ->data($data)
        );
    }

    /**
     * {@inheritdoc}
     *
     * Sends sequentially because FCM HTTP v1 has no batch endpoint.
     * Results are indexed by token. Failures per-token are captured rather
     * than aborting the entire batch.
     */
    public function sendToMany(array $tokens, Message $message): array
    {
        $results = [];

        foreach ($tokens as $token) {
            try {
                $targeted        = $message->toToken($token);
                $results[$token] = [
                    'success'  => true,
                    'response' => $this->send($targeted),
                ];
            } catch (SendingFailedException $e) {
                $results[$token] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                    'fcm'     => $e->getFcmResponse(),
                ];
            }
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function sendRaw(array $payload): array
    {
        if ($this->dryRun) {
            $payload['validate_only'] = true;
        }

        return $this->postWithRetry($payload);
    }

    // ── Internal HTTP ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws SendingFailedException
     */
    private function postWithRetry(array $payload): array
    {
        $attempts = 0;
        $maxTries = $this->retries + 1;

        do {
            $attempts++;
            try {
                return $this->doPost($payload);
            } catch (SendingFailedException $e) {
                $isRetryable = $this->isRetryableCode($e->getCode());

                if ($attempts >= $maxTries || !$isRetryable) {
                    throw $e;
                }

                // Exponential back-off
                $waitMs = $this->retryDelayMs * (2 ** ($attempts - 1));
                usleep($waitMs * 1000);
            }
        } while ($attempts < $maxTries);

        // Unreachable, but satisfies static analysis.
        throw new SendingFailedException('Unexpected retry loop exit.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws SendingFailedException
     */
    private function doPost(array $payload): array
    {
        try {
            $response = $this->http->post($this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->auth->getAccessToken(),
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => $payload,
            ]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return $decoded;
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $rawBody    = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();

            throw new SendingFailedException(
                message:     "FCM request failed ({$statusCode}): {$rawBody}",
                code:        $statusCode,
                previous:    $e,
                fcmResponse: $rawBody,
            );
        } catch (\JsonException $e) {
            throw new SendingFailedException(
                message:  'Invalid JSON in FCM response: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    private function isRetryableCode(int $code): bool
    {
        return in_array($code, self::RETRYABLE_CODES, strict: true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param string|array<string, mixed> $serviceAccount
     * @return array<string, mixed>
     * @throws InvalidCredentialsException
     */
    private function resolveCredentials(string|array $serviceAccount): array
    {
        if (is_array($serviceAccount)) {
            return $serviceAccount;
        }

        if (!is_file($serviceAccount)) {
            throw new InvalidCredentialsException(
                "Service account file not found: {$serviceAccount}"
            );
        }

        $raw = file_get_contents($serviceAccount);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCredentialsException(
                'Service account file contains invalid JSON.',
                previous: $e,
            );
        }

        return $decoded;
    }
}
