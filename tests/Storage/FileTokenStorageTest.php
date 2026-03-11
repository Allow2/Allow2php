<?php

declare(strict_types=1);

namespace Allow2\Tests\Storage;

use Allow2\Models\OAuthTokens;
use Allow2\Storage\FileTokenStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileTokenStorageTest extends TestCase
{
    private string $tempDir;
    private string $filePath;
    private FileTokenStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/allow2_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->filePath = $this->tempDir . '/tokens.json';
        $this->storage = new FileTokenStorage($this->filePath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function storeAndRetrieveTokens(): void
    {
        $tokens = new OAuthTokens('access_abc', 'refresh_xyz', 1700000000);
        $this->storage->store('user-1', $tokens);

        $retrieved = $this->storage->retrieve('user-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('access_abc', $retrieved->accessToken);
        $this->assertSame('refresh_xyz', $retrieved->refreshToken);
        $this->assertSame(1700000000, $retrieved->expiresAt);
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
        $this->assertSame(1800000000, $retrieved->expiresAt);
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
    public function persistsToDisk(): void
    {
        $this->storage->store('user-1', new OAuthTokens('persisted', 'refresh', 1700000000));

        // Create a new storage instance pointing to same file
        $newStorage = new FileTokenStorage($this->filePath);
        $retrieved = $newStorage->retrieve('user-1');

        $this->assertNotNull($retrieved);
        $this->assertSame('persisted', $retrieved->accessToken);
    }

    #[Test]
    public function createsDirectoryIfMissing(): void
    {
        $nestedPath = $this->tempDir . '/nested/deep/tokens.json';
        $storage = new FileTokenStorage($nestedPath);
        $storage->store('user-1', new OAuthTokens('a', 'r', 1700000000));

        $this->assertFileExists($nestedPath);

        // Cleanup
        unlink($nestedPath);
        rmdir($this->tempDir . '/nested/deep');
        rmdir($this->tempDir . '/nested');
    }

    #[Test]
    public function handlesEmptyFileGracefully(): void
    {
        file_put_contents($this->filePath, '');

        $storage = new FileTokenStorage($this->filePath);
        $this->assertNull($storage->retrieve('user-1'));
        $this->assertFalse($storage->exists('user-1'));
    }

    #[Test]
    public function handlesCorruptedJsonGracefully(): void
    {
        file_put_contents($this->filePath, '{invalid json');

        $storage = new FileTokenStorage($this->filePath);
        $this->assertNull($storage->retrieve('user-1'));
    }
}
