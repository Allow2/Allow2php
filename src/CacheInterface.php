<?php

declare(strict_types=1);

namespace Allow2;

/**
 * Interface for caching permission check results.
 *
 * Simple key-value cache with TTL support. Values are serialized strings.
 */
interface CacheInterface
{
    /**
     * Retrieve a cached value by key.
     *
     * @param string $key Cache key.
     * @return string|null The cached value, or null if not found or expired.
     */
    public function get(string $key): ?string;

    /**
     * Store a value in cache.
     *
     * @param string $key Cache key.
     * @param string $value Value to cache.
     * @param int $ttl Time-to-live in seconds (default 60).
     */
    public function set(string $key, string $value, int $ttl = 60): void;

    /**
     * Delete a cached value.
     *
     * @param string $key Cache key.
     */
    public function delete(string $key): void;
}
