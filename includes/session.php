<?php
/**
 * Shared MySQL-backed PHP sessions for deployments with multiple instances.
 * Named MySQL locks serialize concurrent requests for the same session and
 * prevent one request from overwriting another request's session changes.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $lockName = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lifetime
    ) {
        try {
            $this->pdo->query('SELECT 1 FROM app_sessions LIMIT 1');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1146) throw $e;
            $this->pdo->exec(
                'CREATE TABLE app_sessions (
                    id VARCHAR(128) NOT NULL,
                    data MEDIUMBLOB NOT NULL,
                    expires_at BIGINT UNSIGNED NOT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_app_sessions_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return $this->releaseLock();
    }

    public function read(string $id): string|false
    {
        try {
            if (!$this->acquireLock($id)) return false;

            $stmt = $this->pdo->prepare('SELECT data FROM app_sessions WHERE id = ? AND expires_at > ?');
            $stmt->execute([$id, time()]);
            $data = $stmt->fetchColumn();
            if ($data === false) {
                $this->pdo->prepare('DELETE FROM app_sessions WHERE id = ?')->execute([$id]);
                return '';
            }
            return (string) $data;
        } catch (PDOException $e) {
            error_log('Session read failed: ' . $e->getMessage());
            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO app_sessions (id, data, expires_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)'
            );
            return $stmt->execute([$id, $data, time() + $this->lifetime]);
        } catch (PDOException $e) {
            error_log('Session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE id = ?');
            $deleted = $stmt->execute([$id]);
            $this->releaseLock();
            return $deleted;
        } catch (PDOException $e) {
            error_log('Session destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE expires_at <= ?');
            $stmt->execute([time()]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Session cleanup failed: ' . $e->getMessage());
            return false;
        }
    }

    public function validateId(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM app_sessions WHERE id = ? AND expires_at > ?');
            $stmt->execute([$id, time()]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (PDOException $e) {
            error_log('Session validation failed: ' . $e->getMessage());
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE app_sessions SET expires_at = ? WHERE id = ?');
            $stmt->execute([time() + $this->lifetime, $id]);
            return $stmt->rowCount() === 1 || $this->write($id, $data);
        } catch (PDOException $e) {
            error_log('Session timestamp update failed: ' . $e->getMessage());
            return false;
        }
    }

    private function acquireLock(string $id): bool
    {
        $this->lockName = 'vs:' . substr(hash('sha256', $id), 0, 60);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$this->lockName]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function releaseLock(): bool
    {
        if ($this->lockName === null) return true;

        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$this->lockName]);
            $this->lockName = null;
            return true;
        } catch (PDOException $e) {
            error_log('Session lock release failed: ' . $e->getMessage());
            return false;
        }
    }
}
