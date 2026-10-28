<?php
/**
 * includes/auth.php
 * Session-based auth and role-based authorization.
 *
 * Roles in the `users` table: admin, manager, mini_manager, cashier, warehouse, sales_clerk.
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
    if ($user === null) return false;
    $assignedRoles = $user['roles'] ?? [$user['role'] ?? ''];
    if (!is_array($assignedRoles)) $assignedRoles = array_filter(array_map('trim', explode(',', (string) $assignedRoles)));
    return (bool) array_intersect($roles, $assignedRoles);
}

/** Call at the top of any page that requires a logged-in user. */
function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        redirect('login.php');
    }

    // A frozen account is signed out on its next authenticated request,
    // including sessions that were already open when the account was frozen.
    $stmt = db()->prepare('SELECT status, role, roles, store_id FROM users WHERE id = ?');
    $stmt->execute([(int) current_user()['id']]);
    $account = $stmt->fetch();
    $accountRoles = $account ? array_values(array_filter(array_map('trim', explode(',', $account['roles'] ?: $account['role'])))) : [];
    if (!$account || $account['status'] !== 'active' || in_array('staff', $accountRoles, true)) {
        unset($_SESSION['user']);
        flash_set('warning', in_array('staff', $accountRoles, true)
            ? 'Staff attendance records do not have system login access.'
            : 'This account has been frozen. Contact an administrator.');
        redirect('login.php');
    }

    // Apply role and store assignment changes to already-open sessions.
    $_SESSION['user']['role'] = $account['role'];
    $_SESSION['user']['roles'] = $accountRoles;
    $_SESSION['user']['store_id'] = $account['store_id'] !== null ? (int) $account['store_id'] : null;
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
