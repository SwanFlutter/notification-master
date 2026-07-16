<?php

declare(strict_types=1);

namespace SwanFlutter\NotificationMaster\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SwanFlutter\NativeJwt\JWT;
use SwanFlutter\NativeJwt\Key;
use SwanFlutter\NotificationMaster\Auth\OAuthToken;
use SwanFlutter\NotificationMaster\Exceptions\AuthenticationException;
use SwanFlutter\NotificationMaster\Exceptions\InvalidCredentialsException;

final class OAuthTokenTest extends TestCase
{
    private string $privateKey = '';

    private string $publicKey = '';

    private array $serviceAccount;

    protected function setUp(): void
    {
        $this->privateKey = (string) file_get_contents(__DIR__.'/Fixtures/service-account.key.pem');
        $this->publicKey = (string) file_get_contents(__DIR__.'/Fixtures/service-account.key.pub');

        $this->serviceAccount = [
            'client_email' => 'firebase-adminsdk@my-project.iam.gserviceaccount.com',
            'private_key' => $this->privateKey,
            'private_key_id' => 'key-id-123',
            'project_id' => 'my-project',
        ];
    }

    private function mockClient(MockHandler $mock): Client
    {
        return new Client(['handler' => HandlerStack::create($mock)]);
    }

    public function test_constructor_throws_on_missing_client_email(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        new OAuthToken([
            'private_key' => $this->privateKey,
            'project_id' => 'my-project',
        ]);
    }

    public function test_constructor_throws_on_missing_private_key(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        new OAuthToken([
            'client_email' => 'x@y.com',
            'project_id' => 'my-project',
        ]);
    }

    public function test_constructor_throws_on_missing_project_id(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        new OAuthToken([
            'client_email' => 'x@y.com',
            'private_key' => $this->privateKey,
        ]);
    }

    public function test_get_project_id_returns_service_account_project(): void
    {
        $oauth = new OAuthToken($this->serviceAccount);

        $this->assertSame('my-project', $oauth->getProjectId());
    }

    public function test_get_access_token_returns_and_caches_token(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok-abc',
                'expires_in' => 3600,
            ])),
        ]);

        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient(new Client(['handler' => $stack]));

        $first = $oauth->getAccessToken();
        $second = $oauth->getAccessToken();

        $this->assertSame('tok-abc', $first);
        $this->assertSame('tok-abc', $second);
        $this->assertCount(1, $history);
    }

    public function test_get_access_token_refreshes_when_near_expiry(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok-1',
                'expires_in' => 30,
            ])),
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok-2',
                'expires_in' => 3600,
            ])),
        ]);

        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient($this->mockClient($mock));

        $this->assertSame('tok-1', $oauth->getAccessToken());
        $this->assertSame('tok-2', $oauth->getAccessToken());
        $this->assertSame(0, $mock->count());
    }

    public function test_refresh_access_token_bypasses_cache(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok-a',
                'expires_in' => 3600,
            ])),
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok-b',
                'expires_in' => 3600,
            ])),
        ]);

        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient($this->mockClient($mock));

        $this->assertSame('tok-a', $oauth->getAccessToken());
        $this->assertSame('tok-b', $oauth->refreshAccessToken());
        $this->assertSame(0, $mock->count());
    }

    public function test_clear_cache_forces_refetch(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok-x',
                'expires_in' => 3600,
            ])),
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok-y',
                'expires_in' => 3600,
            ])),
        ]);

        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient($this->mockClient($mock));

        $oauth->getAccessToken();
        $oauth->clearCache();
        $this->assertSame('tok-y', $oauth->getAccessToken());
        $this->assertSame(0, $mock->count());
    }

    public function test_signed_assertion_is_valid_rs256_jwt(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok',
                'expires_in' => 3600,
            ])),
        ]);

        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient(new Client(['handler' => $stack]));

        $oauth->getAccessToken();

        $request = $history[0]['request'];
        $params = (string) $request->getBody();
        parse_str($params, $body);

        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $body['grant_type']);

        $decoded = JWT::decode($body['assertion'], new Key($this->publicKey, 'RS256'));

        $this->assertSame($this->serviceAccount['client_email'], $decoded['iss']);
        $this->assertSame($this->serviceAccount['client_email'], $decoded['sub']);
        $this->assertSame('https://oauth2.googleapis.com/token', $decoded['aud']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $decoded['scope']);
        $this->assertGreaterThanOrEqual(time() - 2, $decoded['iat']);
        $this->assertLessThanOrEqual(time() + 2, $decoded['iat']);
        $this->assertSame($decoded['iat'] + 3600, $decoded['exp']);
    }

    public function test_request_is_posted_to_google_token_uri(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'access_token' => 'tok',
                'expires_in' => 3600,
            ])),
        ]);

        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient(new Client(['handler' => $stack]));

        $oauth->getAccessToken();

        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame(
            'https://oauth2.googleapis.com/token',
            (string) $history[0]['request']->getUri()
        );
    }

    public function test_throws_authentication_exception_on_http_error(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'server error'),
        ]);

        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient($this->mockClient($mock));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/HTTP request to Google token endpoint failed/');

        $oauth->getAccessToken();
    }

    public function test_throws_authentication_exception_on_invalid_json(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'not-json'),
        ]);

        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient($this->mockClient($mock));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON response/');

        $oauth->getAccessToken();
    }

    public function test_throws_authentication_exception_when_no_access_token(): void
    {
        $mock = new MockHandler([
            new Response(200, [], (string) json_encode(['expires_in' => 3600])),
        ]);

        $oauth = new OAuthToken($this->serviceAccount);
        $oauth->setClient($this->mockClient($mock));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/returned no access_token/');

        $oauth->getAccessToken();
    }

    public function test_throws_authentication_exception_on_signing_failure(): void
    {
        $badAccount = $this->serviceAccount;
        $badAccount['private_key'] = 'not-a-valid-private-key';

        $oauth = new OAuthToken($badAccount);
        $oauth->setClient($this->mockClient(new MockHandler([])));

        set_error_handler(static fn () => true);

        try {
            $this->expectException(AuthenticationException::class);
            $this->expectExceptionMessageMatches('/Failed to sign JWT assertion/');

            $oauth->getAccessToken();
        } finally {
            restore_error_handler();
        }
    }
}
