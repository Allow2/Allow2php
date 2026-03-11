<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * A challenge-response pair for offline voice code approval.
 *
 * The challenge is displayed to the child, who reads it to the parent.
 * The parent's Allow2 app computes the response; the child enters it.
 */
final class VoiceCodePair
{
    public function __construct(
        public readonly string $challenge,
        public readonly string $expectedResponse,
    ) {}
}
