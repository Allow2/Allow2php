<?php

declare(strict_types=1);

namespace Allow2\Tests\Cache;

use Allow2\Cache\ArrayCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    #[Test]
    public function setAndGetReturnsValue(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertSame('value1', $this->cache->get('key1'));
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
    }

    #[Test]
    public function deleteRemovesEntry(): void
    {
        $this->cache->set('key1', 'value1');
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
        $this->cache->set('key1', 'old');
        $this->cache->set('key1', 'new');
        $this->assertSame('new', $this->cache->get('key1'));
    }

    #[Test]
    public function multipleKeysAreSeparated(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $this->assertSame('value1', $this->cache->get('key1'));
        $this->assertSame('value2', $this->cache->get('key2'));
    }

    #[Test]
    public function expiredEntryReturnsNull(): void
    {
        // Set with 1 second TTL
        $this->cache->set('key1', 'value1', 1);

        // Still valid immediately
        $this->assertSame('value1', $this->cache->get('key1'));

        // Wait for expiry
        sleep(2);

        $this->assertNull($this->cache->get('key1'));
    }

    #[Test]
    public function customTtlIsRespected(): void
    {
        // Set with very long TTL
        $this->cache->set('key1', 'value1', 3600);
        $this->assertSame('value1', $this->cache->get('key1'));
    }

    #[Test]
    public function handlesEmptyStringValue(): void
    {
        $this->cache->set('key1', '');
        $this->assertSame('', $this->cache->get('key1'));
    }

    #[Test]
    public function handlesJsonSerializedValues(): void
    {
        $data = json_encode(['allowed' => true, 'activities' => []], JSON_THROW_ON_ERROR);
        $this->cache->set('check_user1', $data);

        $retrieved = $this->cache->get('check_user1');
        $decoded = json_decode($retrieved, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['allowed']);
        $this->assertSame([], $decoded['activities']);
    }
}
