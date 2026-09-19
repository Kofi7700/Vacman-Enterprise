<?php
/**
 * includes/functions.php
 * Small shared helpers used across every page.
 */

/** Escape a value for safe HTML output. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect and stop execution. Always call before any HTML is echoed. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Store a one-time flash message in the session. */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Retrieve and clear all flash messages. */
function flash_get(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Render flash messages as Bootstrap alerts. */
function flash_render(): void
{
    foreach (flash_get() as $f) {
        $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
            . e($f['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

/** Format a number as the app's currency, e.g. GHS ₵1,234.50 */
function money($amount): string
{
    return CURRENCY_SYMBOL . number_format((float) $amount, 2);
}

/** Re-populate a form field from a previous POST (for validation redisplay). */
function old(array $source, string $key, string $default = ''): string
{
    return e((string) ($source[$key] ?? $default));
}

/** True if the current script's basename matches the given file name. */
function is_current_page(string $file): bool
{
    return basename($_SERVER['SCRIPT_NAME']) === $file;
}

/** Basic required-field + type validation helper. Returns array of error strings. */
function validate(array $data, array $rules): array
{
    $errors = [];
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? null;
        $label = ucwords(str_replace('_', ' ', $field));

        if (str_contains($rule, 'required') && ($value === null || trim((string) $value) === '')) {
            $errors[] = "$label is required.";
            continue;
        }
        if ($value === null || trim((string) $value) === '') {
            continue; // optional and empty, skip further checks
        }
        if (str_contains($rule, 'numeric') && !is_numeric($value)) {
            $errors[] = "$label must be a number.";
        }
        if (str_contains($rule, 'int') && filter_var($value, FILTER_VALIDATE_INT) === false) {
            $errors[] = "$label must be a whole number.";
        }
        if (str_contains($rule, 'positive') && is_numeric($value) && (float) $value < 0) {
            $errors[] = "$label cannot be negative.";
        }
        if (str_contains($rule, 'email') && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "$label must be a valid email address.";
        }
    }
    return $errors;
}

/** Insert an audit_log row. Never throws — auditing must not break the request. */
function log_audit(string $action, string $entityType, ?int $entityId = null, ?array $old = null, ?array $new = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip, :ua)'
        );
        $user = current_user();
        $stmt->execute([
            ':user_id' => $user['id'] ?? null,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':old_values' => $old !== null ? json_encode($old) : null,
            ':new_values' => $new !== null ? json_encode($new) : null,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}
