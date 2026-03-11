<?php

declare(strict_types=1);

namespace Allow2\Tests\Storage;

use Allow2\Models\OAuthTokens;
use Allow2\Storage\PdoTokenStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdoTokenStorageTest extends TestCase
{
    private \PDO $pdo;
    private PdoTokenStorage $storage;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->storage = new PdoTokenStorage($this->pdo);
    }

    #[Test]
    public function storeAndRetrieveTokens(): void
    {
        $tokens = new OAuthTokens(
            accessToken: 'access_123',
            refreshToken: 'refresh_456',
            expiresAt: 1700000000,
        );

        $this->storage->store('user-1', $tokens);

        $retrieved = $this->storage->retrieve('user-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('access_123', $retrieved->accessToken);
        $this->assertSame('refresh_456', $retrieved->refreshToken);
        $this->assertSame(1700000000, $retrieved->expiresAt);
    }

    #[Test]
    public function retrieveReturnsNullForNonExistentUser(): void
    {
        $this->assertNull($this->storage->retrieve('nonexistent'));
    }

    #[Test]
    public function existsReturnsTrueWhenTokensStored(): void
    {
        $tokens = new OAuthTokens('a', 'r', 1700000000);
        $this->storage->store('user-1', $tokens);

        $this->assertTrue($this->storage->exists('user-1'));
    }

    #[Test]
    public function existsReturnsFalseWhenNoTokens(): void
    {
        $this->assertFalse($this->storage->exists('user-1'));
    }

    #[Test]
    public function deleteRemovesTokens(): void
    {
        $tokens = new OAuthTokens('a', 'r', 1700000000);
        $this->storage->store('user-1', $tokens);

        $this->assertTrue($this->storage->exists('user-1'));

        $this->storage->delete('user-1');

        $this->assertFalse($this->storage->exists('user-1'));
        $this->assertNull($this->storage->retrieve('user-1'));
    }

    #[Test]
    public function deleteNonExistentUserDoesNotThrow(): void
    {
        // Should not throw
        $this->storage->delete('nonexistent');
        $this->assertFalse($this->storage->exists('nonexistent'));
    }

    #[Test]
    public function storeOverwritesExistingTokens(): void
    {
        $original = new OAuthTokens('old_access', 'old_refresh', 1700000000);
        $this->storage->store('user-1', $original);

        $updated = new OAuthTokens('new_access', 'new_refresh', 1800000000);
        $this->storage->store('user-1', $updated);

        $retrieved = $this->storage->retrieve('user-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('new_access', $retrieved->accessToken);
        $this->assertSame('new_refresh', $retrieved->refreshToken);
        $this->assertSame(1800000000, $retrieved->expiresAt);
    }

    #[Test]
    public function multipleUsersAreSeparated(): void
    {
        $tokens1 = new OAuthTokens('access_1', 'refresh_1', 1700000000);
        $tokens2 = new OAuthTokens('access_2', 'refresh_2', 1800000000);

        $this->storage->store('user-1', $tokens1);
        $this->storage->store('user-2', $tokens2);

        $retrieved1 = $this->storage->retrieve('user-1');
        $retrieved2 = $this->storage->retrieve('user-2');

        $this->assertSame('access_1', $retrieved1->accessToken);
        $this->assertSame('access_2', $retrieved2->accessToken);
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

    #[Test]
    public function autoCreatesTableOnFirstOperation(): void
    {
        // The table should not exist yet, but retrieve should work (auto-creates)
        $freshPdo = new \PDO('sqlite::memory:');
        $freshStorage = new PdoTokenStorage($freshPdo, 'custom_tokens_table');

        $this->assertNull($freshStorage->retrieve('user-1'));

        // Verify the table was created by checking schema
        $stmt = $freshPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='custom_tokens_table'");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('custom_tokens_table', $row['name']);
    }

    #[Test]
    public function customTableNameIsUsed(): void
    {
        $storage = new PdoTokenStorage($this->pdo, 'my_tokens');
        $storage->store('user-1', new OAuthTokens('a', 'r', 1700000000));

        // Verify data is in the custom table
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM my_tokens");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
