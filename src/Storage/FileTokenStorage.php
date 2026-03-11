<?php

declare(strict_types=1);

namespace Allow2\Storage;

use Allow2\Models\OAuthTokens;
use Allow2\TokenStorageInterface;

/**
 * File-based JSON token storage.
 *
 * Stores all tokens in a single JSON file. Suitable for development
 * and single-server deployments. Not recommended for high-concurrency
 * production use — consider PdoTokenStorage instead.
 */
final class FileTokenStorage implements TokenStorageInterface
{
    private ?array $data = null;

    /**
     * @param string $filePath Path to the JSON storage file.
     */
    public function __construct(
        private readonly string $filePath,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function store(string $userId, OAuthTokens $tokens): void
    {
        $data = $this->loadAll();
        $data[$userId] = $tokens->toArray();
        $this->saveAll($data);
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $userId): ?OAuthTokens
    {
        $data = $this->loadAll();

        if (!isset($data[$userId])) {
            return null;
        }

        return OAuthTokens::fromArray($data[$userId]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $userId): void
    {
        $data = $this->loadAll();
        unset($data[$userId]);
        $this->saveAll($data);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $userId): bool
    {
        $data = $this->loadAll();
        return isset($data[$userId]);
    }

    /**
     * Load all tokens from disk.
     *
     * @return array<string, array>
     */
    private function loadAll(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!file_exists($this->filePath)) {
            $this->data = [];
            return $this->data;
        }

        $contents = file_get_contents($this->filePath);

        if ($contents === false) {
            $this->data = [];
            return $this->data;
        }

        try {
            $this->data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->data = [];
        }

        return $this->data;
    }

    /**
     * Persist all tokens to disk.
     *
     * @param array<string, array> $data
     */
    private function saveAll(array $data): void
    {
        $this->data = $data;

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($this->filePath, $json, LOCK_EX);
        chmod($this->filePath, 0600);
    }
}
