<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Auth;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SwanFlutter\NotificationMaster\Exceptions\AuthenticationException;
use SwanFlutter\NotificationMaster\Exceptions\InvalidCredentialsException;

/**
 * Handles Google OAuth2 access token acquisition and in-memory caching
 * for Firebase Cloud Messaging (FCM) HTTP v1 API.
 *
 * Uses a signed JWT (RS256) to request a short-lived Bearer token
 * from the Google token endpoint.
 */
final class OAuthToken
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** Cached Bearer token string. */
    private ?string $cachedToken = null;

    /** Unix timestamp at which the cached token expires. */
    private int $expiresAt = 0;

    /**
     * @param array{
     *     client_email: string,
     *     private_key: string,
     *     project_id: string
     * } $serviceAccount Decoded Firebase service account JSON.
     *
     * @throws InvalidCredentialsException
     */
    public function __construct(private readonly array $serviceAccount)
    {
        foreach (['client_email', 'private_key', 'project_id'] as $field) {
            if (empty($this->serviceAccount[$field])) {
                throw new InvalidCredentialsException(
                    "Missing required field '{$field}' in service account credentials."
                );
            }
        }
    }

    /**
     * Returns the Firebase project ID from the service account.
     */
    public function getProjectId(): string
    {
        return $this->serviceAccount['project_id'];
    }

    /**
     * Returns a valid Bearer access token, refreshing it automatically
     * when it is within 60 seconds of expiry.
     *
     * @throws AuthenticationException
     */
    public function getAccessToken(): string
    {
        if ($this->cachedToken !== null && time() < ($this->expiresAt - 60)) {
            return $this->cachedToken;
        }

        return $this->fetchAccessToken();
    }

    /**
     * Forces a fresh token fetch from the Google token endpoint,
     * bypassing the in-memory cache.
     *
     * @throws AuthenticationException
     */
    public function refreshAccessToken(): string
    {
        $this->cachedToken = null;
        $this->expiresAt = 0;

        return $this->fetchAccessToken();
    }

    /**
     * Clears the in-memory token cache.
     */
    public function clearCache(): void
    {
        $this->cachedToken = null;
        $this->expiresAt = 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Builds and signs a JWT assertion, exchanges it for a Google access token,
     * caches the result, and returns the token string.
     *
     * @throws AuthenticationException
     */
    private function fetchAccessToken(): string
    {
        $now = time();

        $payload = [
            'iss' => $this->serviceAccount['client_email'],
            'sub' => $this->serviceAccount['client_email'],
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
            'scope' => self::SCOPE,
        ];

        try {
            $jwt = JWT::encode($payload, $this->serviceAccount['private_key'], 'RS256');
        } catch (\Throwable $e) {
            throw new AuthenticationException(
                'Failed to sign JWT assertion: '.$e->getMessage(),
                previous: $e,
            );
        }

        try {
            $client = new Client(['timeout' => 15]);
            $response = $client->post(self::TOKEN_URI, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);

            /** @var array{access_token?: string, expires_in?: int} $data */
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new AuthenticationException(
                'HTTP request to Google token endpoint failed: '.$e->getMessage(),
                previous: $e,
            );
        } catch (\JsonException $e) {
            throw new AuthenticationException(
                'Invalid JSON response from Google token endpoint.',
                previous: $e,
            );
        }

        if (empty($data['access_token'])) {
            throw new AuthenticationException('Google token endpoint returned no access_token.');
        }

        $this->cachedToken = $data['access_token'];
        $this->expiresAt = $now + (int) ($data['expires_in'] ?? 3600);

        return $this->cachedToken;
    }
}
