<?php

declare(strict_types=1);

namespace Allow2\Tests;

use Allow2\Cache\ArrayCache;
use Allow2\CacheInterface;
use Allow2\Exceptions\ApiException;
use Allow2\Exceptions\TokenExpiredException;
use Allow2\Models\OAuthTokens;
use Allow2\Storage\PdoTokenStorage;
use Allow2\TokenStorageInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OAuth2 authorization flow handling within the Allow2 client.
 *
 * Focuses on: authorize URL generation, code exchange, token storage,
 * token refresh, and error handling.
 */
final class OAuth2ManagerTest extends TestCase
{
    private const CLIENT_ID = 'oauth-test-client';
    private const CLIENT_SECRET = 'oauth-test-secret';
    private const API_HOST = 'https://api.allow2.com';
    private const SERVICE_HOST = 'https://service.allow2.com';

    private MockHttpClient $http;
    private TokenStorageInterface $tokenStorage;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $pdo = new \PDO('sqlite::memory:');
        $this->tokenStorage = new PdoTokenStorage($pdo);
        $this->cache = new ArrayCache();
    }

    private function createClient(): \Allow2\Allow2Client
    {
        return new \Allow2\Allow2Client(
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            tokenStorage: $this->tokenStorage,
            cache: $this->cache,
            httpClient: $this->http,
            apiHost: self::API_HOST,
            serviceHost: self::SERVICE_HOST,
        );
    }

    // --- Authorization URL ---

    #[Test]
    public function authorizeUrlContainsAllRequiredParams(): void
    {
        $client = $this->createClient();

        $url = $client->getAuthorizeUrl('user-42', 'https://myapp.com/callback', 'csrf-token');

        $parsed = parse_url($url);
        $this->assertNotNull($parsed);

        parse_str($parsed['query'] ?? '', $params);

        $this->assertSame(self::CLIENT_ID, $params['client_id'] ?? null);
        $this->assertStringContainsString('myapp.com/callback', $params['redirect_uri'] ?? '');
        $this->assertSame('user-42', $params['user_id'] ?? null);
        $this->assertSame('csrf-token', $params['state'] ?? null);
        $this->assertSame('code', $params['response_type'] ?? null);
    }

    // --- Code Exchange ---

    #[Test]
    public function exchangeCodeSendsCorrectPayload(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, [
            'access_token' => 'at_abc',
            'refresh_token' => 'rt_xyz',
            'expires_in' => 3600,
        ]);

        $client->exchangeCode('user-1', 'code-123', 'https://myapp.com/callback');

        $request = $this->http->getLastRequest();

        $this->assertSame('POST', $request['method']);
        $this->assertStringContainsString(self::CLIENT_ID, json_encode($request['data']));
    }

    #[Test]
    public function exchangeCodeReturnsFreshTokens(): void
    {
        $client = $this->createClient();

        $before = time();

        $this->http->addResponse(200, [
            'access_token' => 'at_fresh',
            'refresh_token' => 'rt_fresh',
            'expires_in' => 7200,
        ]);

        $tokens = $client->exchangeCode('user-1', 'code-xyz', 'https://myapp.com/callback');

        $after = time();

        $this->assertSame('at_fresh', $tokens->accessToken);
        $this->assertSame('rt_fresh', $tokens->refreshToken);
        $this->assertGreaterThanOrEqual($before + 7200, $tokens->expiresAt);
        $this->assertLessThanOrEqual($after + 7200, $tokens->expiresAt);
    }

    #[Test]
    public function exchangeCodePersistsTokensInStorage(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, [
            'access_token' => 'at_stored',
            'refresh_token' => 'rt_stored',
            'expires_in' => 3600,
        ]);

        $client->exchangeCode('user-1', 'code-abc', 'https://myapp.com/callback');

        $stored = $this->tokenStorage->retrieve('user-1');
        $this->assertNotNull($stored);
        $this->assertSame('at_stored', $stored->accessToken);
    }

    #[Test]
    public function exchangeCodeWithInvalidCodeThrows(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(400, [
            'error' => 'invalid_grant',
            'error_description' => 'Invalid authorization code',
        ]);

        $this->expectException(\Exception::class);
        $client->exchangeCode('user-1', 'bad-code', 'https://myapp.com/callback');
    }

    // --- Token Refresh ---

    #[Test]
    public function automaticTokenRefreshOnExpiredToken(): void
    {
        $client = $this->createClient();

        // Store expired tokens
        $this->tokenStorage->store('user-1', new OAuthTokens(
            accessToken: 'expired_at',
            refreshToken: 'still_valid_rt',
            expiresAt: time() - 600,
        ));

        // Response for refresh
        $this->http->addResponse(200, [
            'access_token' => 'refreshed_at',
            'refresh_token' => 'refreshed_rt',
            'expires_in' => 3600,
        ]);

        // Response for the check call
        $this->http->addResponse(200, [
            'allowed' => true,
            'activities' => [],
        ]);

        $result = $client->check('user-1', [1]);
        $this->assertTrue($result->allowed);

        // Verify refreshed tokens are stored
        $stored = $this->tokenStorage->retrieve('user-1');
        $this->assertSame('refreshed_at', $stored->accessToken);
        $this->assertSame('refreshed_rt', $stored->refreshToken);
    }

    #[Test]
    public function tokenRefreshFailureThrowsOrClearsTokens(): void
    {
        $client = $this->createClient();

        // Store expired tokens
        $this->tokenStorage->store('user-1', new OAuthTokens(
            accessToken: 'expired_at',
            refreshToken: 'also_expired_rt',
            expiresAt: time() - 600,
        ));

        // Refresh fails
        $this->http->addResponse(401, [
            'error' => 'invalid_grant',
            'error_description' => 'Refresh token expired',
        ]);

        $this->expectException(\Exception::class);
        $client->check('user-1', [1]);
    }
}
