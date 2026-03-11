<?php

declare(strict_types=1);

namespace Allow2;

use Allow2\Models\OAuthTokens;

/**
 * Interface for per-user OAuth2 token persistence.
 *
 * Implementations store tokens keyed by the application's internal user ID.
 * The user ID is an opaque string from the integrating application — not
 * an Allow2 user ID.
 */
interface TokenStorageInterface
{
    /**
     * Store tokens for a user.
     */
    public function store(string $userId, OAuthTokens $tokens): void;

    /**
     * Retrieve tokens for a user, or null if none stored.
     */
    public function retrieve(string $userId): ?OAuthTokens;

    /**
     * Delete tokens for a user (e.g., on unpair).
     */
    public function delete(string $userId): void;

    /**
     * Check whether tokens exist for a user.
     */
    public function exists(string $userId): bool;
}
