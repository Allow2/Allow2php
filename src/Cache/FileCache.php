<?php

declare(strict_types=1);

namespace Allow2\Cache;

use Allow2\CacheInterface;

/**
 * File-based permission cache.
 *
 * Each cache entry is stored as a separate JSON file in the cache directory.
 * Suitable for single-server deployments. For multi-server setups,
 * consider PdoCache or a dedicated cache like Redis via a custom CacheInterface.
 */
final class FileCache implements CacheInterface
{
    /**
     * @param string $cacheDir Directory for cache files. Created automatically if missing.
     */
    public function __construct(
        private readonly string $cacheDir,
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?string
    {
        $path = $this->keyToPath($key);

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            $entry = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            @unlink($path);
            return null;
        }

        if (!isset($entry['expiresAt']) || time() >= $entry['expiresAt']) {
            @unlink($path);
            return null;
        }

        return $entry['value'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, string $value, int $ttl = 60): void
    {
        $path = $this->keyToPath($key);

        $entry = json_encode([
            'value' => $value,
            'expiresAt' => time() + $ttl,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($path, $entry, LOCK_EX);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        $path = $this->keyToPath($key);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Convert a cache key to a safe filesystem path.
     */
    private function keyToPath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $this->cacheDir . DIRECTORY_SEPARATOR . $safeKey . '.json';
    }
}
