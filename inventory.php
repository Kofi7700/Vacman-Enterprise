<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canAdjust = is_role('admin', 'warehouse');

// ---- Handle Stock Adjustment (physical count) --------------------
if (is_post() && isset($_POST['adjust_stock'])) {
    require_role('admin', 'warehouse');
    verify_csrf();

    $inventoryId = (int) ($_POST['inventory_id'] ?? 0);
    $newQuantity = $_POST['new_quantity'] ?? '';

    if ($inventoryId <= 0 || !is_numeric($newQuantity) || (int) $newQuantity < 0) {
        flash_set('danger', 'Enter a valid non-negative quantity.');
        redirect('inventory.php');
    }

    $stmt = $pdo->prepare('SELECT quantity FROM inventory WHERE id = ?');
    $stmt->execute([$inventoryId]);
    $old = $stmt->fetch();

    if (!$old) {
        flash_set('danger', 'Inventory record not found.');
        redirect('inventory.php');
    }

    try {
        $pdo->prepare('UPDATE inventory SET quantity = ?, last_physical_count = CURDATE(), notes = ? WHERE id = ?')
            ->execute([(int) $newQuantity, trim($_POST['notes'] ?? '') ?: null, $inventoryId]);
        log_audit('stock_adjustment', 'inventory', $inventoryId, ['quantity' => $old['quantity']], ['quantity' => (int) $newQuantity]);
        flash_set('success', 'Stock count updated.');
    } catch (PDOException $e) {
        error_log('Stock adjustment failed: ' . $e->getMessage());
        flash_set('danger', 'Could not update the stock count.');
    }
    redirect('inventory.php');
}

// ---- Data -----------------------------------------------------------
$stores = $pdo->query('SELECT id, name FROM stores ORDER BY name')->fetchAll();
$storeFilter = (int) ($_GET['store_id'] ?? 0);

$sql = "SELECT i.id, i.quantity, i.reserved_quantity, i.last_received_date, i.last_sold_date, i.last_physical_count,
               p.name AS product_name, p.sku, p.reorder_level, c.name AS category_name, s.name AS store_name
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        JOIN categories c ON c.id = p.category_id
        JOIN stores s ON s.id = i.store_id";
$params = [];
if ($storeFilter > 0) {
    $sql .= ' WHERE i.store_id = :store_id';
    $params[':store_id'] = $storeFilter;
}
$sql .= ' ORDER BY p.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Inventory';
require __DIR__ . '/includes/header.php';
?>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <h6 class="m-0 fw-bold text-primary">Stock by Store</h6>
    <form method="GET" action="inventory.php">
      <select name="store_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="0">All Stores</option>
        <?php foreach ($stores as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= $storeFilter === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Product</th><th>SKU</th><th>Category</th><th>Store</th><th>On Hand</th><th>Reserved</th>
            <th>Last Received</th><th>Last Sold</th><th>Last Count</th>
            <?php if ($canAdjust): ?><th>Adjust</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No inventory records found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
          <tr class="<?= (int) $r['quantity'] <= (int) $r['reorder_level'] ? 'table-warning' : '' ?>">
            <td><?= e($r['product_name']) ?></td>
            <td><?= e($r['sku']) ?></td>
            <td><?= e($r['category_name']) ?></td>
            <td><?= e($r['store_name']) ?></td>
            <td class="fw-bold"><?= (int) $r['quantity'] ?></td>
            <td><?= (int) $r['reserved_quantity'] ?></td>
            <td><?= $r['last_received_date'] ? e(date('M j, Y', strtotime($r['last_received_date']))) : '—' ?></td>
            <td><?= $r['last_sold_date'] ? e(date('M j, Y', strtotime($r['last_sold_date']))) : '—' ?></td>
            <td><?= $r['last_physical_count'] ? e(date('M j, Y', strtotime($r['last_physical_count']))) : 'Never' ?></td>
            <?php if ($canAdjust): ?>
            <td>
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#adjustModal<?= (int) $r['id'] ?>">
                <i class="bi bi-pencil-square"></i>
              </button>
            </td>
            <?php endif; ?>
          </tr>

          <?php if ($canAdjust): ?>
          <div class="modal fade" id="adjustModal<?= (int) $r['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="POST" action="inventory.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="adjust_stock" value="1">
                  <input type="hidden" name="inventory_id" value="<?= (int) $r['id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Physical Count: <?= e($r['product_name']) ?> (<?= e($r['store_name']) ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p class="text-muted">System currently shows <strong><?= (int) $r['quantity'] ?></strong> units.</p>
                    <div class="mb-3">
                      <label class="form-label">Counted Quantity</label>
                      <input type="number" class="form-control" name="new_quantity" min="0" value="<?= (int) $r['quantity'] ?>" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Notes (optional)</label>
                      <textarea class="form-control" name="notes" rows="2" placeholder="Reason for adjustment"></textarea>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Count</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
