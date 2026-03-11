<?php

declare(strict_types=1);

namespace Allow2\Exceptions;

/**
 * Base exception for all Allow2 SDK errors.
 */
class Allow2Exception extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}
