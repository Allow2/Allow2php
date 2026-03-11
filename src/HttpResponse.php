<?php

declare(strict_types=1);

namespace Allow2;

/**
 * Simple value object representing an HTTP response.
 */
final class HttpResponse
{
    private ?array $decoded = null;

    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {}

    /**
     * Decode the response body as JSON.
     *
     * @return array The decoded JSON array.
     * @throws \JsonException If the body is not valid JSON.
     */
    public function json(): array
    {
        if ($this->decoded === null) {
            $this->decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        }
        return $this->decoded;
    }

    /**
     * Whether the HTTP status code indicates success (2xx).
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Whether the HTTP status code indicates an authentication/authorization error.
     */
    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401 || $this->statusCode === 403;
    }
}
