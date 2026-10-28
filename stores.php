<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager');

$pdo = db();
$isAdmin = is_role('admin');

// ---- Add store and initialize its inventory --------------------------
if (is_post() && isset($_POST['add_store'])) {
    verify_csrf();
    $errors = validate($_POST, ['name' => 'required', 'location' => 'required']);
    $managerId = $isAdmin && ($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null;

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO stores (name, location, address, contact_phone, manager_id) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['location']),
                trim($_POST['address'] ?? '') ?: null,
                trim($_POST['contact_phone'] ?? '') ?: null,
                $managerId,
            ]);
            $storeId = (int) $pdo->lastInsertId();

            $productIds = $pdo->query('SELECT id FROM products')->fetchAll(PDO::FETCH_COLUMN);
            $inventoryStmt = $pdo->prepare('INSERT INTO inventory (product_id, store_id, quantity) VALUES (?, ?, 0)');
            foreach ($productIds as $productId) $inventoryStmt->execute([$productId, $storeId]);

            $pdo->commit();
            log_audit('create', 'store', $storeId, null, [
                'name' => trim($_POST['name']),
                'location' => trim($_POST['location']),
                'manager_id' => $managerId,
            ]);
            flash_set('success', 'Store added successfully.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Add store failed: ' . $e->getMessage());
            flash_set('danger', 'Could not add the store.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('stores.php');
}

// ---- Edit store -------------------------------------------------------
if (is_post() && isset($_POST['edit_store'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $errors = validate($_POST, ['name' => 'required', 'location' => 'required']);

    if (empty($errors) && $id > 0) {
        try {
            $existingStmt = $pdo->prepare('SELECT manager_id FROM stores WHERE id = ?');
            $existingStmt->execute([$id]);
            $existingStore = $existingStmt->fetch();
            if (!$existingStore) throw new RuntimeException('Store not found.');
            $managerId = $isAdmin
                ? (($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null)
                : ($existingStore['manager_id'] !== null ? (int) $existingStore['manager_id'] : null);
            $stmt = $pdo->prepare(
                'UPDATE stores SET name = ?, location = ?, address = ?, contact_phone = ?, manager_id = ?, status = ? WHERE id = ?'
            );
            $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['location']),
                trim($_POST['address'] ?? '') ?: null,
                trim($_POST['contact_phone'] ?? '') ?: null,
                $managerId,
                $status,
                $id,
            ]);
            log_audit('update', 'store', $id, null, [
                'name' => trim($_POST['name']),
                'location' => trim($_POST['location']),
                'manager_id' => $managerId,
                'status' => $status,
            ]);
            flash_set('success', 'Store updated successfully.');
        } catch (RuntimeException $e) {
            flash_set('warning', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Edit store failed: ' . $e->getMessage());
            flash_set('danger', 'Could not update the store.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)) ?: 'Invalid store.');
    }
    redirect('stores.php');
}

// ---- Delete store (admin only) ---------------------------------------
if (is_post() && request_method() === 'DELETE') {
    require_role('admin');
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM stores WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 1) {
                log_audit('delete', 'store', $id);
                flash_set('success', 'Store deleted.');
            } else {
                flash_set('warning', 'Store not found.');
            }
        } catch (PDOException $e) {
            error_log('Delete store failed: ' . $e->getMessage());
            flash_set('danger', 'Could not delete the store. It may still have inventory, sales, or transfer history.');
        }
    }
    redirect('stores.php');
}

// ---- Data, filters and summary ---------------------------------------
$managers = $pdo->query("SELECT id, full_name FROM users WHERE FIND_IN_SET('manager', COALESCE(NULLIF(roles, ''), role)) OR FIND_IN_SET('admin', COALESCE(NULLIF(roles, ''), role)) ORDER BY full_name")->fetchAll();
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) $statusFilter = 'all';

$sql = "SELECT s.*, u.full_name AS manager_name,
               COUNT(i.id) AS product_count,
               COALESCE(SUM(i.quantity), 0) AS total_units,
               COALESCE(SUM(i.quantity <= p.reorder_level), 0) AS low_stock_count,
               (SELECT COUNT(*) FROM sales sa WHERE sa.store_id = s.id AND sa.payment_status = 'approved' AND DATE(sa.sale_date) = CURDATE()) AS sales_today
        FROM stores s
        LEFT JOIN users u ON u.id = s.manager_id
        LEFT JOIN inventory i ON i.store_id = s.id
        LEFT JOIN products p ON p.id = i.product_id";
$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(s.name LIKE :q_name OR s.location LIKE :q_location OR s.address LIKE :q_address OR u.full_name LIKE :q_manager)';
    $params[':q_name'] = '%' . $search . '%';
    $params[':q_location'] = '%' . $search . '%';
    $params[':q_address'] = '%' . $search . '%';
    $params[':q_manager'] = '%' . $search . '%';
}
if ($statusFilter !== 'all') {
    $conditions[] = 's.status = :status';
    $params[':status'] = $statusFilter;
}
if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
$sql .= ' GROUP BY s.id, u.full_name ORDER BY s.name';

$storeStmt = $pdo->prepare($sql);
$storeStmt->execute($params);
$stores = $storeStmt->fetchAll();

$storeMetrics = $pdo->query("SELECT
    COUNT(*) AS total_stores,
    COALESCE(SUM(status = 'active'), 0) AS active_stores,
    COALESCE(SUM(manager_id IS NULL), 0) AS unassigned_stores,
    (SELECT COALESCE(SUM(quantity), 0) FROM inventory) AS total_units
    FROM stores")->fetch();

$pageTitle = 'Store Management';
require __DIR__ . '/includes/header.php';
?>

<section class="store-hero mb-4">
  <div>
    <span class="store-overline"><i class="bi bi-shop me-2"></i>Location directory</span>
    <h2>Every store, clearly connected.</h2>
    <p>Manage locations, assign responsible managers, and see stock coverage across your retail network.</p>
  </div>
  <button class="btn store-new-button" data-bs-toggle="modal" data-bs-target="#addStoreModal"><i class="bi bi-plus-lg me-2"></i>Add store</button>
</section>

<section class="store-kpi-grid mb-4" aria-label="Store summary">
  <div class="store-kpi store-kpi-total"><span class="store-kpi-icon"><i class="bi bi-buildings"></i></span><div><span>Total stores</span><strong><?= (int) $storeMetrics['total_stores'] ?></strong><small>Registered locations</small></div></div>
  <div class="store-kpi store-kpi-active"><span class="store-kpi-icon"><i class="bi bi-shop-window"></i></span><div><span>Active stores</span><strong><?= (int) $storeMetrics['active_stores'] ?></strong><small>Currently operating</small></div></div>
  <div class="store-kpi store-kpi-stock"><span class="store-kpi-icon"><i class="bi bi-box-seam"></i></span><div><span>Total stock pieces</span><strong><?= number_format((int) $storeMetrics['total_units']) ?></strong><small>Across all locations</small></div></div>
  <div class="store-kpi store-kpi-manager"><span class="store-kpi-icon"><i class="bi bi-person-exclamation"></i></span><div><span>Unassigned</span><strong><?= (int) $storeMetrics['unassigned_stores'] ?></strong><small>Stores without a manager</small></div></div>
</section>

<section class="store-panel">
  <header class="store-panel-header">
    <div><span class="store-section-label">Store network</span><h3>Locations</h3></div>
    <span class="store-result-count"><?= count($stores) ?> result<?= count($stores) === 1 ? '' : 's' ?></span>
  </header>

  <form class="store-filters" method="GET" action="stores.php" role="search">
    <div class="store-search"><i class="bi bi-search"></i><input type="search" class="form-control" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Search store, location, address, or manager"></div>
    <select class="form-select" name="status" aria-label="Filter by status"><option value="all">All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
    <button class="btn store-filter-button" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
    <?php if ($search !== '' || $statusFilter !== 'all'): ?><a href="stores.php" class="btn store-clear-button" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
  </form>

  <div class="table-responsive store-table-wrap">
    <table class="table align-middle store-table mb-0">
      <thead><tr><th>Store</th><th>Location</th><th>Manager</th><th>Inventory</th><th>Activity</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$stores): ?>
        <tr><td colspan="7"><div class="store-empty"><i class="bi bi-shop"></i><strong>No stores found</strong><span>Add a location or adjust the filters to see your store network.</span></div></td></tr>
        <?php else: foreach ($stores as $store): ?>
        <tr>
          <td><div class="store-identity"><span><i class="bi bi-shop-window"></i></span><div><strong><?= e($store['name']) ?></strong><small>Store #<?= str_pad((string) $store['id'], 3, '0', STR_PAD_LEFT) ?></small></div></div></td>
          <td><div class="store-location"><strong><i class="bi bi-geo-alt"></i><?= e($store['location']) ?></strong><small><?= e($store['address'] ?: 'No street address') ?></small><?php if ($store['contact_phone']): ?><a href="tel:<?= e($store['contact_phone']) ?>"><i class="bi bi-telephone"></i><?= e($store['contact_phone']) ?></a><?php endif; ?></div></td>
          <td><?php if ($store['manager_name']): ?><div class="store-manager"><span><?= e(strtoupper(substr($store['manager_name'], 0, 1))) ?></span><strong><?= e($store['manager_name']) ?></strong></div><?php else: ?><span class="store-unassigned"><i class="bi bi-person-plus"></i>Unassigned</span><?php endif; ?></td>
          <td><div class="store-stock"><strong><?= number_format((int) $store['total_units']) ?> pieces</strong><small><?= (int) $store['product_count'] ?> product<?= (int) $store['product_count'] === 1 ? '' : 's' ?></small></div></td>
          <td><div class="store-activity"><strong><?= (int) $store['sales_today'] ?> sale<?= (int) $store['sales_today'] === 1 ? '' : 's' ?> today</strong><small class="<?= (int) $store['low_stock_count'] > 0 ? 'text-warning' : '' ?>"><?= (int) $store['low_stock_count'] ?> low-stock item<?= (int) $store['low_stock_count'] === 1 ? '' : 's' ?></small></div></td>
          <td><span class="store-status <?= e($store['status']) ?>"><i></i><?= e(ucfirst($store['status'])) ?></span></td>
          <td class="text-end"><div class="store-actions">
            <button type="button" class="btn store-edit-button" data-bs-toggle="modal" data-bs-target="#editStoreModal" data-store-id="<?= (int) $store['id'] ?>" data-store-name="<?= e($store['name']) ?>" data-store-location="<?= e($store['location']) ?>" data-store-address="<?= e($store['address'] ?? '') ?>" data-store-phone="<?= e($store['contact_phone'] ?? '') ?>" data-store-manager="<?= e((string) ($store['manager_id'] ?? '')) ?>" data-store-status="<?= e($store['status']) ?>"><i class="bi bi-pencil"></i><span>Edit</span></button>
            <?php if ($isAdmin): ?><form method="POST" action="stores.php" data-confirm="Delete '<?= e($store['name']) ?>'? This is only possible when it has no linked records."><?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><input type="hidden" name="id" value="<?= (int) $store['id'] ?>"><button type="submit" class="btn store-delete-button" title="Delete store" aria-label="Delete <?= e($store['name']) ?>"><i class="bi bi-trash"></i></button></form><?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="modal fade" id="addStoreModal" tabindex="-1" aria-labelledby="addStoreTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content store-modal"><form method="POST" action="stores.php">
    <?= csrf_field() ?><input type="hidden" name="add_store" value="1">
    <div class="modal-header store-modal-header"><div><h5 class="modal-title" id="addStoreTitle"><i class="bi bi-shop me-2 text-success"></i>Add a new store</h5><small>Create a location and optionally assign its manager.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body p-4"><div class="row g-3">
      <div class="col-md-7"><label class="form-label" for="addStoreName">Store name</label><input type="text" class="form-control" name="name" id="addStoreName" maxlength="100" placeholder="e.g. Central Market Store" required></div>
      <div class="col-md-5"><label class="form-label" for="addStoreLocation">Town or area</label><input type="text" class="form-control" name="location" id="addStoreLocation" maxlength="255" placeholder="e.g. Accra Central" required></div>
      <div class="col-12"><label class="form-label" for="addStoreAddress">Street address <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control" name="address" id="addStoreAddress" rows="2" placeholder="Building, street, landmark"></textarea></div>
      <div class="col-md-5"><label class="form-label" for="addStorePhone">Contact phone <span class="text-muted fw-normal">(optional)</span></label><input type="tel" class="form-control" name="contact_phone" id="addStorePhone" maxlength="20" placeholder="+233 ..."></div>
      <div class="col-md-7"><label class="form-label" for="addStoreManager">Store manager</label><select class="form-select" name="manager_id" id="addStoreManager" <?= !$isAdmin ? 'disabled' : '' ?>><option value="">Leave unassigned</option><?php foreach ($managers as $manager): ?><option value="<?= (int) $manager['id'] ?>"><?= e($manager['full_name']) ?></option><?php endforeach; ?></select><?php if (!$isAdmin): ?><small class="text-muted">Only an administrator can assign store managers.</small><?php endif; ?></div>
    </div><div class="store-inventory-note mt-4"><i class="bi bi-boxes"></i><div><strong>Inventory ready from day one</strong><small>The new store receives a zero-stock inventory record for every existing product.</small></div></div></div>
    <div class="modal-footer border-0 px-4 pb-4 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn store-save-button"><i class="bi bi-plus-lg me-2"></i>Create store</button></div>
  </form></div></div>
</div>

<div class="modal fade" id="editStoreModal" tabindex="-1" aria-labelledby="editStoreTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content store-modal"><form method="POST" action="stores.php" id="editStoreForm">
    <?= csrf_field() ?><input type="hidden" name="edit_store" value="1"><input type="hidden" name="id" id="editStoreId">
    <div class="modal-header store-modal-header"><div><h5 class="modal-title" id="editStoreTitle"><i class="bi bi-pencil-square me-2 text-success"></i>Edit store</h5><small>Update location details, leadership, or operating status.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body p-4"><div class="row g-3">
      <div class="col-md-7"><label class="form-label" for="editStoreName">Store name</label><input type="text" class="form-control" name="name" id="editStoreName" maxlength="100" required></div>
      <div class="col-md-5"><label class="form-label" for="editStoreLocation">Town or area</label><input type="text" class="form-control" name="location" id="editStoreLocation" maxlength="255" required></div>
      <div class="col-12"><label class="form-label" for="editStoreAddress">Street address <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control" name="address" id="editStoreAddress" rows="2"></textarea></div>
      <div class="col-md-5"><label class="form-label" for="editStorePhone">Contact phone</label><input type="tel" class="form-control" name="contact_phone" id="editStorePhone" maxlength="20"></div>
      <div class="col-md-4"><label class="form-label" for="editStoreManager">Store manager</label><select class="form-select" name="manager_id" id="editStoreManager" <?= !$isAdmin ? 'disabled' : '' ?>><option value="">Unassigned</option><?php foreach ($managers as $manager): ?><option value="<?= (int) $manager['id'] ?>"><?= e($manager['full_name']) ?></option><?php endforeach; ?></select><?php if (!$isAdmin): ?><small class="text-muted">Administrator-controlled</small><?php endif; ?></div>
      <div class="col-md-3"><label class="form-label" for="editStoreStatus">Status</label><select class="form-select" name="status" id="editStoreStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div></div>
    <div class="modal-footer border-0 px-4 pb-4 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn store-save-button"><i class="bi bi-check2 me-2"></i>Save changes</button></div>
  </form></div></div>
</div>

<?php
$extraScripts = '<script>
(function () {
  const modal = document.getElementById("editStoreModal");
  if (!modal) return;
  modal.addEventListener("show.bs.modal", function (event) {
    const button = event.relatedTarget;
    if (!button) return;
    document.getElementById("editStoreId").value = button.dataset.storeId || "";
    document.getElementById("editStoreName").value = button.dataset.storeName || "";
    document.getElementById("editStoreLocation").value = button.dataset.storeLocation || "";
    document.getElementById("editStoreAddress").value = button.dataset.storeAddress || "";
    document.getElementById("editStorePhone").value = button.dataset.storePhone || "";
    document.getElementById("editStoreManager").value = button.dataset.storeManager || "";
    document.getElementById("editStoreStatus").value = button.dataset.storeStatus || "active";
  });
})();
</script>';
require __DIR__ . '/includes/footer.php';
?>
