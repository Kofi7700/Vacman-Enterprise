<?php
/**
 * config/config.php
 *
 * Single entry point for app-wide setup. Every page includes this file
 * FIRST, before any HTML output, because it may start a session and
 * send redirect headers.
 */

// ---- Environment -----------------------------------------------------
// Set to false on a live server so raw PHP errors are never shown to
// visitors; everything still gets logged to the PHP error log.
// Reads from the APP_DEBUG environment variable when it's set (e.g. on
// Render), so it can be turned off in production without editing this
// file; defaults to true for local XAMPP development.
$appDebugEnv = getenv('APP_DEBUG');
define('APP_DEBUG', $appDebugEnv !== false ? filter_var($appDebugEnv, FILTER_VALIDATE_BOOLEAN) : true);

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
// Use the workstation's local timezone. This matches the browser/device
// time used by staff when attendance is marked.
date_default_timezone_set('Africa/Accra');

// ---- Branding ----------------------------------------------------------
// One place to change the software name vs. the business running it.
define('APP_NAME', 'Vacman Enterprise');           // the software product
define('BUSINESS_NAME', 'Yellowman Ventures');     // the company using it
define('CURRENCY_SYMBOL', '₵');
define('CURRENCY_CODE', 'GHS');

// ---- Database ------------------------------------------------------
// Reads connection and TLS settings from environment variables when set,
// and falls back to unencrypted local XAMPP defaults for development.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
$dbPortEnv = getenv('DB_PORT');
define('DB_PORT', $dbPortEnv !== false && filter_var($dbPortEnv, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) !== false
    ? (int) $dbPortEnv
    : 3306);
define('DB_NAME', getenv('DB_NAME') ?: 'vacman_enterprise_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
$dbSslEnv = getenv('DB_SSL');
$dbSslVerifyEnv = getenv('DB_SSL_VERIFY_SERVER_CERT');
define('DB_SSL', $dbSslEnv !== false && filter_var($dbSslEnv, FILTER_VALIDATE_BOOLEAN));
define('DB_SSL_VERIFY_SERVER_CERT', $dbSslVerifyEnv === false ? true : filter_var($dbSslVerifyEnv, FILTER_VALIDATE_BOOLEAN));
define('DB_SSL_CA', getenv('DB_SSL_CA') ?: '');
define('DB_SSL_CA_BASE64', getenv('DB_SSL_CA_BASE64') ?: '');
define('DB_SSL_CERT', getenv('DB_SSL_CERT') ?: '');
define('DB_SSL_KEY', getenv('DB_SSL_KEY') ?: '');
define('DB_SSL_CIPHER', getenv('DB_SSL_CIPHER') ?: '');

// ---- Paths -------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));

// Database access is needed before session_start() because sessions are
// shared through MySQL rather than stored on an individual container disk.
require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/session.php';

// ---- Shared session storage ----------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetimeEnv = getenv('SESSION_LIFETIME');
    $sessionLifetime = $sessionLifetimeEnv !== false && ctype_digit($sessionLifetimeEnv) && (int) $sessionLifetimeEnv > 0
        ? (int) $sessionLifetimeEnv
        : 28800;

    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    try {
        $sessionHandler = new DatabaseSessionHandler(db(), $sessionLifetime);
        session_set_save_handler($sessionHandler, true);
    } catch (Throwable $e) {
        error_log('Shared session initialization failed: ' . $e->getMessage());
        http_response_code(500);
        require ROOT_PATH . '/errors/500.php';
        exit;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- Safe error handling (point 5: safe error pages) --------------
set_exception_handler(function (Throwable $e) {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    require ROOT_PATH . '/errors/500.php';
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // Only escalate genuinely fatal-class errors to the 500 page. A routine
    // notice/warning (e.g. an optional array key that wasn't submitted)
    // gets logged and the page carries on — turning every minor warning
    // into a hard stop would make the app brittle against ordinary
    // malformed requests.
    if (in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    error_log("PHP notice/warning: $message in $file:$line");
    return true;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            require ROOT_PATH . '/errors/500.php';
        }
    }
});

// ---- Core includes ---------------------------------------------------
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/csrf.php';
require_once ROOT_PATH . '/includes/auth.php';
