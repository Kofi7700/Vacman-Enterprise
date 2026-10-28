<?php
require_once __DIR__ . '/config/config.php';
require_role('admin');

$pdo = db();
$roles = ['admin', 'manager', 'mini_manager', 'cashier', 'warehouse', 'sales_clerk', 'staff'];
$storeRequiredRoles = ['mini_manager', 'cashier', 'warehouse', 'sales_clerk', 'staff'];
$accountStatuses = ['active', 'frozen'];
$currentUserId = (int) current_user()['id'];
$stores = $pdo->query("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name")->fetchAll();

function normalize_user_roles(mixed $value, array $allowedRoles): array
{
    if (!is_array($value)) return [];
    return array_values(array_intersect($allowedRoles, array_unique(array_filter($value, 'is_string'))));
}

// ---- Create user -----------------------------------------------------
if (is_post() && isset($_POST['add_user'])) {
    verify_csrf();
    $errors = validate($_POST, [
        'username' => 'required',
        'full_name' => 'required',
        'password' => 'required',
        'status' => 'required',
        'email' => 'email',
    ]);
    $selectedRoles = normalize_user_roles($_POST['roles'] ?? [], $roles);
    if (!$selectedRoles) $errors[] = 'Select at least one access role.';
    if (!in_array($_POST['status'] ?? '', $accountStatuses, true)) $errors[] = 'Invalid account status selected.';
    if (strlen($_POST['password'] ?? '') < 8) $errors[] = 'Password must be at least 8 characters.';
    if (trim($_POST['username'] ?? '') === '') $errors[] = 'Username is required.';
    if (trim($_POST['full_name'] ?? '') === '') $errors[] = 'Full name is required.';
    $primaryRole = $selectedRoles[0] ?? 'sales_clerk';
    $requiresStore = (bool) array_intersect($selectedRoles, $storeRequiredRoles);
    $storeId = $requiresStore ? (int) ($_POST['store_id'] ?? 0) : null;
    if ($requiresStore && $storeId <= 0) $errors[] = 'Select the store where this user operates.';
    if ($storeId) {
        $storeStmt = $pdo->prepare("SELECT COUNT(*) FROM stores WHERE id = ? AND status = 'active'");
        $storeStmt->execute([$storeId]);
        if (!(int) $storeStmt->fetchColumn()) $errors[] = 'The selected store is not available.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, full_name, email, phone, role, roles, store_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                trim($_POST['username']),
                password_hash($_POST['password'], PASSWORD_DEFAULT),
                trim($_POST['full_name']),
                trim($_POST['email'] ?? '') ?: null,
                trim($_POST['phone'] ?? '') ?: null,
                $primaryRole,
                implode(',', $selectedRoles),
                $storeId,
                $_POST['status'],
            ]);
            $id = (int) $pdo->lastInsertId();
            log_audit('create', 'user', $id, null, [
                'username' => trim($_POST['username']),
                'roles' => $selectedRoles,
                'store_id' => $storeId,
                'status' => $_POST['status'],
            ]);
            flash_set('success', 'User account created.');
        } catch (PDOException $e) {
            error_log('Add user failed: ' . $e->getMessage());
            flash_set('danger', str_contains($e->getMessage(), 'Duplicate entry') ? 'That username is already taken.' : 'Could not create the user.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('admin.php');
}

// ---- Freeze or reactivate user --------------------------------------
if (is_post() && isset($_POST['toggle_user_status'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';

    if (!in_array($newStatus, $accountStatuses, true)) {
        flash_set('danger', 'Invalid account status.');
        redirect('admin.php');
    }
    if ($id === $currentUserId && $newStatus === 'frozen') {
        flash_set('danger', 'You cannot freeze your own account.');
        redirect('admin.php');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT username, role, roles, status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) throw new RuntimeException('User account not found.');

        if (in_array('admin', explode(',', $targetUser['roles'] ?: $targetUser['role']), true) && $targetUser['status'] === 'active' && $newStatus === 'frozen') {
            $activeAdmins = $pdo->query("SELECT id FROM users WHERE FIND_IN_SET('admin', COALESCE(NULLIF(roles, ''), role)) AND status = 'active' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if (count($activeAdmins) <= 1) throw new RuntimeException('You cannot freeze the last active administrator.');
        }

        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        $pdo->commit();
        log_audit('status_change', 'user', $id, ['status' => $targetUser['status']], ['status' => $newStatus]);
        flash_set('success', $newStatus === 'frozen' ? 'User account frozen.' : 'User account reactivated.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('danger', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('User status update failed: ' . $e->getMessage());
        flash_set('danger', 'Could not update the account status.');
    }
    redirect('admin.php');
}

// ---- Edit user -------------------------------------------------------
if (is_post() && isset($_POST['edit_user'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $errors = validate($_POST, [
        'username' => 'required',
        'full_name' => 'required',
        'status' => 'required',
        'email' => 'email',
    ]);
    $selectedRoles = normalize_user_roles($_POST['roles'] ?? [], $roles);
    if (!$selectedRoles) $errors[] = 'Select at least one access role.';
    if (!in_array($_POST['status'] ?? '', $accountStatuses, true)) $errors[] = 'Invalid account status selected.';
    if (!empty($_POST['new_password']) && strlen($_POST['new_password']) < 8) $errors[] = 'The new password must be at least 8 characters.';
    if (trim($_POST['username'] ?? '') === '') $errors[] = 'Username is required.';
    if (trim($_POST['full_name'] ?? '') === '') $errors[] = 'Full name is required.';
    if ($id === $currentUserId && ($_POST['status'] ?? '') === 'frozen') $errors[] = 'You cannot freeze your own account.';
    $primaryRole = $selectedRoles[0] ?? 'sales_clerk';
    $requiresStore = (bool) array_intersect($selectedRoles, $storeRequiredRoles);
    $storeId = $requiresStore ? (int) ($_POST['store_id'] ?? 0) : null;
    if ($requiresStore && $storeId <= 0) $errors[] = 'Select the store where this user operates.';
    if ($storeId) {
        $storeStmt = $pdo->prepare("SELECT COUNT(*) FROM stores WHERE id = ? AND status = 'active'");
        $storeStmt->execute([$storeId]);
        if (!(int) $storeStmt->fetchColumn()) $errors[] = 'The selected store is not available.';
    }

    if (!$errors && $id > 0) {
        try {
            $pdo->beginTransaction();
            $existingStmt = $pdo->prepare('SELECT role, roles, status FROM users WHERE id = ? FOR UPDATE');
            $existingStmt->execute([$id]);
            $existingUser = $existingStmt->fetch();
            if (!$existingUser) throw new RuntimeException('User account not found.');

            $existingRoles = explode(',', $existingUser['roles'] ?: $existingUser['role']);
            if (in_array('admin', $existingRoles, true) && $existingUser['status'] === 'active'
                && (!in_array('admin', $selectedRoles, true) || ($_POST['status'] ?? '') !== 'active')) {
                $activeAdmins = $pdo->query("SELECT id FROM users WHERE FIND_IN_SET('admin', COALESCE(NULLIF(roles, ''), role)) AND status = 'active' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
                if (count($activeAdmins) <= 1) throw new RuntimeException('You cannot demote or freeze the last active administrator.');
            }

            $stmt = $pdo->prepare('UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, role = ?, roles = ?, store_id = ?, status = ? WHERE id = ?');
            $stmt->execute([
                trim($_POST['username']),
                trim($_POST['full_name']),
                trim($_POST['email'] ?? '') ?: null,
                trim($_POST['phone'] ?? '') ?: null,
                $primaryRole,
                implode(',', $selectedRoles),
                $storeId,
                $_POST['status'],
                $id,
            ]);
            if (!empty($_POST['new_password'])) {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $id]);
            }
            $pdo->commit();

            if ($id === $currentUserId) {
                $_SESSION['user']['username'] = trim($_POST['username']);
                $_SESSION['user']['full_name'] = trim($_POST['full_name']);
                $_SESSION['user']['role'] = $primaryRole;
                $_SESSION['user']['roles'] = $selectedRoles;
                $_SESSION['user']['store_id'] = $storeId;
            }
            log_audit('update', 'user', $id, null, [
                'username' => trim($_POST['username']),
                'full_name' => trim($_POST['full_name']),
                'roles' => $selectedRoles,
                'store_id' => $storeId,
                'status' => $_POST['status'],
                'password_changed' => !empty($_POST['new_password']),
            ]);
            flash_set('success', 'User updated.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('danger', $e->getMessage());
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Edit user failed: ' . $e->getMessage());
            flash_set('danger', str_contains($e->getMessage(), 'Duplicate entry') ? 'That username is already taken.' : 'Could not update the user.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)) ?: 'Invalid user account.');
    }
    redirect('admin.php');
}

// ---- Data, filters and dashboard summary -----------------------------
$search = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
if ($roleFilter !== 'all' && !in_array($roleFilter, $roles, true)) $roleFilter = 'all';
if ($statusFilter !== 'all' && !in_array($statusFilter, $accountStatuses, true)) $statusFilter = 'all';

$sql = "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.roles, u.store_id, u.status, u.created_at,
               st.name AS store_name,
               (SELECT COUNT(*) FROM sales s WHERE s.user_id = u.id) AS sales_count
        FROM users u
        LEFT JOIN stores st ON st.id = u.store_id";
$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(u.full_name LIKE :q_name OR u.username LIKE :q_username OR u.email LIKE :q_email OR u.phone LIKE :q_phone OR st.name LIKE :q_store)';
    $params[':q_name'] = '%' . $search . '%';
    $params[':q_username'] = '%' . $search . '%';
    $params[':q_email'] = '%' . $search . '%';
    $params[':q_phone'] = '%' . $search . '%';
    $params[':q_store'] = '%' . $search . '%';
}
if ($roleFilter !== 'all') { $conditions[] = "FIND_IN_SET(:role, COALESCE(NULLIF(u.roles, ''), u.role))"; $params[':role'] = $roleFilter; }
if ($statusFilter !== 'all') { $conditions[] = 'u.status = :status'; $params[':status'] = $statusFilter; }
if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
$sql .= ' ORDER BY u.full_name';
$userStmt = $pdo->prepare($sql);
$userStmt->execute($params);
$users = $userStmt->fetchAll();

$userMetrics = $pdo->query("SELECT COUNT(*) AS total_users,
    COALESCE(SUM(status = 'active'), 0) AS active_users,
    COALESCE(SUM(FIND_IN_SET('admin', COALESCE(NULLIF(roles, ''), role)) > 0 AND status = 'active'), 0) AS active_admins,
    COALESCE(SUM(status = 'frozen'), 0) AS frozen_users
    FROM users")->fetch();
$roleCounts = array_fill_keys($roles, 0);
foreach ($pdo->query("SELECT COALESCE(NULLIF(roles, ''), role) AS assigned_roles FROM users")->fetchAll(PDO::FETCH_COLUMN) as $assignedRoleList) {
    foreach (explode(',', $assignedRoleList) as $assignedRole) {
        if (array_key_exists($assignedRole, $roleCounts)) $roleCounts[$assignedRole]++;
    }
}

// ---- Full audit trail ------------------------------------------------
$auditUserFilter = (int) ($_GET['audit_user'] ?? 0);
$auditActionFilter = trim($_GET['audit_action'] ?? 'all');
$auditFrom = trim($_GET['audit_from'] ?? '');
$auditTo = trim($_GET['audit_to'] ?? '');
$auditPage = max(1, (int) ($_GET['audit_page'] ?? 1));
$auditPerPage = 20;
$auditActions = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
if ($auditActionFilter !== 'all' && !in_array($auditActionFilter, $auditActions, true)) $auditActionFilter = 'all';
foreach (['auditFrom', 'auditTo'] as $dateVariable) {
    $dateValue = $$dateVariable;
    $parsed = $dateValue !== '' ? DateTime::createFromFormat('!Y-m-d', $dateValue) : false;
    if ($dateValue !== '' && (!$parsed || $parsed->format('Y-m-d') !== $dateValue)) $$dateVariable = '';
}
if ($auditFrom !== '' && $auditTo !== '' && $auditFrom > $auditTo) [$auditFrom, $auditTo] = [$auditTo, $auditFrom];

$auditConditions = [];
$auditParams = [];
if ($auditUserFilter > 0) { $auditConditions[] = 'a.user_id = :audit_user'; $auditParams[':audit_user'] = $auditUserFilter; }
if ($auditActionFilter !== 'all') { $auditConditions[] = 'a.action = :audit_action'; $auditParams[':audit_action'] = $auditActionFilter; }
if ($auditFrom !== '') { $auditConditions[] = 'a.created_at >= :audit_from'; $auditParams[':audit_from'] = $auditFrom . ' 00:00:00'; }
if ($auditTo !== '') { $auditConditions[] = 'a.created_at <= :audit_to'; $auditParams[':audit_to'] = $auditTo . ' 23:59:59'; }
$auditWhere = $auditConditions ? ' WHERE ' . implode(' AND ', $auditConditions) : '';

$auditCountStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log a' . $auditWhere);
$auditCountStmt->execute($auditParams);
$auditTotal = (int) $auditCountStmt->fetchColumn();
$auditPages = max(1, (int) ceil($auditTotal / $auditPerPage));
$auditPage = min($auditPage, $auditPages);
$auditOffset = ($auditPage - 1) * $auditPerPage;
$auditSql = "SELECT a.*, u.full_name, u.username, u.role, st.name AS store_name
    FROM audit_log a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN stores st ON st.id = u.store_id" . $auditWhere . "
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT {$auditPerPage} OFFSET {$auditOffset}";
$auditStmt = $pdo->prepare($auditSql);
$auditStmt->execute($auditParams);
$auditRows = $auditStmt->fetchAll();
$auditUsers = $pdo->query('SELECT id, full_name, username FROM users ORDER BY full_name')->fetchAll();
$hasAuditFilters = $auditUserFilter > 0 || $auditActionFilter !== 'all' || $auditFrom !== '' || $auditTo !== '';
$auditFilterQuery = [
    'section' => 'audit',
    'audit_user' => $auditUserFilter ?: null,
    'audit_action' => $auditActionFilter !== 'all' ? $auditActionFilter : null,
    'audit_from' => $auditFrom ?: null,
    'audit_to' => $auditTo ?: null,
];
$auditFilterQuery = array_filter($auditFilterQuery, static fn ($value) => $value !== null && $value !== '');

function user_role_label(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

function user_role_icon(string $role): string
{
    return match ($role) {
        'admin' => 'shield-check',
        'manager' => 'briefcase',
        'mini_manager' => 'person-workspace',
        'cashier' => 'cash-coin',
        'staff' => 'person-badge',
        'warehouse' => 'boxes',
        default => 'bag-check',
    };
}

function audit_action_icon(string $action): string
{
    return match ($action) {
        'create' => 'plus-lg',
        'update' => 'pencil',
        'delete' => 'trash',
        'status_change' => 'arrow-repeat',
        'login' => 'box-arrow-in-right',
        'logout' => 'box-arrow-right',
        'approve_payment' => 'cash-coin',
        'correct_receipt' => 'receipt-cutoff',
        'stock_adjustment' => 'boxes',
        'change_password' => 'key',
        default => 'activity',
    };
}

function audit_value_label(mixed $value): string
{
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if ($value === null || $value === '') return 'None';
    if (is_array($value)) return implode(', ', array_map('strval', $value));
    return (string) $value;
}

$showAuditPage = ($_GET['section'] ?? '') === 'audit';
$pageTitle = $showAuditPage ? 'Audit Trail' : 'User Management';
require __DIR__ . '/includes/header.php';
?>

<?php if (!$showAuditPage): ?>
<section class="user-hero mb-4">
  <div>
    <span class="user-overline"><i class="bi bi-shield-lock me-2"></i>Access administration</span>
    <h2>Give every team member the right access.</h2>
    <p>Create accounts, assign operational roles, review activity, and suspend access when necessary.</p>
  </div>
  <button class="btn user-new-button" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus me-2"></i>Add user</button>
</section>

<section class="user-kpi-grid mb-4" aria-label="User account summary">
  <div class="user-kpi user-kpi-total"><span class="user-kpi-icon"><i class="bi bi-people"></i></span><div><span>Total users</span><strong><?= (int) $userMetrics['total_users'] ?></strong><small>All registered accounts</small></div></div>
  <div class="user-kpi user-kpi-active"><span class="user-kpi-icon"><i class="bi bi-person-check"></i></span><div><span>Active accounts</span><strong><?= (int) $userMetrics['active_users'] ?></strong><small>Can currently sign in</small></div></div>
  <div class="user-kpi user-kpi-admin"><span class="user-kpi-icon"><i class="bi bi-shield-check"></i></span><div><span>Active admins</span><strong><?= (int) $userMetrics['active_admins'] ?></strong><small>Full system access</small></div></div>
  <div class="user-kpi user-kpi-frozen"><span class="user-kpi-icon"><i class="bi bi-snow"></i></span><div><span>Frozen accounts</span><strong><?= (int) $userMetrics['frozen_users'] ?></strong><small>Access currently suspended</small></div></div>
</section>

<div class="row g-4 align-items-start">
  <div class="col-12">
    <section class="user-panel">
      <header class="user-panel-header">
        <div><span class="user-section-label">Account directory</span><h3>Team members</h3></div>
        <span class="user-result-count"><?= count($users) ?> result<?= count($users) === 1 ? '' : 's' ?></span>
      </header>

      <form class="user-filters" method="GET" action="admin.php" role="search">
        <div class="user-search"><i class="bi bi-search"></i><input type="search" class="form-control" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Search name, username, email, or phone"></div>
        <select class="form-select" name="role" aria-label="Filter by role"><option value="all">All roles</option><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>><?= e(user_role_label($role)) ?></option><?php endforeach; ?></select>
        <select class="form-select" name="status" aria-label="Filter by status"><option value="all">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="frozen" <?= $statusFilter === 'frozen' ? 'selected' : '' ?>>Frozen</option></select>
        <button class="btn user-filter-button" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
        <?php if ($search !== '' || $roleFilter !== 'all' || $statusFilter !== 'all'): ?><a href="admin.php" class="btn user-clear-button" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </form>

      <div class="user-role-strip" aria-label="Users by role">
        <?php foreach ($roles as $role): ?><span><i class="bi bi-<?= user_role_icon($role) ?>"></i><?= e(user_role_label($role)) ?><strong><?= (int) ($roleCounts[$role] ?? 0) ?></strong></span><?php endforeach; ?>
      </div>

      <div class="table-responsive user-table-wrap">
        <table class="table align-middle user-table mb-0">
          <thead><tr><th>User</th><th>Roles</th><th>Store</th><th>Contact</th><th>Activity</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
            <?php if (!$users): ?>
            <tr><td colspan="7"><div class="user-empty"><i class="bi bi-people"></i><strong>No users found</strong><span>Try another search, role, store, or account status.</span></div></td></tr>
            <?php else: foreach ($users as $user): $isCurrentUser = (int) $user['id'] === $currentUserId; $userRoles = explode(',', $user['roles'] ?: $user['role']); ?>
            <tr>
              <td><div class="user-identity"><span><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span><div><strong><?= e($user['full_name']) ?><?php if ($isCurrentUser): ?><em>You</em><?php endif; ?></strong><small>@<?= e($user['username']) ?> · Joined <?= e(date('M Y', strtotime($user['created_at']))) ?></small></div></div></td>
              <td><div class="user-role-list"><?php foreach ($userRoles as $userRole): ?><span class="user-role role-<?= e($userRole) ?>"><i class="bi bi-<?= user_role_icon($userRole) ?>"></i><?= e(user_role_label($userRole)) ?></span><?php endforeach; ?></div></td>
              <td><?php if ($user['store_name']): ?><span class="user-store"><i class="bi bi-shop"></i><?= e($user['store_name']) ?></span><?php else: ?><span class="user-store global"><i class="bi bi-globe2"></i>All stores</span><?php endif; ?></td>
              <td><div class="user-contact"><?php if ($user['email']): ?><a href="mailto:<?= e($user['email']) ?>"><i class="bi bi-envelope"></i><?= e($user['email']) ?></a><?php else: ?><span><i class="bi bi-envelope"></i>No email</span><?php endif; ?><?php if ($user['phone']): ?><a href="tel:<?= e($user['phone']) ?>"><i class="bi bi-telephone"></i><?= e($user['phone']) ?></a><?php endif; ?></div></td>
              <td><div class="user-activity"><strong><?= number_format((int) $user['sales_count']) ?></strong><small>sales recorded</small></div></td>
              <td><span class="user-status <?= e($user['status']) ?>"><i></i><?= e(ucfirst($user['status'])) ?></span></td>
              <td class="text-end"><div class="user-actions">
                <button type="button" class="btn user-edit-button" data-bs-toggle="modal" data-bs-target="#editUserModal" data-user-id="<?= (int) $user['id'] ?>" data-user-name="<?= e($user['full_name']) ?>" data-user-username="<?= e($user['username']) ?>" data-user-email="<?= e($user['email'] ?? '') ?>" data-user-phone="<?= e($user['phone'] ?? '') ?>" data-user-roles="<?= e(implode(',', $userRoles)) ?>" data-user-store="<?= e((string) ($user['store_id'] ?? '')) ?>" data-user-status="<?= e($user['status']) ?>"><i class="bi bi-pencil"></i><span>Edit</span></button>
                <form method="POST" action="admin.php" data-confirm="<?= $user['status'] === 'active' ? 'Freeze this user account?' : 'Reactivate this user account?' ?>"><?= csrf_field() ?><input type="hidden" name="toggle_user_status" value="1"><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="status" value="<?= $user['status'] === 'active' ? 'frozen' : 'active' ?>"><button type="submit" class="btn user-status-button <?= $user['status'] === 'active' ? 'freeze' : 'activate' ?>" title="<?= $user['status'] === 'active' ? 'Freeze account' : 'Reactivate account' ?>" aria-label="<?= $user['status'] === 'active' ? 'Freeze' : 'Reactivate' ?> <?= e($user['full_name']) ?>" <?= $isCurrentUser && $user['status'] === 'active' ? 'disabled' : '' ?>><i class="bi bi-<?= $user['status'] === 'active' ? 'snow' : 'unlock' ?>"></i></button></form>
              </div></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

</div>

<?php else: ?>
<section class="audit-panel" id="auditTrail">
  <header class="audit-panel-header">
    <div><span class="user-section-label">Security and accountability</span><h3>Complete audit trail</h3><p>Review recorded actions across every user and operational area.</p></div>
    <span class="audit-total"><i class="bi bi-database-check"></i><?= number_format($auditTotal) ?> event<?= $auditTotal === 1 ? '' : 's' ?></span>
  </header>

  <form class="audit-filters" method="GET" action="admin.php#auditTrail">
    <input type="hidden" name="section" value="audit">
    <select class="form-select" name="audit_user" aria-label="Filter audit trail by user">
      <option value="0">All users</option>
      <?php foreach ($auditUsers as $auditUser): ?><option value="<?= (int) $auditUser['id'] ?>" <?= $auditUserFilter === (int) $auditUser['id'] ? 'selected' : '' ?>><?= e($auditUser['full_name']) ?> (@<?= e($auditUser['username']) ?>)</option><?php endforeach; ?>
    </select>
    <select class="form-select" name="audit_action" aria-label="Filter audit trail by action">
      <option value="all">All actions</option>
      <?php foreach ($auditActions as $auditAction): ?><option value="<?= e($auditAction) ?>" <?= $auditActionFilter === $auditAction ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $auditAction))) ?></option><?php endforeach; ?>
    </select>
    <div class="audit-date-field"><label for="auditFrom">From</label><input class="form-control" type="date" name="audit_from" id="auditFrom" value="<?= e($auditFrom) ?>"></div>
    <div class="audit-date-field"><label for="auditTo">To</label><input class="form-control" type="date" name="audit_to" id="auditTo" value="<?= e($auditTo) ?>"></div>
    <button class="btn audit-filter-button" type="submit"><i class="bi bi-funnel"></i><span>Filter</span></button>
    <?php if ($hasAuditFilters): ?><a class="btn audit-clear-button" href="admin.php?section=audit#auditTrail" title="Clear audit filters" aria-label="Clear audit filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
  </form>

  <div class="table-responsive audit-table-wrap">
    <table class="table audit-table align-middle mb-0">
      <thead><tr><th>User</th><th>Action</th><th>Target</th><th>Details</th><th>Source</th><th>Date and time</th></tr></thead>
      <tbody>
        <?php if (!$auditRows): ?>
        <tr><td colspan="6"><div class="user-empty"><i class="bi bi-clock-history"></i><strong>No audit events found</strong><span>Try changing the user, action, or date filters.</span></div></td></tr>
        <?php else: foreach ($auditRows as $audit):
          $oldValues = json_decode($audit['old_values'] ?? '', true);
          $newValues = json_decode($audit['new_values'] ?? '', true);
          $oldValues = is_array($oldValues) ? $oldValues : [];
          $newValues = is_array($newValues) ? $newValues : [];
        ?>
        <tr>
          <td><div class="audit-user"><span><?= e(strtoupper(substr($audit['full_name'] ?? 'S', 0, 1))) ?></span><div><strong><?= e($audit['full_name'] ?? 'System') ?></strong><small><?= $audit['username'] ? '@' . e($audit['username']) : 'Automated event' ?><?= $audit['store_name'] ? ' · ' . e($audit['store_name']) : '' ?></small></div></div></td>
          <td><span class="audit-action action-<?= e($audit['action']) ?>"><i class="bi bi-<?= audit_action_icon($audit['action']) ?>"></i><?= e(ucwords(str_replace('_', ' ', $audit['action']))) ?></span></td>
          <td><div class="audit-target"><strong><?= e(ucwords(str_replace('_', ' ', $audit['entity_type']))) ?></strong><small><?= $audit['entity_id'] ? '#' . (int) $audit['entity_id'] : 'General' ?></small></div></td>
          <td><div class="audit-changes">
            <?php if ($newValues): foreach ($newValues as $key => $value): ?>
            <span><b><?= e(ucwords(str_replace('_', ' ', (string) $key))) ?></b><?php if (array_key_exists($key, $oldValues) && $oldValues[$key] !== $value): ?><del><?= e(audit_value_label($oldValues[$key])) ?></del><i class="bi bi-arrow-right"></i><?php endif; ?><em><?= e(audit_value_label($value)) ?></em></span>
            <?php endforeach; elseif ($oldValues): foreach ($oldValues as $key => $value): ?>
            <span><b><?= e(ucwords(str_replace('_', ' ', (string) $key))) ?></b><del><?= e(audit_value_label($value)) ?></del></span>
            <?php endforeach; else: ?><small>No field details</small><?php endif; ?>
          </div></td>
          <td><div class="audit-source"><strong><?= e($audit['ip_address'] ?: 'Unknown IP') ?></strong><small title="<?= e($audit['user_agent'] ?? '') ?>"><?= e($audit['user_agent'] ? substr($audit['user_agent'], 0, 34) . (strlen($audit['user_agent']) > 34 ? '…' : '') : 'Unknown device') ?></small></div></td>
          <td><time class="audit-time" datetime="<?= e(date('c', strtotime($audit['created_at']))) ?>"><strong><?= e(date('M j, Y', strtotime($audit['created_at']))) ?></strong><small><?= e(date('g:i:s A', strtotime($audit['created_at']))) ?></small></time></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($auditPages > 1): ?>
  <nav class="audit-pagination" aria-label="Audit trail pages">
    <a class="btn <?= $auditPage <= 1 ? 'disabled' : '' ?>" href="admin.php?<?= e(http_build_query(array_merge($auditFilterQuery, ['audit_page' => max(1, $auditPage - 1)]))) ?>#auditTrail"><i class="bi bi-chevron-left"></i>Previous</a>
    <span>Page <?= $auditPage ?> of <?= $auditPages ?></span>
    <a class="btn <?= $auditPage >= $auditPages ? 'disabled' : '' ?>" href="admin.php?<?= e(http_build_query(array_merge($auditFilterQuery, ['audit_page' => min($auditPages, $auditPage + 1)]))) ?>#auditTrail">Next<i class="bi bi-chevron-right"></i></a>
  </nav>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$showAuditPage): ?>
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content user-modal"><form method="POST" action="admin.php">
    <?= csrf_field() ?><input type="hidden" name="add_user" value="1">
    <div class="modal-header user-modal-header"><div><h5 class="modal-title" id="addUserTitle"><i class="bi bi-person-plus me-2 text-success"></i>Create user account</h5><small>Add account details, access role, and initial status.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body p-4"><div class="row g-3">
      <div class="col-md-7"><label class="form-label" for="addUserName">Full name</label><input type="text" class="form-control" name="full_name" id="addUserName" maxlength="100" autocomplete="name" required></div>
      <div class="col-md-5"><label class="form-label" for="addUsername">Username</label><div class="input-group"><span class="input-group-text">@</span><input type="text" class="form-control" name="username" id="addUsername" maxlength="50" autocomplete="username" required></div></div>
      <div class="col-md-7"><label class="form-label" for="addUserEmail">Email <span class="text-muted fw-normal">(optional)</span></label><input type="email" class="form-control" name="email" id="addUserEmail" maxlength="100" autocomplete="email"></div>
      <div class="col-md-5"><label class="form-label" for="addUserPhone">Phone <span class="text-muted fw-normal">(optional)</span></label><input type="tel" class="form-control" name="phone" id="addUserPhone" maxlength="20" autocomplete="tel"></div>
      <div class="col-md-6"><fieldset><legend class="form-label">Access roles</legend><div class="user-role-options" id="addUserRoles"><?php foreach ($roles as $role): ?><label><input type="checkbox" name="roles[]" value="<?= e($role) ?>" <?= $role === 'sales_clerk' ? 'checked' : '' ?>><span><?= e(user_role_label($role)) ?></span></label><?php endforeach; ?></div></fieldset></div>
      <div class="col-md-6"><label class="form-label" for="addUserStatus">Account status</label><select class="form-select" name="status" id="addUserStatus" required><option value="active" selected>Active</option><option value="frozen">Frozen</option></select></div>
      <div class="col-12"><label class="form-label" for="addUserStore">Operating store</label><select class="form-select" name="store_id" id="addUserStore"><option value="">Select a store</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?></select><small class="user-password-help"><i class="bi bi-shop"></i>Required for mini managers, cashiers, warehouse staff, sales clerks, and non-login staff.</small></div>
      <div class="col-12"><label class="form-label" for="addUserPassword">Temporary password</label><div class="user-password-field"><input type="password" class="form-control" name="password" id="addUserPassword" minlength="8" autocomplete="new-password" required><button type="button" class="user-password-toggle" data-password-target="addUserPassword" aria-label="Show password"><i class="bi bi-eye"></i></button></div><small class="user-password-help"><i class="bi bi-shield-check"></i>Use at least 8 characters. Share it securely with the new user.</small></div>
    </div></div>
    <div class="modal-footer border-0 px-4 pb-4 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn user-save-button"><i class="bi bi-person-check me-2"></i>Create account</button></div>
  </form></div></div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content user-modal"><form method="POST" action="admin.php">
    <?= csrf_field() ?><input type="hidden" name="edit_user" value="1"><input type="hidden" name="id" id="editUserId">
    <div class="modal-header user-modal-header"><div><h5 class="modal-title" id="editUserTitle"><i class="bi bi-person-gear me-2 text-success"></i>Edit user account</h5><small>Update identity, role, access status, or password.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body p-4"><div class="row g-3">
      <div class="col-md-7"><label class="form-label" for="editUserName">Full name</label><input type="text" class="form-control" name="full_name" id="editUserName" maxlength="100" required></div>
      <div class="col-md-5"><label class="form-label" for="editUsername">Username</label><div class="input-group"><span class="input-group-text">@</span><input type="text" class="form-control" name="username" id="editUsername" maxlength="50" required></div></div>
      <div class="col-md-7"><label class="form-label" for="editUserEmail">Email</label><input type="email" class="form-control" name="email" id="editUserEmail" maxlength="100"></div>
      <div class="col-md-5"><label class="form-label" for="editUserPhone">Phone</label><input type="tel" class="form-control" name="phone" id="editUserPhone" maxlength="20"></div>
      <div class="col-md-6"><fieldset><legend class="form-label">Access roles</legend><div class="user-role-options" id="editUserRoles"><?php foreach ($roles as $role): ?><label><input type="checkbox" name="roles[]" value="<?= e($role) ?>"><span><?= e(user_role_label($role)) ?></span></label><?php endforeach; ?></div></fieldset></div>
      <div class="col-md-6"><label class="form-label" for="editUserStatus">Account status</label><select class="form-select" name="status" id="editUserStatus" required><option value="active">Active</option><option value="frozen">Frozen</option></select></div>
      <div class="col-12"><label class="form-label" for="editUserStore">Operating store</label><select class="form-select" name="store_id" id="editUserStore"><option value="">Select a store</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?></select><small class="user-password-help"><i class="bi bi-shop"></i>Required for mini managers, cashiers, warehouse staff, sales clerks, and non-login staff.</small></div>
      <div class="col-12"><label class="form-label" for="editUserPassword">New password <span class="text-muted fw-normal">(optional)</span></label><div class="user-password-field"><input type="password" class="form-control" name="new_password" id="editUserPassword" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep the current password"><button type="button" class="user-password-toggle" data-password-target="editUserPassword" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
    </div><div class="user-access-note mt-4"><i class="bi bi-info-circle"></i><div><strong>Access changes apply immediately</strong><small>Frozen users are signed out on their next request. The final active administrator cannot be frozen or demoted.</small></div></div></div>
    <div class="modal-footer border-0 px-4 pb-4 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn user-save-button"><i class="bi bi-check2 me-2"></i>Save changes</button></div>
  </form></div></div>
</div>
<?php endif; ?>

<?php
$extraScripts = '<script>
(function () {
  const storeRequiredRoles = ["mini_manager", "cashier", "warehouse", "sales_clerk", "staff"];

  function selectedRoles(roleContainer) {
    if (!roleContainer) return [];
    return Array.from(roleContainer.querySelectorAll("input[type=checkbox]:checked")).map(function (input) { return input.value; });
  }

  function syncStoreField(roleContainer, storeField) {
    if (!roleContainer || !storeField) return;
    const required = selectedRoles(roleContainer).some(function (role) { return storeRequiredRoles.includes(role); });
    storeField.disabled = !required;
    storeField.required = required;
    if (!required) storeField.value = "";
  }

  const addRole = document.getElementById("addUserRoles");
  const addStore = document.getElementById("addUserStore");
  if (addRole && addStore) {
    addRole.addEventListener("change", function () { syncStoreField(addRole, addStore); });
    syncStoreField(addRole, addStore);
  }

  const modal = document.getElementById("editUserModal");
  if (modal) {
    modal.addEventListener("show.bs.modal", function (event) {
      const button = event.relatedTarget;
      if (!button) return;
      document.getElementById("editUserId").value = button.dataset.userId || "";
      document.getElementById("editUserName").value = button.dataset.userName || "";
      document.getElementById("editUsername").value = button.dataset.userUsername || "";
      document.getElementById("editUserEmail").value = button.dataset.userEmail || "";
      document.getElementById("editUserPhone").value = button.dataset.userPhone || "";
      const assignedRoles = (button.dataset.userRoles || "sales_clerk").split(",");
      document.querySelectorAll("#editUserRoles input[type=checkbox]").forEach(function (input) { input.checked = assignedRoles.includes(input.value); });
      document.getElementById("editUserStore").value = button.dataset.userStore || "";
      document.getElementById("editUserStatus").value = button.dataset.userStatus || "active";
      document.getElementById("editUserPassword").value = "";
      syncStoreField(document.getElementById("editUserRoles"), document.getElementById("editUserStore"));
    });
  }

  const editRole = document.getElementById("editUserRoles");
  const editStore = document.getElementById("editUserStore");
  if (editRole && editStore) editRole.addEventListener("change", function () { syncStoreField(editRole, editStore); });

  document.querySelectorAll(".user-password-toggle").forEach(function (button) {
    button.addEventListener("click", function () {
      const input = document.getElementById(button.dataset.passwordTarget);
      if (!input) return;
      const showing = input.type === "text";
      input.type = showing ? "password" : "text";
      button.innerHTML = showing ? "<i class=\"bi bi-eye\"></i>" : "<i class=\"bi bi-eye-slash\"></i>";
      button.setAttribute("aria-label", showing ? "Show password" : "Hide password");
    });
  });
})();
</script>';
require __DIR__ . '/includes/footer.php';
?>
