<?php

declare(strict_types=1);

namespace Allow2\Tests\Storage;

use Allow2\Models\OAuthTokens;
use Allow2\Storage\SessionTokenStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionTokenStorage.
 *
 * Note: These tests manipulate $_SESSION directly. PHPUnit runs in CLI
 * mode where session_start() may behave differently. We pre-populate
 * $_SESSION to avoid requiring an active session.
 *
 * @runInSeparateProcess
 */
final class SessionTokenStorageTest extends TestCase
{
    private SessionTokenStorage $storage;

    protected function setUp(): void
    {
        // Ensure $_SESSION is available without needing headers
        if (session_status() === PHP_SESSION_NONE) {
            // In CLI, session_start() works fine
            @session_start();
        }
        $_SESSION = [];
        $this->storage = new SessionTokenStorage();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function storeAndRetrieveTokens(): void
    {
        $tokens = new OAuthTokens('access_sess', 'refresh_sess', 1700000000);
        $this->storage->store('user-1', $tokens);

        $retrieved = $this->storage->retrieve('user-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('access_sess', $retrieved->accessToken);
        $this->assertSame('refresh_sess', $retrieved->refreshToken);
    }

    #[Test]
    public function retrieveReturnsNullForNonExistentUser(): void
    {
        $this->assertNull($this->storage->retrieve('nonexistent'));
    }

    #[Test]
    public function existsReturnsTrueWhenStored(): void
    {
        $this->storage->store('user-1', new OAuthTokens('a', 'r', 1700000000));
        $this->assertTrue($this->storage->exists('user-1'));
    }

    #[Test]
    public function existsReturnsFalseWhenNotStored(): void
    {
        $this->assertFalse($this->storage->exists('user-1'));
    }

    #[Test]
    public function deleteRemovesTokens(): void
    {
        $this->storage->store('user-1', new OAuthTokens('a', 'r', 1700000000));
        $this->storage->delete('user-1');

        $this->assertFalse($this->storage->exists('user-1'));
        $this->assertNull($this->storage->retrieve('user-1'));
    }

    #[Test]
    public function storeOverwritesExistingTokens(): void
    {
        $this->storage->store('user-1', new OAuthTokens('old', 'old_r', 1700000000));
        $this->storage->store('user-1', new OAuthTokens('new', 'new_r', 1800000000));

        $retrieved = $this->storage->retrieve('user-1');
        $this->assertSame('new', $retrieved->accessToken);
    }

    #[Test]
    public function multipleUsersAreSeparated(): void
    {
        $this->storage->store('user-1', new OAuthTokens('a1', 'r1', 1700000000));
        $this->storage->store('user-2', new OAuthTokens('a2', 'r2', 1800000000));

        $this->assertSame('a1', $this->storage->retrieve('user-1')->accessToken);
        $this->assertSame('a2', $this->storage->retrieve('user-2')->accessToken);
    }

    #[Test]
    public function deletingOneUserDoesNotAffectOthers(): void
    {
        $this->storage->store('user-1', new OAuthTokens('a1', 'r1', 1700000000));
        $this->storage->store('user-2', new OAuthTokens('a2', 'r2', 1700000000));

        $this->storage->delete('user-1');

        $this->assertFalse($this->storage->exists('user-1'));
        $this->assertTrue($this->storage->exists('user-2'));
    }
}
