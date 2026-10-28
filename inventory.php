<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canAdjust = is_role('admin', 'warehouse');
$canViewAllStores = is_role('admin', 'warehouse', 'manager');
$storeScopedViewer = !$canViewAllStores;
$assignedStoreId = $storeScopedViewer ? (int) (current_user()['store_id'] ?? 0) : 0;

// ---- Handle Stock Adjustment (physical count) ------------------------
if (is_post() && isset($_POST['adjust_stock'])) {
    require_role('admin', 'warehouse');
    verify_csrf();

    $inventoryId = (int) ($_POST['inventory_id'] ?? 0);
    $newQuantity = $_POST['new_quantity'] ?? '';

    if ($inventoryId <= 0 || filter_var($newQuantity, FILTER_VALIDATE_INT) === false || (int) $newQuantity < 0) {
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

// ---- Filters and inventory data --------------------------------------
$storeSql = "SELECT id, name FROM stores WHERE status = 'active'";
if ($storeScopedViewer) $storeSql .= ' AND id = ' . $assignedStoreId;
$stores = $pdo->query($storeSql . ' ORDER BY name')->fetchAll();
$storeFilter = (int) ($_GET['store_id'] ?? 0);
if ($storeScopedViewer) $storeFilter = $assignedStoreId;
$search = trim($_GET['q'] ?? '');
$stockFilter = $_GET['stock'] ?? 'all';
if (!in_array($stockFilter, ['all', 'healthy', 'low', 'empty'], true)) {
    $stockFilter = 'all';
}

$sql = "SELECT i.id, i.quantity, i.reserved_quantity, i.last_received_date, i.last_sold_date, i.last_physical_count,
               i.store_id, p.name AS product_name, p.sku, p.reorder_level, c.name AS category_name, s.name AS store_name
        FROM inventory i
        JOIN products p ON p.id = i.product_id
        JOIN categories c ON c.id = p.category_id
        JOIN stores s ON s.id = i.store_id";
$conditions = [];
$params = [];

if ($storeScopedViewer) {
    if ($assignedStoreId > 0) {
        $conditions[] = 'i.store_id = :assigned_store_id';
        $params[':assigned_store_id'] = $assignedStoreId;
    } else {
        $conditions[] = '1 = 0';
    }
} elseif ($storeFilter > 0) {
    $conditions[] = 'i.store_id = :store_id';
    $params[':store_id'] = $storeFilter;
}
if ($search !== '') {
    $conditions[] = '(p.name LIKE :query_name OR p.sku LIKE :query_sku OR c.name LIKE :query_category)';
    $params[':query_name'] = '%' . $search . '%';
    $params[':query_sku'] = '%' . $search . '%';
    $params[':query_category'] = '%' . $search . '%';
}
if ($stockFilter === 'healthy') {
    $conditions[] = 'i.quantity > p.reorder_level';
} elseif ($stockFilter === 'low') {
    $conditions[] = 'i.quantity > 0 AND i.quantity <= p.reorder_level';
} elseif ($stockFilter === 'empty') {
    $conditions[] = 'i.quantity = 0';
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY s.name, p.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalUnits = array_sum(array_map(static fn(array $row): int => (int) $row['quantity'], $rows));
$reservedUnits = array_sum(array_map(static fn(array $row): int => (int) $row['reserved_quantity'], $rows));
$availableUnits = max(0, $totalUnits - $reservedUnits);
$lowStockCount = count(array_filter($rows, static fn(array $row): bool => (int) $row['quantity'] <= (int) $row['reorder_level']));
$storesShown = count(array_unique(array_column($rows, 'store_name')));

$pageTitle = 'Inventory';
require __DIR__ . '/includes/header.php';
?>

<section class="inventory-hero mb-4">
  <div>
    <span class="inventory-overline"><i class="bi bi-boxes me-2"></i>Stock control</span>
    <h2>Know what is available, everywhere.</h2>
    <p>Monitor on-hand stock, reservations, reorder points, and physical counts across every store.</p>
  </div>
  <?php if ($canAdjust): ?>
  <div class="inventory-hero-note"><i class="bi bi-clipboard-check"></i><span><strong>Physical counts enabled</strong><small>Select the edit action on any row to reconcile stock.</small></span></div>
  <?php endif; ?>
</section>

<section class="inventory-kpi-grid mb-4" aria-label="Inventory summary">
  <div class="inventory-kpi inventory-kpi-total">
    <span class="inventory-kpi-icon"><i class="bi bi-box-seam"></i></span>
    <div><span>On-hand pieces</span><strong><?= number_format($totalUnits) ?></strong><small>Across filtered records</small></div>
  </div>
  <div class="inventory-kpi inventory-kpi-available">
    <span class="inventory-kpi-icon"><i class="bi bi-check2-circle"></i></span>
    <div><span>Available pieces</span><strong><?= number_format($availableUnits) ?></strong><small>After reservations</small></div>
  </div>
  <div class="inventory-kpi inventory-kpi-reserved">
    <span class="inventory-kpi-icon"><i class="bi bi-bookmark-check"></i></span>
    <div><span>Reserved pieces</span><strong><?= number_format($reservedUnits) ?></strong><small>Committed stock</small></div>
  </div>
  <div class="inventory-kpi inventory-kpi-low">
    <span class="inventory-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span>
    <div><span>Low-stock records</span><strong><?= $lowStockCount ?></strong><small>Across <?= $storesShown ?> store<?= $storesShown === 1 ? '' : 's' ?></small></div>
  </div>
</section>

<section class="inventory-panel">
  <header class="inventory-panel-header">
    <div>
      <span class="inventory-section-label">Stock register</span>
      <h3>Inventory by store</h3>
    </div>
    <span class="inventory-result-count"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
  </header>

  <form class="inventory-filters" method="GET" action="inventory.php" role="search">
    <div class="inventory-search">
      <i class="bi bi-search"></i>
      <input type="search" class="form-control" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Search product, SKU, or category">
    </div>
    <select name="store_id" class="form-select" aria-label="Filter by store" <?= $storeScopedViewer ? 'disabled' : '' ?>>
      <?php if (!$storeScopedViewer): ?><option value="0">All stores</option><?php endif; ?>
      <?php foreach ($stores as $store): ?>
      <option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="stock" class="form-select" aria-label="Filter by stock status">
      <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>All stock levels</option>
      <option value="healthy" <?= $stockFilter === 'healthy' ? 'selected' : '' ?>>Healthy stock</option>
      <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option>
      <option value="empty" <?= $stockFilter === 'empty' ? 'selected' : '' ?>>Out of stock</option>
    </select>
    <button class="btn inventory-filter-button" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
    <?php if ($search !== '' || (!$storeScopedViewer && $storeFilter > 0) || $stockFilter !== 'all'): ?>
    <a href="inventory.php" class="btn inventory-clear-button" title="Clear filters"><i class="bi bi-x-lg"></i></a>
    <?php endif; ?>
  </form>

  <div class="table-responsive inventory-table-wrap">
    <table class="table inventory-table align-middle mb-0">
      <thead>
        <tr>
          <th>Product</th>
          <th>Category</th>
          <th>Store</th>
          <th>On hand</th>
          <th>Available</th>
          <th>Reserved</th>
          <th>Recent activity</th>
          <?php if ($canAdjust): ?><th class="text-end">Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="<?= $canAdjust ? 8 : 7 ?>"><div class="inventory-empty"><i class="bi bi-inboxes"></i><strong>No inventory records found</strong><span>Try changing your filters or add products to a store.</span></div></td></tr>
        <?php else: ?>
        <?php $currentStoreId = null; ?>
        <?php foreach ($rows as $row): ?>
        <?php if ($currentStoreId !== (int) $row['store_id']): ?>
        <?php $currentStoreId = (int) $row['store_id']; ?>
        <tr class="inventory-store-group">
          <td colspan="<?= $canAdjust ? 8 : 7 ?>"><i class="bi bi-shop"></i><strong><?= e($row['store_name']) ?></strong></td>
        </tr>
        <?php endif; ?>
        <?php
          $quantity = (int) $row['quantity'];
          $reserved = (int) $row['reserved_quantity'];
          $available = max(0, $quantity - $reserved);
          $isEmpty = $quantity === 0;
          $isLow = $quantity <= (int) $row['reorder_level'];
        ?>
        <tr class="<?= $isLow ? 'inventory-row-low' : '' ?>">
          <td>
            <div class="inventory-product">
              <span><?= e(strtoupper(substr($row['product_name'], 0, 1))) ?></span>
              <div><strong><?= e($row['product_name']) ?></strong><small><?= e($row['sku']) ?></small></div>
            </div>
          </td>
          <td><span class="inventory-category"><?= e($row['category_name']) ?></span></td>
          <td><span class="inventory-store"><i class="bi bi-shop"></i><?= e($row['store_name']) ?></span></td>
          <td><span class="inventory-stock-pill <?= $isEmpty ? 'empty' : ($isLow ? 'low' : 'healthy') ?>"><?= $quantity ?> pieces</span></td>
          <td><strong class="inventory-available"><?= $available ?></strong></td>
          <td><?= $reserved ?></td>
          <td>
            <div class="inventory-activity">
              <span title="Last received"><i class="bi bi-box-arrow-in-down"></i><?= $row['last_received_date'] ? e(date('M j, Y', strtotime($row['last_received_date']))) : 'Never' ?></span>
              <span title="Last sold"><i class="bi bi-bag-check"></i><?= $row['last_sold_date'] ? e(date('M j, Y', strtotime($row['last_sold_date']))) : 'Never' ?></span>
              <span title="Last physical count"><i class="bi bi-clipboard-check"></i><?= $row['last_physical_count'] ? e(date('M j, Y', strtotime($row['last_physical_count']))) : 'Not counted' ?></span>
            </div>
          </td>
          <?php if ($canAdjust): ?>
          <td class="text-end">
            <button class="btn inventory-adjust-button" data-bs-toggle="modal" data-bs-target="#adjustModal<?= (int) $row['id'] ?>" title="Adjust physical count"><i class="bi bi-pencil-square"></i><span>Adjust</span></button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($canAdjust): ?>
<?php foreach ($rows as $row): ?>
<div class="modal fade" id="adjustModal<?= (int) $row['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content inventory-modal">
      <form method="POST" action="inventory.php">
        <?= csrf_field() ?>
        <input type="hidden" name="adjust_stock" value="1">
        <input type="hidden" name="inventory_id" value="<?= (int) $row['id'] ?>">
        <div class="modal-header inventory-modal-header">
          <div>
            <span class="inventory-section-label">Physical count</span>
            <h5 class="modal-title"><?= e($row['product_name']) ?></h5>
            <small><i class="bi bi-shop me-1"></i><?= e($row['store_name']) ?></small>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="inventory-current-count mb-4">
            <span class="inventory-current-icon"><i class="bi bi-boxes"></i></span>
            <div><span>System quantity</span><strong><?= (int) $row['quantity'] ?> pieces</strong></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Counted quantity</label>
            <div class="input-group">
              <input type="number" class="form-control" name="new_quantity" min="0" value="<?= (int) $row['quantity'] ?>" required>
              <span class="input-group-text">pieces</span>
            </div>
            <div class="form-text">Enter the quantity physically counted at this store.</div>
          </div>
          <div>
            <label class="form-label">Adjustment note <span class="text-muted fw-normal">(optional)</span></label>
            <textarea class="form-control" name="notes" rows="3" placeholder="Explain any difference from the system quantity"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0 px-4 pb-4">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn inventory-save-button"><i class="bi bi-check2 me-1"></i>Save count</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
