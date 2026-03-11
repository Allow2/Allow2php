<?php

declare(strict_types=1);

namespace Allow2\Cache;

use Allow2\CacheInterface;

/**
 * PDO-based permission cache supporting MySQL, PostgreSQL, and SQLite.
 *
 * Auto-creates the cache table if it does not exist. Expired entries
 * are cleaned up lazily on read.
 */
final class PdoCache implements CacheInterface
{
    private bool $tableCreated = false;

    /**
     * @param \PDO $pdo PDO connection instance.
     * @param string $tableName Table name for cache storage.
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tableName = 'allow2_cache',
    ) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \InvalidArgumentException('Table name must contain only alphanumeric characters and underscores');
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?string
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT cache_value, expires_at FROM {$this->tableName} WHERE cache_key = :key"
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (time() >= (int) $row['expires_at']) {
            $this->delete($key);
            return null;
        }

        return $row['cache_value'];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, string $value, int $ttl = 60): void
    {
        $this->ensureTable();

        $expiresAt = time() + $ttl;
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = "INSERT INTO {$this->tableName} (cache_key, cache_value, expires_at)
                    VALUES (:key, :value, :expires_at)
                    ON DUPLICATE KEY UPDATE
                        cache_value = VALUES(cache_value),
                        expires_at = VALUES(expires_at)";
        } elseif ($driver === 'pgsql') {
            $sql = "INSERT INTO {$this->tableName} (cache_key, cache_value, expires_at)
                    VALUES (:key, :value, :expires_at)
                    ON CONFLICT (cache_key) DO UPDATE SET
                        cache_value = EXCLUDED.cache_value,
                        expires_at = EXCLUDED.expires_at";
        } else {
            $sql = "INSERT OR REPLACE INTO {$this->tableName} (cache_key, cache_value, expires_at)
                    VALUES (:key, :value, :expires_at)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':expires_at' => $expiresAt,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE cache_key = :key");
        $stmt->execute([':key' => $key]);
    }

    /**
     * Remove all expired entries. Call periodically or via cron.
     */
    public function purgeExpired(): int
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->tableName} WHERE expires_at < :now"
        );
        $stmt->execute([':now' => time()]);
        return $stmt->rowCount();
    }

    /**
     * Create the cache table if it does not exist.
     */
    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->tableName} (
                cache_key VARCHAR(255) PRIMARY KEY,
                cache_value TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            )"
        );

        $this->tableCreated = true;
    }
}
