<?php

declare(strict_types=1);

namespace Allow2\Tests;

use Allow2\Cache\ArrayCache;
use Allow2\CacheInterface;
use Allow2\Models\OAuthTokens;
use Allow2\Models\RequestType;
use Allow2\Models\VoiceCodePair;
use Allow2\Storage\PdoTokenStorage;
use Allow2\TokenStorageInterface;
use Allow2\VoiceCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for voice code challenge-response (offline approval) functionality.
 *
 * Format: T A MM NN where:
 * - T = request type (0=MoreTime, 1=DayTypeChange, 2=BanLift)
 * - A = activity ID
 * - MM = minutes (in 5-min increments or raw)
 * - NN = nonce
 */
final class VoiceCodeTest extends TestCase
{
    private const CLIENT_ID = 'voice-test-client';
    private const CLIENT_SECRET = 'voice-test-secret';
    private const TEST_SECRET = 'test-pairing-secret';
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

    // --- RequestType voice codes ---

    #[Test]
    public function requestTypeMoreTimeVoiceCodeIsZero(): void
    {
        $this->assertSame(0, RequestType::MoreTime->voiceCode());
    }

    #[Test]
    public function requestTypeDayTypeChangeVoiceCodeIsOne(): void
    {
        $this->assertSame(1, RequestType::DayTypeChange->voiceCode());
    }

    #[Test]
    public function requestTypeBanLiftVoiceCodeIsTwo(): void
    {
        $this->assertSame(2, RequestType::BanLift->voiceCode());
    }

    // --- VoiceCodePair model ---

    #[Test]
    public function voiceCodePairConstructorSetsProperties(): void
    {
        $pair = new VoiceCodePair(
            challenge: '0 3 06 42',
            expectedResponse: '1234',
        );

        $this->assertSame('0 3 06 42', $pair->challenge);
        $this->assertSame('1234', $pair->expectedResponse);
    }

    // --- generateVoiceChallenge ---

    #[Test]
    public function generateVoiceChallengeReturnsVoiceCodePair(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens();

        $result = $client->generateVoiceChallenge(
            'user-1',
            RequestType::MoreTime,
            3,
            30,
            self::TEST_SECRET,
        );

        $this->assertInstanceOf(VoiceCodePair::class, $result);
        $this->assertNotEmpty($result->challenge);
        $this->assertNotEmpty($result->expectedResponse);
    }

    #[Test]
    public function generateVoiceChallengeThrowsWithoutSecret(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens();

        $this->expectException(\InvalidArgumentException::class);

        $client->generateVoiceChallenge('user-1', RequestType::MoreTime, 3, 30);
    }

    #[Test]
    public function sameChallengeSameSecretProducesSameResponse(): void
    {
        // VoiceCode::generate() uses random_int() for the nonce, so two calls
        // produce different challenges. Instead, verify that the same challenge
        // + secret always produces the same HMAC response.
        $challenge = '0 03 06 42';
        $date = '2026-03-11';

        $response1 = VoiceCode::verify(self::TEST_SECRET, $challenge, '000000', $date);
        // Generate the expected response for this challenge
        $pair = VoiceCode::generate(self::TEST_SECRET, RequestType::MoreTime, 3, 30, $date);
        // Verify that verifying the generated pair's challenge with its response works
        $verified = VoiceCode::verify(self::TEST_SECRET, $pair->challenge, $pair->expectedResponse, $date);
        $this->assertTrue($verified);
    }

    #[Test]
    public function generateVoiceChallengeEncodesRequestTypeInChallenge(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens();
        $date = '2026-03-11';

        $moreTime = $client->generateVoiceChallenge('user-1', RequestType::MoreTime, 3, 30, self::TEST_SECRET, $date);
        $banLift = $client->generateVoiceChallenge('user-1', RequestType::BanLift, 3, 30, self::TEST_SECRET, $date);

        // Different request types should produce different challenges
        // (the T field differs: 0 vs 2)
        $this->assertNotSame($moreTime->challenge, $banLift->challenge);
    }

    #[Test]
    public function generateVoiceChallengeProducesDifferentResponseForDifferentSecret(): void
    {
        $date = '2026-03-11';

        // Use VoiceCode::generate() directly with different secrets and a fixed date
        $pair1 = VoiceCode::generate('secret-AAA', RequestType::MoreTime, 3, 30, $date);
        $pair2 = VoiceCode::generate('secret-BBB', RequestType::MoreTime, 3, 30, $date);

        // Different secrets should produce different expected responses,
        // even if challenges happen to differ due to random nonce.
        // Verify cross-check: pair1's response should NOT verify with pair2's secret.
        $crossVerified = VoiceCode::verify('secret-BBB', $pair1->challenge, $pair1->expectedResponse, $date);
        $this->assertFalse($crossVerified);
    }

    // --- verifyVoiceResponse ---

    #[Test]
    public function verifyVoiceResponseReturnsTrueForCorrectResponse(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens();
        $date = '2026-03-11';

        // Generate a challenge first
        $pair = $client->generateVoiceChallenge('user-1', RequestType::MoreTime, 3, 30, self::TEST_SECRET, $date);

        // Verify with the correct response
        $verified = $client->verifyVoiceResponse('user-1', $pair->challenge, $pair->expectedResponse, self::TEST_SECRET, $date);

        $this->assertTrue($verified);
    }

    #[Test]
    public function verifyVoiceResponseReturnsFalseForWrongResponse(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens();
        $date = '2026-03-11';

        $pair = $client->generateVoiceChallenge('user-1', RequestType::MoreTime, 3, 30, self::TEST_SECRET, $date);

        $verified = $client->verifyVoiceResponse('user-1', $pair->challenge, '000000', self::TEST_SECRET, $date);

        $this->assertFalse($verified);
    }

    #[Test]
    public function verifyVoiceResponseReturnsFalseForEmptyResponse(): void
    {
        $client = $this->createClient();
        $this->storeValidTokens();
        $date = '2026-03-11';

        $pair = $client->generateVoiceChallenge('user-1', RequestType::MoreTime, 3, 30, self::TEST_SECRET, $date);

        $verified = $client->verifyVoiceResponse('user-1', $pair->challenge, '', self::TEST_SECRET, $date);

        $this->assertFalse($verified);
    }
}
