<?php
require_once __DIR__ . '/config/config.php';
require_role('admin');

$pdo = db();
$roles = ['admin', 'manager', 'warehouse', 'sales_clerk'];

// ---- Handle Add User --------------------------------------------------
if (is_post() && isset($_POST['add_user'])) {
    verify_csrf();

    $errors = validate($_POST, ['username' => 'required', 'full_name' => 'required', 'password' => 'required', 'role' => 'required', 'email' => 'email']);
    if (!in_array($_POST['role'] ?? '', $roles, true)) {
        $errors[] = 'Invalid role selected.';
    }
    if (isset($_POST['password']) && strlen($_POST['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password, full_name, email, phone, role) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                trim($_POST['username']),
                password_hash($_POST['password'], PASSWORD_DEFAULT),
                trim($_POST['full_name']),
                trim($_POST['email'] ?? '') ?: null,
                trim($_POST['phone'] ?? '') ?: null,
                $_POST['role'],
            ]);
            $id = (int) $pdo->lastInsertId();
            log_audit('create', 'user', $id, null, ['username' => $_POST['username'], 'role' => $_POST['role']]);
            flash_set('success', 'User account created.');
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate entry') ? 'That username is already taken.' : 'Could not create the user.';
            error_log('Add user failed: ' . $e->getMessage());
            flash_set('danger', $msg);
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('admin.php');
}

// ---- Handle Edit User --------------------------------------------------
if (is_post() && isset($_POST['edit_user'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);

    $errors = validate($_POST, ['full_name' => 'required', 'role' => 'required', 'email' => 'email']);
    if (!in_array($_POST['role'] ?? '', $roles, true)) {
        $errors[] = 'Invalid role selected.';
    }
    // Prevent an admin from demoting themselves out of the only admin account.
    if ($id === (int) current_user()['id'] && $_POST['role'] !== 'admin') {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount <= 1) {
            $errors[] = 'You cannot remove the last remaining admin account.';
        }
    }

    if (empty($errors) && $id > 0) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, role = ? WHERE id = ?');
            $stmt->execute([
                trim($_POST['full_name']),
                trim($_POST['email'] ?? '') ?: null,
                trim($_POST['phone'] ?? '') ?: null,
                $_POST['role'],
                $id,
            ]);

            if (!empty($_POST['new_password'])) {
                if (strlen($_POST['new_password']) < 8) {
                    flash_set('warning', 'User details saved, but the password was not changed (must be at least 8 characters).');
                } else {
                    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                        ->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $id]);
                }
            }

            // Refresh the session copy if the admin edited their own account.
            if ($id === (int) current_user()['id']) {
                $_SESSION['user']['full_name'] = trim($_POST['full_name']);
                $_SESSION['user']['role'] = $_POST['role'];
            }

            log_audit('update', 'user', $id, null, ['full_name' => $_POST['full_name'], 'role' => $_POST['role']]);
            flash_set('success', 'User updated.');
        } catch (PDOException $e) {
            error_log('Edit user failed: ' . $e->getMessage());
            flash_set('danger', 'Could not update the user.');
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('admin.php');
}

// ---- Data -----------------------------------------------------------
$users = $pdo->query(
    "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.created_at,
            (SELECT COUNT(*) FROM sales WHERE user_id = u.id) AS sales_count
     FROM users u ORDER BY u.full_name"
)->fetchAll();

$roleCounts = $pdo->query('SELECT role, COUNT(*) AS c FROM users GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);

$recentAudit = $pdo->query(
    "SELECT a.action, a.entity_type, a.entity_id, a.created_at, u.full_name
     FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC LIMIT 15"
)->fetchAll();

$pageTitle = 'User Management';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-4">
  <?php foreach (['admin' => 'Admins', 'manager' => 'Managers', 'warehouse' => 'Warehouse', 'sales_clerk' => 'Sales Clerks'] as $role => $label): ?>
  <div class="col-md-3">
    <div class="card card-dashboard border-left-primary shadow h-100 py-2">
      <div class="card-body">
        <div class="text-xs fw-bold text-primary text-uppercase mb-1"><?= e($label) ?></div>
        <div class="h5 mb-0 fw-bold"><?= (int) ($roleCounts[$role] ?? 0) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addUserModal">
    <i class="bi bi-person-plus me-1"></i> Add User
  </button>
</div>

<div class="row">
  <div class="col-lg-8">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary">All Users</h6></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Contact</th><th>Sales Recorded</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><?= e($u['full_name']) ?><?= (int) $u['id'] === (int) current_user()['id'] ? ' <span class="badge bg-secondary">You</span>' : '' ?></td>
                <td><?= e($u['username']) ?></td>
                <td><span class="badge bg-info text-dark"><?= e(str_replace('_', ' ', ucfirst($u['role']))) ?></span></td>
                <td><?= e($u['email'] ?: '—') ?><?= $u['phone'] ? '<br><small class="text-muted">' . e($u['phone']) . '</small>' : '' ?></td>
                <td><?= (int) $u['sales_count'] ?></td>
                <td>
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?= (int) $u['id'] ?>"><i class="bi bi-pencil"></i></button>
                </td>
              </tr>

              <div class="modal fade" id="editUserModal<?= (int) $u['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                  <div class="modal-content">
                    <form method="POST" action="admin.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="edit_user" value="1">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <div class="modal-header">
                        <h5 class="modal-title">Edit User: <?= e($u['full_name']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body">
                        <div class="mb-3"><label class="form-label">Full Name</label><input type="text" class="form-control" name="full_name" value="<?= e($u['full_name']) ?>" required></div>
                        <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($u['email']) ?>"></div>
                        <div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone" value="<?= e($u['phone']) ?>"></div>
                        <div class="mb-3">
                          <label class="form-label">Role</label>
                          <select class="form-select" name="role" required>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(str_replace('_', ' ', ucfirst($r))) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="mb-3">
                          <label class="form-label">Reset Password (optional)</label>
                          <input type="password" class="form-control" name="new_password" placeholder="Leave blank to keep current password" minlength="8">
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary">Recent Activity</h6></div>
      <div class="card-body">
        <ul class="list-group list-group-flush small">
          <?php if (empty($recentAudit)): ?>
          <li class="list-group-item text-muted">No activity logged yet.</li>
          <?php else: foreach ($recentAudit as $a): ?>
          <li class="list-group-item">
            <strong><?= e($a['full_name'] ?? 'System') ?></strong> <?= e(str_replace('_', ' ', $a['action'])) ?> <?= e($a['entity_type']) ?>
            <?= $a['entity_id'] ? '#' . (int) $a['entity_id'] : '' ?>
            <div class="text-muted"><?= e(date('M j, g:i A', strtotime($a['created_at']))) ?></div>
          </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="admin.php">
        <?= csrf_field() ?>
        <input type="hidden" name="add_user" value="1">
        <div class="modal-header">
          <h5 class="modal-title">Add User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Full Name</label><input type="text" class="form-control" name="full_name" required></div>
          <div class="mb-3"><label class="form-label">Username</label><input type="text" class="form-control" name="username" required></div>
          <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" minlength="8" required></div>
          <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div>
          <div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone"></div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select class="form-select" name="role" required>
              <?php foreach ($roles as $r): ?>
              <option value="<?= e($r) ?>"><?= e(str_replace('_', ' ', ucfirst($r))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
