<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * Value object representing an OAuth2 token set.
 */
final class OAuthTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresAt,
    ) {}

    /**
     * Check whether the access token has expired or is about to expire.
     *
     * @param int $bufferSeconds Seconds before actual expiry to consider "expired" (default 300 = 5 min).
     */
    public function isExpired(int $bufferSeconds = 300): bool
    {
        return time() >= ($this->expiresAt - $bufferSeconds);
    }

    /**
     * Create from an API token response.
     *
     * @param array{access_token: string, refresh_token: string, expires_in: int} $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            accessToken: $response['access_token'],
            refreshToken: $response['refresh_token'],
            expiresAt: time() + (int) $response['expires_in'],
        );
    }

    /**
     * Serialize to array for storage.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * Reconstitute from stored array.
     *
     * @param array{access_token: string, refresh_token: string, expires_at: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['access_token'],
            refreshToken: $data['refresh_token'],
            expiresAt: (int) $data['expires_at'],
        );
    }
}
