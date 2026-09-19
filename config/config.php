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
date_default_timezone_set('Africa/Accra');

// ---- Branding ----------------------------------------------------------
// One place to change the software name vs. the business running it.
define('APP_NAME', 'Vacman Enterprise');           // the software product
define('BUSINESS_NAME', 'Yellowman Ventures');     // the company using it
define('CURRENCY_SYMBOL', '₵');
define('CURRENCY_CODE', 'GHS');

// ---- Database ------------------------------------------------------
// Reads DB_HOST/DB_NAME/DB_USER/DB_PASS from environment variables when
// they're set (e.g. on Render, pointing at the MySQL private service),
// and falls back to XAMPP defaults for local development.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'vacman_enterprise_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ---- Paths -------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));

// ---- Session -------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
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
require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/csrf.php';
require_once ROOT_PATH . '/includes/auth.php';
