<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * Result of creating a request (more time, day type change, or ban lift).
 */
final class RequestResult
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $statusSecret,
        public readonly string $status,
    ) {}

    /**
     * Create from API response.
     *
     * @param array{requestId: string, statusSecret: string, status?: string} $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            requestId: (string) $response['requestId'],
            statusSecret: (string) $response['statusSecret'],
            status: (string) ($response['status'] ?? 'pending'),
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isDenied(): bool
    {
        return $this->status === 'denied';
    }
}
