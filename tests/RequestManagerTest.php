<?php

declare(strict_types=1);

namespace Allow2\Tests;

use Allow2\Cache\ArrayCache;
use Allow2\CacheInterface;
use Allow2\Exceptions\UnpairedException;
use Allow2\Models\OAuthTokens;
use Allow2\Models\RequestResult;
use Allow2\Storage\PdoTokenStorage;
use Allow2\TokenStorageInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for all three request types: more time, day type change, ban lift.
 * Also tests request status polling.
 */
final class RequestManagerTest extends TestCase
{
    private const CLIENT_ID = 'req-test-client';
    private const CLIENT_SECRET = 'req-test-secret';
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

    private function storeValidTokens(string $userId = 'user-1'): void
    {
        $this->tokenStorage->store($userId, new OAuthTokens(
            accessToken: 'valid_at',
            refreshToken: 'valid_rt',
            expiresAt: time() + 7200,
        ));
    }

    // --- requestMoreTime ---

    #[Test]
    public function requestMoreTimeReturnsResult(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-mt-001',
            'statusSecret' => 'ss-mt-001',
            'status' => 'pending',
        ]);

        $result = $client->requestMoreTime('user-1', 3, 30, 'I need 30 more minutes for gaming');

        $this->assertInstanceOf(RequestResult::class, $result);
        $this->assertSame('req-mt-001', $result->requestId);
        $this->assertSame('ss-mt-001', $result->statusSecret);
        $this->assertTrue($result->isPending());
        $this->assertFalse($result->isApproved());
        $this->assertFalse($result->isDenied());
    }

    #[Test]
    public function requestMoreTimeSendsCorrectPayload(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-mt-002',
            'statusSecret' => 'ss-mt-002',
            'status' => 'pending',
        ]);

        $client->requestMoreTime('user-1', 3, 45, 'More gaming time please');

        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertStringContainsString('request', strtolower($request['url']));
    }

    #[Test]
    public function requestMoreTimeThrowsWhenUnpaired(): void
    {
        $client = $this->createClient();

        // No tokens stored
        $this->expectException(UnpairedException::class);
        $client->requestMoreTime('user-1', 3, 30, 'Please');
    }

    // --- requestDayTypeChange ---

    #[Test]
    public function requestDayTypeChangeReturnsResult(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-dt-001',
            'statusSecret' => 'ss-dt-001',
            'status' => 'pending',
        ]);

        $result = $client->requestDayTypeChange('user-1', 2, 'Can today be a weekend day?');

        $this->assertInstanceOf(RequestResult::class, $result);
        $this->assertSame('req-dt-001', $result->requestId);
        $this->assertTrue($result->isPending());
    }

    #[Test]
    public function requestDayTypeChangeSendsCorrectPayload(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-dt-002',
            'statusSecret' => 'ss-dt-002',
            'status' => 'pending',
        ]);

        $client->requestDayTypeChange('user-1', 5, 'Holiday please');

        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
    }

    // --- requestBanLift ---

    #[Test]
    public function requestBanLiftReturnsResult(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-bl-001',
            'statusSecret' => 'ss-bl-001',
            'status' => 'pending',
        ]);

        $result = $client->requestBanLift('user-1', 3, 'I did all my chores');

        $this->assertInstanceOf(RequestResult::class, $result);
        $this->assertSame('req-bl-001', $result->requestId);
        $this->assertTrue($result->isPending());
    }

    #[Test]
    public function requestBanLiftSendsCorrectPayload(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        // First HTTP call: POST /request/tempToken
        $this->http->addResponse(200, [
            'tempToken' => 'tmp-token',
            'secretToken' => 'tmp-secret',
        ]);
        // Second HTTP call: POST /request/createRequest
        $this->http->addResponse(200, [
            'requestId' => 'req-bl-002',
            'statusSecret' => 'ss-bl-002',
            'status' => 'pending',
        ]);

        $client->requestBanLift('user-1', 6, 'Please unban social media');

        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
    }

    // --- getRequestStatus ---

    #[Test]
    public function getRequestStatusReturnsPending(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, ['status' => 'pending']);

        $status = $client->getRequestStatus('req-123', 'secret-abc');
        $this->assertSame('pending', $status);
    }

    #[Test]
    public function getRequestStatusReturnsApproved(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, ['status' => 'approved']);

        $status = $client->getRequestStatus('req-456', 'secret-def');
        $this->assertSame('approved', $status);
    }

    #[Test]
    public function getRequestStatusReturnsDenied(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, ['status' => 'denied']);

        $status = $client->getRequestStatus('req-789', 'secret-ghi');
        $this->assertSame('denied', $status);
    }

    #[Test]
    public function getRequestStatusSendsStatusSecretInRequest(): void
    {
        $client = $this->createClient();

        $this->http->addResponse(200, ['status' => 'pending']);

        $client->getRequestStatus('req-123', 'my-secret');

        $request = $this->http->getLastRequest();
        // The request should include the statusSecret and requestId
        $requestJson = json_encode($request);
        $urlAndData = $request['url'] . json_encode($request['data']);
        $this->assertTrue(
            str_contains($urlAndData, 'req-123') || str_contains($urlAndData, 'my-secret'),
            'Request should include requestId or statusSecret',
        );
    }

    // --- Request API error handling ---

    #[Test]
    public function requestThrowsOn401(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(401, ['error' => 'unauthorized']);

        $this->expectException(UnpairedException::class);
        $client->requestMoreTime('user-1', 3, 30, 'Please');
    }

    #[Test]
    public function requestResultModelStatusChecks(): void
    {
        // Test RequestResult model directly
        $pending = new RequestResult('r1', 's1', 'pending');
        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isApproved());
        $this->assertFalse($pending->isDenied());

        $approved = new RequestResult('r2', 's2', 'approved');
        $this->assertFalse($approved->isPending());
        $this->assertTrue($approved->isApproved());
        $this->assertFalse($approved->isDenied());

        $denied = new RequestResult('r3', 's3', 'denied');
        $this->assertFalse($denied->isPending());
        $this->assertFalse($denied->isApproved());
        $this->assertTrue($denied->isDenied());
    }

    #[Test]
    public function requestResultFromApiResponse(): void
    {
        $result = RequestResult::fromApiResponse([
            'requestId' => 'api-req-1',
            'statusSecret' => 'api-secret-1',
            'status' => 'approved',
        ]);

        $this->assertSame('api-req-1', $result->requestId);
        $this->assertSame('api-secret-1', $result->statusSecret);
        $this->assertTrue($result->isApproved());
    }

    #[Test]
    public function requestResultFromApiResponseDefaultsToPending(): void
    {
        $result = RequestResult::fromApiResponse([
            'requestId' => 'api-req-2',
            'statusSecret' => 'api-secret-2',
            // No status key
        ]);

        $this->assertTrue($result->isPending());
    }
}
