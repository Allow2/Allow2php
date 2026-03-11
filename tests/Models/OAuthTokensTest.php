<?php

declare(strict_types=1);

namespace Allow2\Tests\Models;

use Allow2\Models\OAuthTokens;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthTokensTest extends TestCase
{
    #[Test]
    public function constructorSetsReadonlyProperties(): void
    {
        $tokens = new OAuthTokens(
            accessToken: 'access_abc',
            refreshToken: 'refresh_xyz',
            expiresAt: 1700000000,
        );

        $this->assertSame('access_abc', $tokens->accessToken);
        $this->assertSame('refresh_xyz', $tokens->refreshToken);
        $this->assertSame(1700000000, $tokens->expiresAt);
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastExpiryTime(): void
    {
        $tokens = new OAuthTokens(
            accessToken: 'a',
            refreshToken: 'r',
            expiresAt: time() - 100,
        );

        $this->assertTrue($tokens->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueWithinBufferWindow(): void
    {
        // Expires in 200 seconds, but buffer is 300 (default)
        $tokens = new OAuthTokens(
            accessToken: 'a',
            refreshToken: 'r',
            expiresAt: time() + 200,
        );

        $this->assertTrue($tokens->isExpired(bufferSeconds: 300));
    }

    #[Test]
    public function isExpiredReturnsFalseWhenFarFromExpiry(): void
    {
        $tokens = new OAuthTokens(
            accessToken: 'a',
            refreshToken: 'r',
            expiresAt: time() + 7200,
        );

        $this->assertFalse($tokens->isExpired());
    }

    #[Test]
    public function isExpiredRespectsCustomBuffer(): void
    {
        // Expires in 60 seconds, buffer is 0
        $tokens = new OAuthTokens(
            accessToken: 'a',
            refreshToken: 'r',
            expiresAt: time() + 60,
        );

        $this->assertFalse($tokens->isExpired(bufferSeconds: 0));
    }

    #[Test]
    public function fromApiResponseCreatesTokensWithComputedExpiry(): void
    {
        $before = time();

        $tokens = OAuthTokens::fromApiResponse([
            'access_token' => 'at_123',
            'refresh_token' => 'rt_456',
            'expires_in' => 3600,
        ]);

        $after = time();

        $this->assertSame('at_123', $tokens->accessToken);
        $this->assertSame('rt_456', $tokens->refreshToken);
        $this->assertGreaterThanOrEqual($before + 3600, $tokens->expiresAt);
        $this->assertLessThanOrEqual($after + 3600, $tokens->expiresAt);
    }

    #[Test]
    public function toArraySerializesCorrectly(): void
    {
        $tokens = new OAuthTokens(
            accessToken: 'access_abc',
            refreshToken: 'refresh_xyz',
            expiresAt: 1700000000,
        );

        $this->assertSame([
            'access_token' => 'access_abc',
            'refresh_token' => 'refresh_xyz',
            'expires_at' => 1700000000,
        ], $tokens->toArray());
    }

    #[Test]
    public function fromArrayReconstitutesCorrectly(): void
    {
        $tokens = OAuthTokens::fromArray([
            'access_token' => 'access_abc',
            'refresh_token' => 'refresh_xyz',
            'expires_at' => 1700000000,
        ]);

        $this->assertSame('access_abc', $tokens->accessToken);
        $this->assertSame('refresh_xyz', $tokens->refreshToken);
        $this->assertSame(1700000000, $tokens->expiresAt);
    }

    #[Test]
    public function roundTripThroughArrayPreservesData(): void
    {
        $original = new OAuthTokens(
            accessToken: 'at_roundtrip',
            refreshToken: 'rt_roundtrip',
            expiresAt: 1750000000,
        );

        $restored = OAuthTokens::fromArray($original->toArray());

        $this->assertSame($original->accessToken, $restored->accessToken);
        $this->assertSame($original->refreshToken, $restored->refreshToken);
        $this->assertSame($original->expiresAt, $restored->expiresAt);
    }
}
