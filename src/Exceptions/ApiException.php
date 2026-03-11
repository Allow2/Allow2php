<?php

declare(strict_types=1);

namespace Allow2\Exceptions;

/**
 * Thrown when an Allow2 API call fails with an unexpected error.
 */
class ApiException extends Allow2Exception
{
    public function __construct(
        string $message,
        public readonly int $httpStatusCode,
        public readonly ?array $responseBody = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous, [
            'httpStatusCode' => $httpStatusCode,
            'responseBody' => $responseBody,
        ]);
    }
}
