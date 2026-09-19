<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager');

$pdo = db();

// ---- Handle Add Store --------------------------------------------------
if (is_post() && isset($_POST['add_store'])) {
    verify_csrf();
    $errors = validate($_POST, ['name' => 'required', 'location' => 'required']);

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO stores (name, location, address, contact_phone, manager_id) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['location']),
                trim($_POST['address'] ?? '') ?: null,
                trim($_POST['contact_phone'] ?? '') ?: null,
                ($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null,
            ]);
            $storeId = (int) $pdo->lastInsertId();

            // Give the new store a zero-stock row for every existing product.
            $productIds = $pdo->query('SELECT id FROM products')->fetchAll(PDO::FETCH_COLUMN);
            $inv = $pdo->prepare('INSERT INTO inventory (product_id, store_id, quantity) VALUES (?, ?, 0)');
            foreach ($productIds as $pid) {
                $inv->execute([$pid, $storeId]);
            }

            log_audit('create', 'store', $storeId, null, $_POST);
            flash_set('success', 'Store added successfully.');
        } catch (PDOException $e) {
            error_log('Add store failed: ' . $e->getMessage());
            flash_set('danger', 'Could not add the store.');
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('stores.php');
}

// ---- Handle Edit Store -------------------------------------------------
if (is_post() && isset($_POST['edit_store'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $errors = validate($_POST, ['name' => 'required', 'location' => 'required']);

    if (empty($errors) && $id > 0) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE stores SET name = ?, location = ?, address = ?, contact_phone = ?, manager_id = ?, status = ? WHERE id = ?'
            );
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['location']),
                trim($_POST['address'] ?? '') ?: null,
                trim($_POST['contact_phone'] ?? '') ?: null,
                ($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null,
                in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active',
                $id,
            ]);
            log_audit('update', 'store', $id, null, $_POST);
            flash_set('success', 'Store updated successfully.');
        } catch (PDOException $e) {
            error_log('Edit store failed: ' . $e->getMessage());
            flash_set('danger', 'Could not update the store.');
        }
    } else {
        flash_set('danger', implode(' ', $errors) ?: 'Invalid store.');
    }
    redirect('stores.php');
}

// ---- Handle Delete (admin only) -----------------------------------------
if (is_post() && request_method() === 'DELETE') {
    require_role('admin');
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM stores WHERE id = ?');
            $stmt->execute([$id]);
            log_audit('delete', 'store', $id);
            flash_set('success', 'Store deleted.');
        } catch (PDOException $e) {
            error_log('Delete store failed: ' . $e->getMessage());
            flash_set('danger', 'Could not delete the store. It may still have inventory, sales, or transfer history.');
        }
    }
    redirect('stores.php');
}

// ---- Data ---------------------------------------------------------
$managers = $pdo->query("SELECT id, full_name FROM users WHERE role IN ('manager','admin') ORDER BY full_name")->fetchAll();
$stores = $pdo->query(
    "SELECT s.*, u.full_name AS manager_name,
            (SELECT COUNT(*) FROM inventory i WHERE i.store_id = s.id) AS product_count
     FROM stores s
     LEFT JOIN users u ON u.id = s.manager_id
     ORDER BY s.name"
)->fetchAll();

$pageTitle = 'Store Management';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addStoreModal">
    <i class="bi bi-plus-lg me-1"></i> Add Store
  </button>
</div>

<div class="card shadow mb-4">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr><th>Name</th><th>Location</th><th>Phone</th><th>Manager</th><th>Products Stocked</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($stores)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No stores yet.</td></tr>
          <?php else: foreach ($stores as $store): ?>
          <tr>
            <td><?= e($store['name']) ?></td>
            <td><?= e($store['location']) ?></td>
            <td><?= e($store['contact_phone'] ?? '—') ?></td>
            <td><?= e($store['manager_name'] ?? 'Unassigned') ?></td>
            <td><?= (int) $store['product_count'] ?></td>
            <td><span class="badge bg-<?= $store['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($store['status'])) ?></span></td>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editStoreModal<?= (int) $store['id'] ?>"><i class="bi bi-pencil"></i></button>
              <?php if (is_role('admin')): ?>
              <form method="POST" action="stores.php" class="d-inline" data-confirm="Delete '<?= e($store['name']) ?>'?">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="id" value="<?= (int) $store['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>

          <div class="modal fade" id="editStoreModal<?= (int) $store['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="POST" action="stores.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="edit_store" value="1">
                  <input type="hidden" name="id" value="<?= (int) $store['id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Store</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Store Name</label><input type="text" class="form-control" name="name" value="<?= e($store['name']) ?>" required></div>
                    <div class="mb-3"><label class="form-label">Location</label><input type="text" class="form-control" name="location" value="<?= e($store['location']) ?>" required></div>
                    <div class="mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="2"><?= e($store['address']) ?></textarea></div>
                    <div class="mb-3"><label class="form-label">Contact Phone</label><input type="text" class="form-control" name="contact_phone" value="<?= e($store['contact_phone']) ?>"></div>
                    <div class="mb-3">
                      <label class="form-label">Manager</label>
                      <select class="form-select" name="manager_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($managers as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= $m['id'] == $store['manager_id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Status</label>
                      <select class="form-select" name="status">
                        <option value="active" <?= $store['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $store['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                      </select>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Store</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="addStoreModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="stores.php">
        <?= csrf_field() ?>
        <input type="hidden" name="add_store" value="1">
        <div class="modal-header">
          <h5 class="modal-title">Add Store</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Store Name</label><input type="text" class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">Location</label><input type="text" class="form-control" name="location" required></div>
          <div class="mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="2"></textarea></div>
          <div class="mb-3"><label class="form-label">Contact Phone</label><input type="text" class="form-control" name="contact_phone"></div>
          <div class="mb-3">
            <label class="form-label">Manager</label>
            <select class="form-select" name="manager_id">
              <option value="">Unassigned</option>
              <?php foreach ($managers as $m): ?>
              <option value="<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Add Store</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
