<?php

declare(strict_types=1);

namespace Allow2\Exceptions;

/**
 * Thrown when the service account is no longer linked (HTTP 401/403 from API).
 *
 * The integration should redirect the user through OAuth2 re-pairing.
 */
class UnpairedException extends Allow2Exception
{
    public function __construct(
        string $userId,
        string $message = 'Service account is no longer linked. Re-pairing required.',
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
