<?php
/**
 * includes/csrf.php
 * Synchronizer-token CSRF protection for every state-changing form.
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every <form method="POST">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Call at the top of every POST/DELETE handler before touching the
 * database. Aborts the request with a 403 page on mismatch.
 */
function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, (string) $submitted)) {
        http_response_code(403);
        require ROOT_PATH . '/errors/403.php';
        exit;
    }
}
