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
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (DB_SSL) {
            $caFile = DB_SSL_CA;
            if ($caFile === '' && DB_SSL_CA_BASE64 !== '') {
                $encodedCa = preg_replace('/\s+/', '', DB_SSL_CA_BASE64);
                $decodedCa = base64_decode($encodedCa, true);
                if ($decodedCa === false || !str_contains($decodedCa, 'BEGIN CERTIFICATE')) {
                    throw new RuntimeException('DB_SSL_CA_BASE64 is not a valid base64-encoded PEM certificate.');
                }
                $caFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . 'vacman-db-ca-' . substr(hash('sha256', $decodedCa), 0, 20) . '.pem';
                if (!is_file($caFile) && file_put_contents($caFile, $decodedCa, LOCK_EX) === false) {
                    throw new RuntimeException('Could not materialize DB_SSL_CA_BASE64 in the temporary directory.');
                }
                @chmod($caFile, 0600);
            }
            if ($caFile === '') {
                $certificateLocations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
                $caCandidates = array_filter([
                    $certificateLocations['ini_cafile'] ?? null,
                    $certificateLocations['default_cert_file'] ?? null,
                    '/etc/ssl/certs/ca-certificates.crt',
                    'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
                ]);
                foreach ($caCandidates as $candidate) {
                    if (is_file($candidate) && is_readable($candidate)) {
                        $caFile = $candidate;
                        break;
                    }
                }
            }

            if (DB_SSL_VERIFY_SERVER_CERT && $caFile === '') {
                throw new RuntimeException('Database TLS verification is enabled, but no readable CA bundle was found. Set DB_SSL_CA.');
            }
            foreach (['DB_SSL_CA' => $caFile, 'DB_SSL_CERT' => DB_SSL_CERT, 'DB_SSL_KEY' => DB_SSL_KEY] as $setting => $path) {
                if ($path !== '' && (!is_file($path) || !is_readable($path))) {
                    throw new RuntimeException($setting . ' does not point to a readable file.');
                }
            }

            if ($caFile !== '') $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
            if (DB_SSL_CERT !== '') $options[PDO::MYSQL_ATTR_SSL_CERT] = DB_SSL_CERT;
            if (DB_SSL_KEY !== '') $options[PDO::MYSQL_ATTR_SSL_KEY] = DB_SSL_KEY;
            if (DB_SSL_CIPHER !== '') $options[PDO::MYSQL_ATTR_SSL_CIPHER] = DB_SSL_CIPHER;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = DB_SSL_VERIFY_SERVER_CERT;
        }

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        // Keep MySQL NOW(), CURDATE(), and timestamp comparisons aligned
        // with PHP's configured local timezone, including daylight saving.
        $offsetSeconds = (new DateTimeImmutable('now'))->getOffset();
        $offsetSign = $offsetSeconds < 0 ? '-' : '+';
        $offsetSeconds = abs($offsetSeconds);
        $mysqlOffset = sprintf('%s%02d:%02d', $offsetSign, intdiv($offsetSeconds, 3600), intdiv($offsetSeconds % 3600, 60));
        $pdo->exec('SET time_zone = ' . $pdo->quote($mysqlOffset));
        return $pdo;
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
        }
        require ROOT_PATH . '/errors/500.php';
        exit;
    }
}
