<?php

declare(strict_types=1);

namespace Allow2\Tests;

use Allow2\Cache\ArrayCache;
use Allow2\CacheInterface;
use Allow2\Exceptions\UnpairedException;
use Allow2\Models\FeedbackCategory;
use Allow2\Models\OAuthTokens;
use Allow2\Storage\PdoTokenStorage;
use Allow2\TokenStorageInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the feedback submission feature.
 */
final class FeedbackManagerTest extends TestCase
{
    private const CLIENT_ID = 'fb-test-client';
    private const CLIENT_SECRET = 'fb-test-secret';
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

    #[Test]
    public function submitFeedbackReturnsStringResponse(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(200, [
            'discussionId' => 'fb-001',
            'status' => 'received',
        ]);

        $result = $client->submitFeedback('user-1', FeedbackCategory::Bug, 'App crashes on login');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function submitFeedbackSendsPostRequest(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(200, [
            'discussionId' => 'fb-002',
            'status' => 'received',
        ]);

        $client->submitFeedback('user-1', FeedbackCategory::FeatureRequest, 'Add dark mode');

        $request = $this->http->getLastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertStringContainsString('feedback', strtolower($request['url']));
    }

    #[Test]
    public function submitFeedbackWithBugCategory(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(200, [
            'discussionId' => 'fb-003',
            'status' => 'received',
        ]);

        $result = $client->submitFeedback('user-1', FeedbackCategory::Bug, 'Button is broken');

        $request = $this->http->getLastRequest();
        $data = $request['data'];
        // The category should be sent as 'bug'
        $dataJson = json_encode($data);
        $this->assertTrue(
            str_contains($dataJson, 'bug') || str_contains($dataJson, FeedbackCategory::Bug->value),
            'Request data should contain the bug category value',
        );
    }

    #[Test]
    public function submitFeedbackWithNotWorkingCategory(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(200, [
            'discussionId' => 'fb-004',
            'status' => 'received',
        ]);

        $client->submitFeedback('user-1', FeedbackCategory::NotWorking, 'The check API is not working');

        $request = $this->http->getLastRequest();
        $dataJson = json_encode($request['data']);
        $this->assertTrue(
            str_contains($dataJson, 'not_working'),
            'Request data should contain the not_working category value',
        );
    }

    #[Test]
    public function submitFeedbackWithOtherCategory(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(200, [
            'discussionId' => 'fb-005',
            'status' => 'received',
        ]);

        $client->submitFeedback('user-1', FeedbackCategory::Other, 'General feedback');

        $request = $this->http->getLastRequest();
        $dataJson = json_encode($request['data']);
        $this->assertTrue(
            str_contains($dataJson, 'other'),
            'Request data should contain the other category value',
        );
    }

    #[Test]
    public function submitFeedbackThrowsWhenUnpaired(): void
    {
        $client = $this->createClient();

        // No tokens stored
        $this->expectException(UnpairedException::class);
        $client->submitFeedback('user-1', FeedbackCategory::Bug, 'This will fail');
    }

    #[Test]
    public function submitFeedbackThrowsOn401(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(401, ['error' => 'unauthorized']);

        $this->expectException(UnpairedException::class);
        $client->submitFeedback('user-1', FeedbackCategory::Bug, 'Unauthorized feedback');
    }

    #[Test]
    public function submitFeedbackIncludesMessageInPayload(): void
    {
        $client = $this->createClient();

        $this->storeValidTokens();

        $this->http->addResponse(200, [
            'discussionId' => 'fb-006',
            'status' => 'received',
        ]);

        $message = 'This is a detailed feedback message about the SDK';
        $client->submitFeedback('user-1', FeedbackCategory::FeatureRequest, $message);

        $request = $this->http->getLastRequest();
        $dataJson = json_encode($request['data']);
        $this->assertTrue(
            str_contains($dataJson, $message),
            'Request data should contain the feedback message',
        );
    }
}
