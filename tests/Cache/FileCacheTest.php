<?php

declare(strict_types=1);

namespace Allow2\Tests\Cache;

use Allow2\Cache\FileCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $cacheDir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/allow2_cache_test_' . uniqid();
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        // Clean up cache files
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->cacheDir);
        }
    }

    #[Test]
    public function createsDirectoryOnConstruction(): void
    {
        $this->assertDirectoryExists($this->cacheDir);
    }

    #[Test]
    public function setAndGetReturnsValue(): void
    {
        $this->cache->set('test_key', 'test_value', 3600);
        $this->assertSame('test_value', $this->cache->get('test_key'));
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
    }

    #[Test]
    public function deleteRemovesEntry(): void
    {
        $this->cache->set('key1', 'value1', 3600);
        $this->cache->delete('key1');
        $this->assertNull($this->cache->get('key1'));
    }

    #[Test]
    public function deleteNonExistentKeyDoesNotThrow(): void
    {
        $this->cache->delete('nonexistent');
        $this->assertNull($this->cache->get('nonexistent'));
    }

    #[Test]
    public function setOverwritesExistingValue(): void
    {
        $this->cache->set('key1', 'old', 3600);
        $this->cache->set('key1', 'new', 3600);
        $this->assertSame('new', $this->cache->get('key1'));
    }

    #[Test]
    public function expiredEntryReturnsNull(): void
    {
        $this->cache->set('key1', 'value1', 1);

        // Should be valid immediately
        $this->assertSame('value1', $this->cache->get('key1'));

        // Wait for expiry
        sleep(2);

        $this->assertNull($this->cache->get('key1'));
    }

    #[Test]
    public function persistsBetweenInstances(): void
    {
        $this->cache->set('persist_key', 'persist_value', 3600);

        // Create new instance pointing to same directory
        $newCache = new FileCache($this->cacheDir);
        $this->assertSame('persist_value', $newCache->get('persist_key'));
    }

    #[Test]
    public function sanitizesKeysForFilenames(): void
    {
        // Keys with special characters should be handled safely
        $this->cache->set('user:123:check', 'data', 3600);
        $this->assertSame('data', $this->cache->get('user:123:check'));
    }

    #[Test]
    public function handlesJsonSerializedValues(): void
    {
        $data = json_encode(['allowed' => true, 'remaining' => 3600], JSON_THROW_ON_ERROR);
        $this->cache->set('check_result', $data, 3600);

        $retrieved = $this->cache->get('check_result');
        $decoded = json_decode($retrieved, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['allowed']);
        $this->assertSame(3600, $decoded['remaining']);
    }

    #[Test]
    public function handlesEmptyStringValue(): void
    {
        $this->cache->set('empty', '', 3600);
        // An empty string stored as a value should be retrievable
        // Note: FileCache stores as JSON { value: "", expiresAt: N }
        // The value field will be empty string, which is a valid non-null return
        $result = $this->cache->get('empty');
        $this->assertSame('', $result);
    }
}
