<?php

declare(strict_types=1);

namespace Allow2\Storage;

use Allow2\Models\OAuthTokens;
use Allow2\TokenStorageInterface;

/**
 * PHP $_SESSION-based token storage.
 *
 * Suitable for development and simple applications where session
 * persistence is acceptable. Not recommended for production
 * multi-server deployments.
 *
 * Ensure session_start() has been called before using this storage.
 */
final class SessionTokenStorage implements TokenStorageInterface
{
    private const SESSION_KEY = 'allow2_tokens';

    /**
     * {@inheritdoc}
     */
    public function store(string $userId, OAuthTokens $tokens): void
    {
        $this->ensureSession();
        $_SESSION[self::SESSION_KEY][$userId] = $tokens->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $userId): ?OAuthTokens
    {
        $this->ensureSession();

        if (!isset($_SESSION[self::SESSION_KEY][$userId])) {
            return null;
        }

        return OAuthTokens::fromArray($_SESSION[self::SESSION_KEY][$userId]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $userId): void
    {
        $this->ensureSession();
        unset($_SESSION[self::SESSION_KEY][$userId]);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $userId): bool
    {
        $this->ensureSession();
        return isset($_SESSION[self::SESSION_KEY][$userId]);
    }

    /**
     * Ensure a PHP session is active.
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
