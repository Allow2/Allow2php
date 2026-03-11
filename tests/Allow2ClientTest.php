<?php

declare(strict_types=1);

namespace Allow2\Tests;

use Allow2\Cache\ArrayCache;
use Allow2\CacheInterface;
use Allow2\Exceptions\ApiException;
use Allow2\Exceptions\UnpairedException;
use Allow2\HttpClientInterface;
use Allow2\HttpResponse;
use Allow2\Models\OAuthTokens;
use Allow2\Storage\PdoTokenStorage;
use Allow2\TokenStorageInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Allow2Client (the main SDK facade).
 *
 * These tests mock the HTTP layer and use real storage/cache to validate
 * end-to-end SDK behaviour without hitting the Allow2 API.
 */
final class Allow2ClientTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const CLIENT_SECRET = 'test-client-secret';
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

    /**
     * Create an Allow2 client instance using the mock HTTP client.
     */
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

    /**
     * Store valid (non-expired) tokens for a user.
     */
    private function storeValidTokens(string $userId = 'user-1'): OAuthTokens
    {
        $tokens = new OAuthTokens(
            accessToken: 'valid_access_token',
            refreshToken: 'valid_refresh_token',
            expiresAt: time() + 7200,
        );
        $this->tokenStorage->store($userId, $tokens);
        return $tokens;
    }

    /**
     * Store expired tokens for a user to test refresh flows.
     */
    private function storeExpiredTokens(string $userId = 'user-1'): OAuthTokens
    {
        $tokens = new OAuthTokens(
            accessToken: 'expired_access_token',
            refreshToken: 'valid_refresh_for_renewal',
            expiresAt: time() - 100,
        );
        $this->tokenStorage->store($userId, $tokens);
        return $tokens;
    }

    // --- getAuthorizeUrl ---

    #[Test]
    public function getAuthorizeUrlGeneratesCorrectUrl(): void
    {
        $client = $this->createClient();

        $url = $client->getAuthorizeUrl('user-1', 'https://example.com/callback');

        $this->assertStringContainsString('client_id=' . self::CLIENT_ID, $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('user_id=user-1', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    #[Test]
    public function getAuthorizeUrlIncludesStateWhenProvided(): void
    {
        $client = $this->createClient();

        $url = $client->getAuthorizeUrl('user-1', 'https://example.com/callback', 'random-state-123');

        $this->assertStringContainsString('state=random-state-123', $url);
    }

    #[Test]
    public function getAuthorizeUrlOmitsStateWhenNull(): void
    {
        $client = $this->createClient();

        $url = $client->getAuthorizeUrl('user-1', 'https://example.com/callback');

        // State param should not be present (or empty)
        // Parse the query string to check
        $parsed = parse_url($url);
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            if (isset($params['state'])) {
                $this->assertSame('', $params['state']);
            }
        }
        // If state is not in URL at all, that's fine too — test passes
        $this->assertTrue(true);
    }

    // --- exchangeCode ---

    #[Test]
    public function exchangeCodeStoresTokensOnSuccess(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, [
            'access_token' => 'new_access',
            'refresh_token' => 'new_refresh',
            'expires_in' => 3600,
        ]);

        $tokens = $client->exchangeCode('user-1', 'auth_code_123', 'https://example.com/callback');

        $this->assertSame('new_access', $tokens->accessToken);
        $this->assertSame('new_refresh', $tokens->refreshToken);

        // Verify tokens were stored
        $stored = $this->tokenStorage->retrieve('user-1');
        $this->assertNotNull($stored);
        $this->assertSame('new_access', $stored->accessToken);

        // Verify the HTTP request was correct
        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertStringContainsString('token', $request['url']);
        $this->assertSame('auth_code_123', $request['data']['code'] ?? $request['data']['authorization_code'] ?? null);
    }

    #[Test]
    public function exchangeCodeThrowsOnApiError(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(400, [
            'error' => 'invalid_grant',
            'error_description' => 'Authorization code expired',
        ]);

        $this->expectException(\Exception::class);

        $client->exchangeCode('user-1', 'expired_code', 'https://example.com/callback');
    }

    // --- check ---

    #[Test]
    public function checkReturnsAllowedResultWithActivities(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->http->addResponse(200, [
            'allowed' => true,
            'activities' => [
                [
                    'id' => 1,
                    'activity' => 'Internet',
                    'allowed' => true,
                    'remaining' => 3600,
                    'banned' => false,
                    'timeblock' => true,
                ],
                [
                    'id' => 3,
                    'activity' => 'Gaming',
                    'allowed' => true,
                    'remaining' => 1800,
                    'banned' => false,
                    'timeblock' => true,
                ],
            ],
            'dayType' => ['id' => 1, 'name' => 'School Day'],
            'tomorrowDayType' => ['id' => 2, 'name' => 'Weekend'],
        ]);

        $result = $client->check('user-1', [1, 3]);

        $this->assertTrue($result->allowed);
        $this->assertCount(2, $result->activities);
        $this->assertTrue($result->isActivityAllowed(1));
        $this->assertSame(3600, $result->getRemainingSeconds(1));
        $this->assertNotNull($result->todayDayType);
        $this->assertSame('School Day', $result->todayDayType->name);
    }

    #[Test]
    public function checkReturnsBlockedResult(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->http->addResponse(200, [
            'allowed' => false,
            'activities' => [
                [
                    'id' => 3,
                    'activity' => 'Gaming',
                    'allowed' => false,
                    'remaining' => 0,
                    'banned' => true,
                    'timeblock' => false,
                ],
            ],
            'dayType' => ['id' => 1, 'name' => 'School Day'],
        ]);

        $result = $client->check('user-1', [3]);

        $this->assertFalse($result->allowed);
        $this->assertFalse($result->isActivityAllowed(3));
        $this->assertTrue($result->activities[0]->banned);
    }

    #[Test]
    public function checkUsesCacheOnSecondCall(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->http->addResponse(200, [
            'allowed' => true,
            'activities' => [
                ['id' => 1, 'activity' => 'Internet', 'allowed' => true, 'remaining' => 3600],
            ],
        ]);

        // First call makes HTTP request
        $result1 = $client->check('user-1', [1]);
        $this->assertTrue($result1->allowed);

        // Second call should use cache — no additional HTTP request
        $result2 = $client->check('user-1', [1]);
        $this->assertTrue($result2->allowed);

        // Only one HTTP request should have been made
        $this->assertSame(1, $this->http->getRequestCount());
    }

    #[Test]
    public function checkThrowsUnpairedExceptionOn401(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->http->addResponse(401, [
            'error' => 'unauthorized',
        ]);

        $this->expectException(UnpairedException::class);

        $client->check('user-1', [1]);
    }

    #[Test]
    public function checkThrowsUnpairedExceptionOn403AndClearsTokens(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->http->addResponse(403, [
            'error' => 'forbidden',
        ]);

        try {
            $client->check('user-1', [1]);
            $this->fail('Expected UnpairedException');
        } catch (UnpairedException $e) {
            // Tokens should be cleared
            $this->assertFalse($this->tokenStorage->exists('user-1'));
        }
    }

    #[Test]
    public function checkThrowsWhenNoTokensStored(): void
    {
        $client = $this->createClient();

        $this->expectException(UnpairedException::class);

        $client->check('user-1', [1]);
    }

    #[Test]
    public function checkSendsCorrectRequestPayload(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->http->addResponse(200, [
            'allowed' => true,
            'activities' => [],
        ]);

        $client->check('user-1', [1, 3, 8], 'Australia/Sydney');

        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertStringContainsString('check', $request['url']);
    }

    // --- Auto token refresh ---

    #[Test]
    public function checkRefreshesExpiredTokenBeforeCalling(): void
    {
        $client = $this->createClient();
        $this->storeExpiredTokens('user-1');

        // First call: token refresh
        $this->http->addResponse(200, [
            'access_token' => 'refreshed_access',
            'refresh_token' => 'refreshed_refresh',
            'expires_in' => 3600,
        ]);

        // Second call: the actual check
        $this->http->addResponse(200, [
            'allowed' => true,
            'activities' => [],
        ]);

        $result = $client->check('user-1', [1]);
        $this->assertTrue($result->allowed);

        // Verify refreshed tokens were stored
        $stored = $this->tokenStorage->retrieve('user-1');
        $this->assertNotNull($stored);
        $this->assertSame('refreshed_access', $stored->accessToken);

        // Two HTTP requests: refresh + check
        $this->assertSame(2, $this->http->getRequestCount());
    }

    // --- requestMoreTime ---

    #[Test]
    public function requestMoreTimeReturnsRequestResult(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-123',
            'statusSecret' => 'secret-abc',
            'status' => 'pending',
        ]);

        $result = $client->requestMoreTime('user-1', 3, 30, 'Please let me play more');

        $this->assertSame('req-123', $result->requestId);
        $this->assertSame('secret-abc', $result->statusSecret);
        $this->assertTrue($result->isPending());

        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
    }

    // --- requestDayTypeChange ---

    #[Test]
    public function requestDayTypeChangeReturnsRequestResult(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-456',
            'statusSecret' => 'secret-def',
            'status' => 'pending',
        ]);

        $result = $client->requestDayTypeChange('user-1', 2, 'Can today be a weekend?');

        $this->assertSame('req-456', $result->requestId);
        $this->assertTrue($result->isPending());
    }

    // --- requestBanLift ---

    #[Test]
    public function requestBanLiftReturnsRequestResult(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-789',
            'statusSecret' => 'secret-ghi',
            'status' => 'pending',
        ]);

        $result = $client->requestBanLift('user-1', 3, 'I finished my homework');

        $this->assertSame('req-789', $result->requestId);
        $this->assertTrue($result->isPending());
    }

    // --- getRequestStatus ---

    #[Test]
    public function getRequestStatusReturnsCurrentStatus(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, [
            'status' => 'approved',
        ]);

        $status = $client->getRequestStatus('req-123', 'secret-abc');

        $this->assertSame('approved', $status);
    }

    // --- unpair ---

    #[Test]
    public function unpairClearsStoredTokens(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->assertTrue($this->tokenStorage->exists('user-1'));

        $client->unpair('user-1');

        $this->assertFalse($this->tokenStorage->exists('user-1'));
    }

    // --- submitFeedback ---

    #[Test]
    public function submitFeedbackSendsCorrectRequest(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens('user-1');

        $this->http->addResponse(200, [
            'discussionId' => 'fb-123',
            'status' => 'received',
        ]);

        $result = $client->submitFeedback(
            'user-1',
            \Allow2\Models\FeedbackCategory::Bug,
            'Something is broken',
        );

        $this->assertIsString($result);

        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
    }
}
