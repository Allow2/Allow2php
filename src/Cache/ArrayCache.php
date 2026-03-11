<?php

declare(strict_types=1);

namespace Allow2\Cache;

use Allow2\CacheInterface;

/**
 * In-memory cache that lives for the duration of a single PHP request.
 *
 * No persistence between requests. Useful for testing or when you
 * only need to deduplicate checks within a single request lifecycle.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: string, expiresAt: int}> */
    private array $store = [];

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?string
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        if (time() >= $this->store[$key]['expiresAt']) {
            unset($this->store[$key]);
            return null;
        }

        return $this->store[$key]['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, string $value, int $ttl = 60): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => time() + $ttl,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}
