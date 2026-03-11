<?php

declare(strict_types=1);

namespace Allow2\Exceptions;

/**
 * Thrown when the OAuth2 token refresh fails and no valid token is available.
 */
class TokenExpiredException extends Allow2Exception
{
    public function __construct(
        string $userId,
        string $message = 'OAuth2 token refresh failed. Re-authorization required.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous, ['userId' => $userId]);
    }

    public function getUserId(): string
    {
        return $this->context['userId'];
    }
}
