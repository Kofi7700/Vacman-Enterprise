<?php
/**
 * includes/auth.php
 * Session-based auth and role-based authorization.
 *
 * Roles in the `users` table: admin, manager, warehouse, sales_clerk.
 */

/** The logged-in user's session data, or null if not logged in. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_role(string ...$roles): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], $roles, true);
}

/** Call at the top of any page that requires a logged-in user. */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        redirect('login.php');
    }
}

/**
 * Call at the top of any page/action restricted to specific roles.
 * Always call require_login() first (or rely on this calling it).
 */
function require_role(string ...$roles): void
{
    require_login();
    if (!is_role(...$roles)) {
        http_response_code(403);
        require ROOT_PATH . '/errors/403.php';
        exit;
    }
}

/**
 * HTML forms can only submit GET/POST. This lets a POST simulate
 * PUT/PATCH/DELETE via a hidden "_method" field, so real delete/update
 * routes can be written and CSRF-checked like any other POST.
 */
function request_method(): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method'])) {
        $override = strtoupper((string) $_POST['_method']);
        if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $override;
        }
    }
    return $_SERVER['REQUEST_METHOD'];
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}
