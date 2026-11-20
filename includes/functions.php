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

/** Match the exact 2*6 Essa product, allowing common multiplication symbols. */
function is_essa_2_by_6(?string $productName): bool
{
    return (bool) preg_match('/^2\s*[x*×]\s*6\s+Essa$/iu', trim($productName ?? ''));
}

/** Match the exact 2*8 Wood B/C product, allowing common multiplication symbols. */
function is_wood_bc_2_by_8(?string $productName): bool
{
    return (bool) preg_match('/^2\s*[x*×]\s*8\s+Wood\s+B\s*\/\s*C$/iu', trim($productName ?? ''));
}

/** Match the exact 2*8 Otie product, allowing common multiplication symbols. */
function is_otie_2_by_8(?string $productName): bool
{
    return (bool) preg_match('/^2\s*[x*×]\s*8\s+Otie$/iu', trim($productName ?? ''));
}

/** Match the exact 2*8 Ceiba product, allowing common multiplication symbols. */
function is_ceiba_2_by_8(?string $productName): bool
{
    return (bool) preg_match('/^2\s*[x*×]\s*8\s+Ceiba$/iu', trim($productName ?? ''));
}

/** All sheet and Alluzinc products use the roofing bundle conversion. */
function is_roofing_sheet(?string $productName): bool
{
    return (bool) preg_match('/\b(sheet|alluzinc)\b/iu', trim($productName ?? ''));
}

/** Return the number of pieces in a louvre-glass box for supported glass sizes. */
function louvre_glass_box_size(?string $productName): ?int
{
    $name = trim($productName ?? '');
    if (!preg_match('/\blouvre\s+glass\b/iu', $name)) return null;
    if (preg_match('/\b24\s*(?:"|inch(?:es)?)/iu', $name)) return 40;
    if (preg_match('/\b30\s*(?:"|inch(?:es)?)/iu', $name)) return 32;
    if (preg_match('/\b36\s*(?:"|inch(?:es)?)/iu', $name)) return 27;
    return null;
}

/** Every louvre frame, regardless of blade count, is packed 10 pieces per box. */
function is_louvre_frame(?string $productName): bool
{
    return (bool) preg_match('/\blouvre\s+frame\b/iu', trim($productName ?? ''));
}

/** Nail inventory is stored and priced in pounds; seven pounds make one box. */
function is_nail_product(?string $productName): bool
{
    return (bool) preg_match('/\bnails?\b/iu', trim($productName ?? ''));
}

/** Convert validated nail stock fields into the total pounds stored in inventory. */
function nail_total_pounds(mixed $boxes, mixed $extraPounds): ?int
{
    if (filter_var($boxes, FILTER_VALIDATE_INT) === false || filter_var($extraPounds, FILTER_VALIDATE_INT) === false) return null;
    $boxes = (int) $boxes;
    $extraPounds = (int) $extraPounds;
    if ($boxes < 0 || $extraPounds < 0 || $extraPounds > 6) return null;
    return ($boxes * 7) + $extraPounds;
}

/** Return the scaled dimensional equivalents for supported products. */
function product_dimensional_equivalence(?string $productName, int $pieces): ?string
{
    if ($pieces < 1) return null;

    if (is_essa_2_by_6($productName)) {
        return number_format($pieces * 3) . ' × 2*2 Essa or '
            . number_format($pieces) . ' × 2*2 Essa + ' . number_format($pieces) . ' × 2*4 Essa';
    }

    if (is_wood_bc_2_by_8($productName)) {
        return number_format($pieces * 4) . ' × 2*2 Wood B/C or '
            . number_format($pieces * 2) . ' × 2*2 Wood B/C + ' . number_format($pieces) . ' × 2*4 Wood B/C or '
            . number_format($pieces * 2) . ' × 2*4 Wood B/C';
    }

    if (is_otie_2_by_8($productName)) {
        return number_format($pieces * 4) . ' × 2*2 Otie or '
            . number_format($pieces * 2) . ' × 2*2 Otie + ' . number_format($pieces) . ' × 2*4 Otie or '
            . number_format($pieces * 2) . ' × 2*4 Otie';
    }

    if (is_ceiba_2_by_8($productName)) {
        return number_format($pieces * 2) . ' × 2*4 Ceiba';
    }

    return null;
}

function has_product_dimensional_conversion(?string $productName): bool
{
    return product_dimensional_equivalence($productName, 1) !== null
        || is_roofing_sheet($productName)
        || louvre_glass_box_size($productName) !== null
        || is_louvre_frame($productName)
        || is_nail_product($productName);
}

/** Return the short conversion note shown when a supported product is selected. */
function product_conversion_note(?string $productName): ?string
{
    if (is_nail_product($productName)) return '7 pounds = 1 box';
    if (is_roofing_sheet($productName)) return '20 pieces = 1 bundle';
    $boxSize = louvre_glass_box_size($productName);
    if ($boxSize !== null) return number_format($boxSize) . ' pieces = 1 box';
    if (is_louvre_frame($productName)) return '10 pieces = 1 box';
    $equivalence = product_dimensional_equivalence($productName, 1);
    return $equivalence !== null ? '1 piece = ' . $equivalence : null;
}

/** Format a product quantity while retaining its exact underlying base-unit count. */
function product_quantity_label(?string $productName, int $pieces, bool $showExactPieces = true): string
{
    if (is_nail_product($productName)) {
        $poundLabel = number_format($pieces) . ' ' . ($pieces === 1 ? 'pound' : 'pounds');
        if ($pieces < 7) return $poundLabel;
        $boxes = intdiv($pieces, 7);
        $remainder = $pieces % 7;
        $boxLabel = number_format($boxes) . ' ' . ($boxes === 1 ? 'box' : 'boxes');
        if ($remainder > 0) $boxLabel .= ' + ' . number_format($remainder) . ' ' . ($remainder === 1 ? 'pound' : 'pounds');
        return $showExactPieces ? $boxLabel . ' (' . $poundLabel . ')' : $boxLabel;
    }

    $pieceLabel = number_format($pieces) . ' ' . ($pieces === 1 ? 'piece' : 'pieces');
    $equivalence = product_dimensional_equivalence($productName, $pieces);
    if ($equivalence !== null) {
        return $showExactPieces ? $pieceLabel . ' (' . $equivalence . ')' : $equivalence;
    }

    $boxSize = louvre_glass_box_size($productName);
    if ($boxSize === null && is_louvre_frame($productName)) $boxSize = 10;
    if ($boxSize !== null && $pieces >= $boxSize) {
        $boxes = intdiv($pieces, $boxSize);
        $remainder = $pieces % $boxSize;
        $boxLabel = number_format($boxes) . ' ' . ($boxes === 1 ? 'box' : 'boxes');
        if ($remainder > 0) {
            $boxLabel .= ' + ' . number_format($remainder) . ' ' . ($remainder === 1 ? 'piece' : 'pieces');
        }
        return $showExactPieces ? $boxLabel . ' (' . $pieceLabel . ')' : $boxLabel;
    }

    if (!is_roofing_sheet($productName) || $pieces < 20) return $pieceLabel;

    $bundles = intdiv($pieces, 20);
    $remainder = $pieces % 20;
    $bundleLabel = number_format($bundles) . ' ' . ($bundles === 1 ? 'bundle' : 'bundles');
    if ($remainder > 0) {
        $bundleLabel .= ' + ' . number_format($remainder) . ' ' . ($remainder === 1 ? 'piece' : 'pieces');
    }

    return $showExactPieces ? $bundleLabel . ' (' . $pieceLabel . ')' : $bundleLabel;
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
