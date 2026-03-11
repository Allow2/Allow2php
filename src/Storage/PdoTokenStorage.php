<?php

declare(strict_types=1);

namespace Allow2\Storage;

use Allow2\Models\OAuthTokens;
use Allow2\TokenStorageInterface;

/**
 * PDO-based token storage supporting MySQL, PostgreSQL, and SQLite.
 *
 * Auto-creates the storage table if it does not exist.
 */
final class PdoTokenStorage implements TokenStorageInterface
{
    private bool $tableCreated = false;

    /**
     * @param \PDO $pdo PDO connection instance.
     * @param string $tableName Table name for token storage.
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tableName = 'allow2_tokens',
    ) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new \InvalidArgumentException('Table name must contain only alphanumeric characters and underscores');
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * {@inheritdoc}
     */
    public function store(string $userId, OAuthTokens $tokens): void
    {
        $this->ensureTable();

        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $data = $tokens->toArray();

        if ($driver === 'mysql') {
            $sql = "INSERT INTO {$this->tableName} (user_id, access_token, refresh_token, expires_at)
                    VALUES (:user_id, :access_token, :refresh_token, :expires_at)
                    ON DUPLICATE KEY UPDATE
                        access_token = VALUES(access_token),
                        refresh_token = VALUES(refresh_token),
                        expires_at = VALUES(expires_at)";
        } elseif ($driver === 'pgsql') {
            $sql = "INSERT INTO {$this->tableName} (user_id, access_token, refresh_token, expires_at)
                    VALUES (:user_id, :access_token, :refresh_token, :expires_at)
                    ON CONFLICT (user_id) DO UPDATE SET
                        access_token = EXCLUDED.access_token,
                        refresh_token = EXCLUDED.refresh_token,
                        expires_at = EXCLUDED.expires_at";
        } else {
            // SQLite and others
            $sql = "INSERT OR REPLACE INTO {$this->tableName} (user_id, access_token, refresh_token, expires_at)
                    VALUES (:user_id, :access_token, :refresh_token, :expires_at)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':access_token' => $data['access_token'],
            ':refresh_token' => $data['refresh_token'],
            ':expires_at' => $data['expires_at'],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $userId): ?OAuthTokens
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT access_token, refresh_token, expires_at FROM {$this->tableName} WHERE user_id = :user_id"
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return OAuthTokens::fromArray([
            'access_token' => $row['access_token'],
            'refresh_token' => $row['refresh_token'],
            'expires_at' => (int) $row['expires_at'],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $userId): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $userId): bool
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE user_id = :user_id"
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Create the storage table if it does not exist.
     */
    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->tableName} (
                user_id VARCHAR(255) PRIMARY KEY,
                access_token TEXT NOT NULL,
                refresh_token TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            )"
        );

        $this->tableCreated = true;
    }
}
