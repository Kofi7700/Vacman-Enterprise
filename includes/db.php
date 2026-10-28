<?php
/**
 * includes/db.php
 * One shared PDO connection, created lazily on first use.
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Keep MySQL NOW(), CURDATE(), and timestamp comparisons aligned
        // with PHP's configured local timezone, including daylight saving.
        $offsetSeconds = (new DateTimeImmutable('now'))->getOffset();
        $offsetSign = $offsetSeconds < 0 ? '-' : '+';
        $offsetSeconds = abs($offsetSeconds);
        $mysqlOffset = sprintf('%s%02d:%02d', $offsetSign, intdiv($offsetSeconds, 3600), intdiv($offsetSeconds % 3600, 60));
        $pdo->exec('SET time_zone = ' . $pdo->quote($mysqlOffset));
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
        }
        require ROOT_PATH . '/errors/500.php';
        exit;
    }
}
