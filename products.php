<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canManage = is_role('admin', 'warehouse');
$canViewAllStores = is_role('admin', 'warehouse', 'manager');
$assignedStoreId = $canViewAllStores ? 0 : (int) (current_user()['store_id'] ?? 0);

// ---- Handle Add Product -----------------------------------------------
if (is_post() && isset($_POST['add_product'])) {
    require_role('admin', 'warehouse');
    verify_csrf();

    $errors = validate($_POST, [
        'name' => 'required',
        'category_id' => 'required|int',
        'store_id' => 'required|int',
        'opening_stock' => 'required|int|positive',
        'unit_price' => 'required|numeric|positive',
        'reorder_level' => 'required|int|positive',
        'min_stock' => 'required|int|positive',
        'max_stock' => 'required|int|positive',
    ]);

    $minStock = (int) ($_POST['min_stock'] ?? 0);
    $maxStock = (int) ($_POST['max_stock'] ?? 0);
    $reorderLevel = (int) ($_POST['reorder_level'] ?? 0);
    if ($minStock > $maxStock) {
        $errors[] = 'Minimum stock cannot be greater than maximum stock.';
    }
    if ($reorderLevel > $maxStock) {
        $errors[] = 'Reorder level cannot be greater than maximum stock.';
    }

    $storeId = (int) ($_POST['store_id'] ?? 0);
    if (empty($errors)) {
        $storeStmt = $pdo->prepare("SELECT id, name FROM stores WHERE id = ? AND status = 'active'");
        $storeStmt->execute([$storeId]);
        $selectedStore = $storeStmt->fetch();
        if (!$selectedStore) {
            $errors[] = 'Select an active store.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // The temporary value satisfies the unique, non-null SKU column.
            // It is replaced with a stable product code as soon as the ID exists.
            $temporarySku = 'AUTO-' . bin2hex(random_bytes(12));
            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (sku, name, description, category_id, barcode, unit_price, supplier_name, reorder_level, min_stock, max_stock)
                 VALUES
                    (:sku, :name, :description, :category_id, NULL, :unit_price, :supplier_name, :reorder_level, :min_stock, :max_stock)'
            );
            $stmt->execute([
                ':sku' => $temporarySku,
                ':name' => trim($_POST['name']),
                ':description' => trim($_POST['description'] ?? '') ?: null,
                ':category_id' => (int) $_POST['category_id'],
                ':unit_price' => (float) $_POST['unit_price'],
                ':supplier_name' => trim($_POST['supplier_name'] ?? '') ?: null,
                ':reorder_level' => $reorderLevel,
                ':min_stock' => $minStock,
                ':max_stock' => $maxStock,
            ]);

            $productId = (int) $pdo->lastInsertId();
            $sku = 'PRD-' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE products SET sku = ? WHERE id = ?')->execute([$sku, $productId]);

            $openingStock = (int) $_POST['opening_stock'];
            $pdo->prepare(
                'INSERT INTO inventory (product_id, store_id, quantity, last_received_date)
                 VALUES (?, ?, ?, CASE WHEN ? > 0 THEN CURDATE() ELSE NULL END)'
            )->execute([$productId, $storeId, $openingStock, $openingStock]);

            $pdo->commit();
            log_audit('create', 'product', $productId, null, [
                'sku' => $sku,
                'name' => trim($_POST['name']),
                'store_id' => $storeId,
                'opening_stock' => $openingStock,
                'unit_price' => (float) $_POST['unit_price'],
            ]);
            flash_set('success', "{$sku} was added to {$selectedStore['name']} successfully.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Add product failed: ' . $e->getMessage());
            flash_set('danger', 'Could not add the product. Please try again.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('products.php');
}

// ---- Handle Edit Product ----------------------------------------------
if (is_post() && isset($_POST['edit_product'])) {
    require_role('admin', 'warehouse');
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $errors = validate($_POST, [
        'name' => 'required',
        'category_id' => 'required|int',
        'store_id' => 'required|int',
        'stock' => 'required|int|positive',
        'unit_price' => 'required|numeric|positive',
        'reorder_level' => 'required|int|positive',
        'min_stock' => 'required|int|positive',
        'max_stock' => 'required|int|positive',
    ]);

    $minStock = (int) ($_POST['min_stock'] ?? 0);
    $maxStock = (int) ($_POST['max_stock'] ?? 0);
    $reorderLevel = (int) ($_POST['reorder_level'] ?? 0);
    $storeId = (int) ($_POST['store_id'] ?? 0);
    $stock = (int) ($_POST['stock'] ?? 0);
    if ($id <= 0) {
        $errors[] = 'Invalid product.';
    }
    if ($minStock > $maxStock) {
        $errors[] = 'Minimum stock cannot be greater than maximum stock.';
    }
    if ($reorderLevel > $maxStock) {
        $errors[] = 'Reorder level cannot be greater than maximum stock.';
    }

    $selectedStore = null;
    if (empty($errors)) {
        $storeStmt = $pdo->prepare("SELECT id, name FROM stores WHERE id = ? AND status = 'active'");
        $storeStmt->execute([$storeId]);
        $selectedStore = $storeStmt->fetch();
        if (!$selectedStore) {
            $errors[] = 'Select an active store.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $oldInventoryStmt = $pdo->prepare('SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ? FOR UPDATE');
            $oldInventoryStmt->execute([$id, $storeId]);
            $oldInventory = $oldInventoryStmt->fetch();

            $stmt = $pdo->prepare(
                'UPDATE products SET name = :name, description = :description, category_id = :category_id,
                 unit_price = :unit_price, supplier_name = :supplier_name,
                 reorder_level = :reorder_level, min_stock = :min_stock, max_stock = :max_stock
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => trim($_POST['name']),
                ':description' => trim($_POST['description'] ?? '') ?: null,
                ':category_id' => (int) $_POST['category_id'],
                ':unit_price' => (float) $_POST['unit_price'],
                ':supplier_name' => trim($_POST['supplier_name'] ?? '') ?: null,
                ':reorder_level' => $reorderLevel,
                ':min_stock' => $minStock,
                ':max_stock' => $maxStock,
                ':id' => $id,
            ]);

            $pdo->prepare(
                'INSERT INTO inventory (product_id, store_id, quantity, last_physical_count)
                 VALUES (?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), last_physical_count = CURDATE()'
            )->execute([$id, $storeId, $stock]);

            $inventoryIdStmt = $pdo->prepare('SELECT id FROM inventory WHERE product_id = ? AND store_id = ?');
            $inventoryIdStmt->execute([$id, $storeId]);
            $inventoryId = (int) $inventoryIdStmt->fetchColumn();

            $pdo->commit();
            log_audit('update', 'product', $id, null, [
                'name' => trim($_POST['name']),
                'unit_price' => (float) $_POST['unit_price'],
                'store_id' => $storeId,
                'stock' => $stock,
            ]);
            log_audit(
                'stock_adjustment',
                'inventory',
                $inventoryId,
                ['quantity' => $oldInventory ? (int) $oldInventory['quantity'] : null],
                ['quantity' => $stock, 'product_id' => $id, 'store_id' => $storeId]
            );
            flash_set('success', 'Product and ' . $selectedStore['name'] . ' stock updated successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Edit product failed: ' . $e->getMessage());
            flash_set('danger', 'Could not update the product.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('products.php');
}

// ---- Handle Delete (POST + method override, never GET) ----------------
if (is_post() && request_method() === 'DELETE') {
    require_role('admin');
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
            log_audit('delete', 'product', $id);
            flash_set('success', 'Product deleted successfully.');
        } catch (PDOException $e) {
            error_log('Delete product failed: ' . $e->getMessage());
            flash_set('danger', 'Could not delete the product. It may have sales or transfer history tied to it.');
        }
    }
    redirect('products.php');
}

// ---- Data for the page ------------------------------------------------
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$stores = $pdo->query("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name")->fetchAll();

$search = trim($_GET['q'] ?? '');
$categoryFilter = (int) ($_GET['category_id'] ?? 0);
$storeFilter = $canViewAllStores ? (int) ($_GET['store_id'] ?? 0) : $assignedStoreId;

$sql = "SELECT p.*, c.name AS category_name,
               i.id AS inventory_id, i.store_id, i.quantity AS total_stock,
               s.name AS store_name
        FROM products p
        JOIN categories c ON p.category_id = c.id
        JOIN inventory i ON i.product_id = p.id
        JOIN stores s ON s.id = i.store_id AND s.status = 'active'";
$conditions = [];
$params = [];
if (!$canViewAllStores) {
    if ($assignedStoreId > 0) {
        $conditions[] = 'i.store_id = :assigned_store_id';
        $params[':assigned_store_id'] = $assignedStoreId;
    } else {
        $conditions[] = '1 = 0';
    }
} elseif ($storeFilter > 0) {
    $conditions[] = 'i.store_id = :store_filter';
    $params[':store_filter'] = $storeFilter;
}
if ($categoryFilter > 0) {
    $conditions[] = 'p.category_id = :category_id';
    $params[':category_id'] = $categoryFilter;
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY s.name, p.name, p.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$inventoryByProduct = [];
if ($canManage && $products) {
    $productIds = array_values(array_unique(array_map('intval', array_column($products, 'id'))));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $inventoryStmt = $pdo->prepare(
        "SELECT product_id, store_id, quantity FROM inventory WHERE product_id IN ($placeholders) ORDER BY store_id"
    );
    $inventoryStmt->execute($productIds);
    foreach ($inventoryStmt->fetchAll() as $inventoryRow) {
        $inventoryByProduct[(int) $inventoryRow['product_id']][(int) $inventoryRow['store_id']] = (int) $inventoryRow['quantity'];
    }
}

$productCount = count($products);
$totalUnits = array_sum(array_map(static fn(array $product): int => (int) $product['total_stock'], $products));
$lowStockCount = count(array_filter(
    $products,
    static fn(array $product): bool => (int) $product['total_stock'] <= (int) $product['reorder_level']
));
$activeCount = count(array_filter(
    $products,
    static fn(array $product): bool => $product['status'] === 'active'
));

$pageTitle = 'Products';
require __DIR__ . '/includes/header.php';
?>

<section class="product-hero mb-4">
  <div>
    <span class="product-eyebrow">Inventory catalogue</span>
    <h2 class="h4 mb-1">Manage your products</h2>
    <p class="mb-0 text-muted">Add products to a store, monitor stock, and maintain selling prices.</p>
  </div>
  <?php if ($canManage): ?>
  <button class="btn btn-danger btn-lg product-add-button" data-bs-toggle="modal" data-bs-target="#addProductModal" <?= empty($stores) ? 'disabled' : '' ?>>
    <i class="bi bi-plus-lg me-2"></i>Add product
  </button>
  <?php endif; ?>
</section>

<?php if ($canManage && empty($stores)): ?>
<div class="alert alert-warning d-flex align-items-center" role="alert">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  <div>Create an active store before adding a product.</div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="product-stat-card product-stat-primary">
      <span class="product-stat-icon bg-primary-subtle text-primary"><i class="bi bi-box-seam"></i></span>
      <div><div class="product-stat-value" id="productVisibleCount"><?= $productCount ?></div><div class="product-stat-label">Store products shown</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="product-stat-card product-stat-success">
      <span class="product-stat-icon bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></span>
      <div><div class="product-stat-value"><?= $activeCount ?></div><div class="product-stat-label">Active products</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="product-stat-card product-stat-info">
      <span class="product-stat-icon bg-info-subtle text-info"><i class="bi bi-boxes"></i></span>
      <div><div class="product-stat-value"><?= number_format($totalUnits) ?></div><div class="product-stat-label">Pieces in stock</div></div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="product-stat-card product-stat-danger">
      <span class="product-stat-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-circle"></i></span>
      <div><div class="product-stat-value"><?= $lowStockCount ?></div><div class="product-stat-label">Low-stock products</div></div>
    </div>
  </div>
</div>

<div class="card product-panel border-0 shadow-sm">
  <div class="card-body p-3 p-lg-4">
    <form class="product-filters mb-4" method="GET" action="products.php" role="search">
      <div class="product-search-wrap">
        <i class="bi bi-search"></i>
        <input type="search" name="q" id="productLiveSearch" class="form-control" placeholder="Start typing a product name, code, category, supplier, or store" value="<?= old($_GET, 'q') ?>" autocomplete="off">
      </div>
      <select name="category_id" class="form-select product-category-filter" aria-label="Filter by category">
        <option value="">All categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= (int) $cat['id'] ?>" <?= $categoryFilter === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($canViewAllStores): ?>
      <select name="store_id" class="form-select product-category-filter" aria-label="Search by store">
        <option value="0">All stores</option>
        <?php foreach ($stores as $store): ?>
        <option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn product-search-button px-4" type="submit"><i class="bi bi-search me-2"></i>Search</button>
      <?php if ($search !== '' || $categoryFilter > 0 || ($canViewAllStores && $storeFilter > 0)): ?>
      <a href="products.php" class="btn btn-light border" title="Clear filters"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>

    <div class="table-responsive">
      <table class="table product-table align-middle mb-0">
        <thead>
          <tr>
            <th>Product</th>
            <th>Category</th>
            <th>Store</th>
            <th>Selling price</th>
            <th>Stock</th>
            <th>Status</th>
            <?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody id="productTableBody">
          <?php if (!$products): ?>
          <tr>
            <td colspan="<?= $canManage ? 7 : 6 ?>">
              <div class="product-empty-state">
                <span><i class="bi bi-inboxes"></i></span>
                <h3 class="h6">No products found</h3>
                <p>Try another search or add your first product.</p>
              </div>
            </td>
          </tr>
          <?php else: ?>
          <?php $currentStoreId = null; ?>
          <?php foreach ($products as $product): ?>
          <?php if ($currentStoreId !== (int) $product['store_id']): ?>
          <?php $currentStoreId = (int) $product['store_id']; ?>
          <tr class="table-light product-store-group" data-store-id="<?= (int) $product['store_id'] ?>">
            <td colspan="<?= $canManage ? 7 : 6 ?>" class="fw-semibold text-dark py-3">
              <i class="bi bi-shop me-2 text-muted"></i><?= e($product['store_name']) ?>
            </td>
          </tr>
          <?php endif; ?>
          <?php $isLowStock = (int) $product['total_stock'] <= (int) $product['reorder_level']; ?>
          <tr class="product-data-row" data-store-id="<?= (int) $product['store_id'] ?>" data-search="<?= e(implode(' ', array_filter([$product['name'], $product['sku'], $product['category_name'], $product['supplier_name'] ?? '', $product['store_name'], $product['description'] ?? '']))) ?>">
            <td>
              <div class="d-flex align-items-center gap-3">
                <span class="product-avatar"><?= e(strtoupper(substr($product['name'], 0, 1))) ?></span>
                <div>
                  <div class="fw-semibold text-dark"><?= e($product['name']) ?></div>
                  <div class="small text-muted font-monospace"><?= e($product['sku']) ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge rounded-pill text-bg-light border fw-normal"><?= e($product['category_name']) ?></span></td>
            <td><div class="product-store-cell"><i class="bi bi-shop me-1"></i><?= e($product['store_name']) ?></div></td>
            <td class="fw-semibold"><?= e(money($product['unit_price'])) ?> / piece</td>
            <td>
              <span class="stock-pill <?= $isLowStock ? 'stock-low' : 'stock-ok' ?>">
                <?= (int) $product['total_stock'] ?> pieces
              </span>
            </td>
            <td><span class="status-dot status-<?= e($product['status']) ?>"></span><?= e(ucwords(str_replace('_', ' ', $product['status']))) ?></td>
            <?php if ($canManage): ?>
            <td class="text-end text-nowrap">
              <button class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#editProductModal<?= (int) $product['inventory_id'] ?>" title="Edit product"><i class="bi bi-pencil-square"></i></button>
              <?php if (is_role('admin')): ?>
              <form method="POST" action="products.php" class="d-inline" data-confirm="Delete '<?= e($product['name']) ?>'? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                <button type="submit" class="btn btn-sm btn-light border text-danger" title="Delete product"><i class="bi bi-trash3"></i></button>
              </form>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          <tr id="productLiveEmpty" style="display:none">
            <td colspan="<?= $canManage ? 7 : 6 ?>">
              <div class="product-empty-state">
                <span><i class="bi bi-search"></i></span>
                <h3 class="h6">No matching products</h3>
                <p>Try a different name, code, category, supplier, or store.</p>
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($canManage): ?>
<?php foreach ($products as $product): ?>
<?php
$productInventory = $inventoryByProduct[(int) $product['id']] ?? [];
$selectedStoreId = (int) $product['store_id'];
$editRowId = (int) $product['inventory_id'];
?>
<div class="modal fade" id="editProductModal<?= $editRowId ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content product-modal">
      <form method="POST" action="products.php">
        <?= csrf_field() ?>
        <input type="hidden" name="edit_product" value="1">
        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
        <div class="modal-header product-modal-header">
          <div>
            <span class="product-eyebrow">Product details</span>
            <h5 class="modal-title mb-0">Edit <?= e($product['name']) ?></h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Product name</label>
              <input type="text" class="form-control" name="name" value="<?= e($product['name']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Product code</label>
              <input type="text" class="form-control bg-light font-monospace" value="<?= e($product['sku']) ?>" readonly>
              <div class="form-text">Generated automatically</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <select class="form-select" name="category_id" required>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>" <?= (int) $cat['id'] === (int) $product['category_id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Selling price per piece (<?= e(CURRENCY_SYMBOL) ?>)</label>
              <div class="input-group">
                <span class="input-group-text"><?= e(CURRENCY_CODE) ?></span>
                <input type="number" step="0.01" min="0" class="form-control" name="unit_price" value="<?= e((string) $product['unit_price']) ?>" required>
              </div>
            </div>
            <div class="col-md-7">
              <label class="form-label" for="editStore<?= $editRowId ?>">Store</label>
              <select class="form-select product-edit-store" name="store_id" id="editStore<?= $editRowId ?>" data-stock-target="editStock<?= $editRowId ?>" required>
                <?php foreach ($stores as $store): ?>
                <?php $storeId = (int) $store['id']; ?>
                <option value="<?= $storeId ?>" data-stock="<?= $productInventory[$storeId] ?? 0 ?>" <?= $storeId === $selectedStoreId ? 'selected' : '' ?>><?= e($store['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Choose a store to view or update its stock.</div>
            </div>
            <div class="col-md-5">
              <label class="form-label" for="editStock<?= $editRowId ?>">Stock</label>
              <div class="input-group">
                <input type="number" min="0" class="form-control" name="stock" id="editStock<?= $editRowId ?>" value="<?= $productInventory[$selectedStoreId] ?? 0 ?>" required>
                <span class="input-group-text">pieces</span>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Description <span class="text-muted fw-normal">(optional)</span></label>
              <textarea class="form-control" name="description" rows="2"><?= e($product['description']) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Supplier <span class="text-muted fw-normal">(optional)</span></label>
              <input type="text" class="form-control" name="supplier_name" value="<?= e($product['supplier_name']) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">Reorder at</label>
              <input type="number" min="0" class="form-control" name="reorder_level" value="<?= (int) $product['reorder_level'] ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Minimum</label>
              <input type="number" min="0" class="form-control" name="min_stock" value="<?= (int) $product['min_stock'] ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">Maximum</label>
              <input type="number" min="0" class="form-control" name="max_stock" value="<?= (int) $product['max_stock'] ?>" required>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 px-4 pb-4">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger px-4">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content product-modal">
      <form method="POST" action="products.php" id="addProductForm">
        <?= csrf_field() ?>
        <input type="hidden" name="add_product" value="1">
        <div class="modal-header product-modal-header">
          <div>
            <span class="product-eyebrow">New inventory item</span>
            <h5 class="modal-title mb-0">Add a product</h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="auto-code-note mb-4">
            <i class="bi bi-stars"></i>
            <div><strong>Product code is automatic</strong><span>A unique code will be created when you save this product.</span></div>
          </div>
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label">Product name</label>
              <input type="text" class="form-control" name="name" placeholder="e.g. Cement (50kg)" required autofocus>
            </div>
            <div class="col-md-5">
              <label class="form-label">Category</label>
              <select class="form-select" name="category_id" required>
                <option value="" selected disabled>Select category</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-7">
              <label class="form-label">Store</label>
              <select class="form-select" name="store_id" required>
                <option value="" selected disabled>Select the store receiving this product</option>
                <?php foreach ($stores as $store): ?>
                <option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">The product will initially be stocked only at this store.</div>
            </div>
            <div class="col-md-5">
              <label class="form-label">Opening stock</label>
              <div class="input-group">
                <input type="number" min="0" class="form-control" name="opening_stock" value="0" required>
                <span class="input-group-text">pieces</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Selling price per piece (<?= e(CURRENCY_SYMBOL) ?>)</label>
              <div class="input-group">
                <span class="input-group-text"><?= e(CURRENCY_CODE) ?></span>
                <input type="number" step="0.01" min="0" class="form-control" name="unit_price" placeholder="0.00" required>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Supplier <span class="text-muted fw-normal">(optional)</span></label>
              <input type="text" class="form-control" name="supplier_name" placeholder="Supplier or distributor">
            </div>
            <div class="col-12">
              <label class="form-label">Description <span class="text-muted fw-normal">(optional)</span></label>
              <textarea class="form-control" name="description" rows="2" placeholder="Add useful product details"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Reorder level</label>
              <input type="number" min="0" class="form-control" name="reorder_level" value="10" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Minimum stock</label>
              <input type="number" min="0" class="form-control" name="min_stock" value="5" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Maximum stock</label>
              <input type="number" min="0" class="form-control" name="max_stock" value="100" required>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 px-4 pb-4">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger px-4"><i class="bi bi-plus-lg me-1"></i>Add product</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<script>
document.querySelectorAll('.product-edit-store').forEach(function (storeSelect) {
  storeSelect.addEventListener('change', function () {
    const stockInput = document.getElementById(storeSelect.dataset.stockTarget);
    const selectedOption = storeSelect.options[storeSelect.selectedIndex];
    if (stockInput && selectedOption) stockInput.value = selectedOption.dataset.stock || '0';
  });
});
</script>
<?php endif; ?>

<script>
(function () {
  const searchInput = document.getElementById('productLiveSearch');
  const productRows = Array.from(document.querySelectorAll('.product-data-row'));
  const storeGroups = Array.from(document.querySelectorAll('.product-store-group'));
  const emptyRow = document.getElementById('productLiveEmpty');
  const visibleCount = document.getElementById('productVisibleCount');

  if (!searchInput || !productRows.length) return;

  function normalize(value) {
    return String(value || '').trim().toLocaleLowerCase();
  }

  function filterProducts() {
    const query = normalize(searchInput.value);
    const visibleStores = new Set();
    let matches = 0;

    productRows.forEach(function (row) {
      const isMatch = query === '' || normalize(row.dataset.search).includes(query);
      row.hidden = !isMatch;
      if (isMatch) {
        matches += 1;
        visibleStores.add(row.dataset.storeId);
      }
    });

    storeGroups.forEach(function (row) {
      row.hidden = !visibleStores.has(row.dataset.storeId);
    });

    if (emptyRow) emptyRow.style.display = matches === 0 ? '' : 'none';
    if (visibleCount) visibleCount.textContent = String(matches);
  }

  searchInput.addEventListener('input', filterProducts);
  filterProducts();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
