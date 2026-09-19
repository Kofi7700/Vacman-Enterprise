<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canManage = is_role('admin', 'warehouse');

// ---- Handle Add Product ------------------------------------------------
if (is_post() && isset($_POST['add_product'])) {
    require_role('admin', 'warehouse');
    verify_csrf();

    $errors = validate($_POST, [
        'name' => 'required',
        'sku' => 'required',
        'category_id' => 'required|int',
        'unit_price' => 'required|numeric|positive',
        'cost_price' => 'required|numeric|positive',
        'reorder_level' => 'required|int|positive',
        'min_stock' => 'required|int|positive',
        'max_stock' => 'required|int|positive',
    ]);

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO products (sku, name, description, category_id, barcode, unit_price, cost_price, supplier_name, reorder_level, min_stock, max_stock)
                 VALUES (:sku, :name, :description, :category_id, :barcode, :unit_price, :cost_price, :supplier_name, :reorder_level, :min_stock, :max_stock)'
            );
            $stmt->execute([
                ':sku' => trim($_POST['sku']),
                ':name' => trim($_POST['name']),
                ':description' => trim($_POST['name']) . ' - ' . trim($_POST['supplier_name'] ?? ''),
                ':category_id' => (int) $_POST['category_id'],
                ':barcode' => null, // no longer collected on the Add Product form; still editable afterwards
                ':unit_price' => (float) $_POST['unit_price'],
                ':cost_price' => (float) $_POST['cost_price'],
                ':supplier_name' => trim($_POST['supplier_name'] ?? ''),
                ':reorder_level' => (int) $_POST['reorder_level'],
                ':min_stock' => (int) $_POST['min_stock'],
                ':max_stock' => (int) $_POST['max_stock'],
            ]);
            $productId = (int) $pdo->lastInsertId();

            // Give it a zero-stock row at every active store so it shows
            // up consistently in inventory/transfer screens.
            $storeIds = $pdo->query("SELECT id FROM stores WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            $invStmt = $pdo->prepare('INSERT INTO inventory (product_id, store_id, quantity) VALUES (?, ?, 0)');
            foreach ($storeIds as $storeId) {
                $invStmt->execute([$productId, $storeId]);
            }

            $pdo->commit();
            log_audit('create', 'product', $productId, null, $_POST);
            flash_set('success', 'Product added successfully.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = str_contains($e->getMessage(), 'Duplicate entry') ? 'That SKU already exists.' : 'Could not add the product.';
            error_log('Add product failed: ' . $e->getMessage());
            flash_set('danger', $msg);
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('products.php');
}

// ---- Handle Edit Product -----------------------------------------------
if (is_post() && isset($_POST['edit_product'])) {
    require_role('admin', 'warehouse');
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);
    $errors = validate($_POST, [
        'name' => 'required',
        'sku' => 'required',
        'category_id' => 'required|int',
        'unit_price' => 'required|numeric|positive',
        'cost_price' => 'required|numeric|positive',
        'reorder_level' => 'required|int|positive',
        'min_stock' => 'required|int|positive',
        'max_stock' => 'required|int|positive',
    ]);

    if ($id <= 0) {
        $errors[] = 'Invalid product.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE products SET name = :name, sku = :sku, category_id = :category_id, barcode = :barcode,
                 unit_price = :unit_price, cost_price = :cost_price, supplier_name = :supplier_name,
                 reorder_level = :reorder_level, min_stock = :min_stock, max_stock = :max_stock
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name' => trim($_POST['name']),
                ':sku' => trim($_POST['sku']),
                ':category_id' => (int) $_POST['category_id'],
                ':barcode' => $_POST['barcode'] !== '' ? trim($_POST['barcode']) : null,
                ':unit_price' => (float) $_POST['unit_price'],
                ':cost_price' => (float) $_POST['cost_price'],
                ':supplier_name' => trim($_POST['supplier_name'] ?? ''),
                ':reorder_level' => (int) $_POST['reorder_level'],
                ':min_stock' => (int) $_POST['min_stock'],
                ':max_stock' => (int) $_POST['max_stock'],
                ':id' => $id,
            ]);
            log_audit('update', 'product', $id, null, $_POST);
            flash_set('success', 'Product updated successfully.');
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate entry') ? 'That SKU already exists.' : 'Could not update the product.';
            error_log('Edit product failed: ' . $e->getMessage());
            flash_set('danger', $msg);
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('products.php');
}

// ---- Handle Delete (POST + method override, never GET) -----------------
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

// ---- Data for the page --------------------------------------------
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$search = trim($_GET['q'] ?? '');
$categoryFilter = (int) ($_GET['category_id'] ?? 0);

$sql = "SELECT p.*, c.name AS category_name, COALESCE(SUM(i.quantity), 0) AS total_stock
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON i.product_id = p.id";
$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(p.name LIKE :q OR p.sku LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($categoryFilter > 0) {
    $conditions[] = 'p.category_id = :category_id';
    $params[':category_id'] = $categoryFilter;
}
if (!empty($conditions)) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' GROUP BY p.id ORDER BY p.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = 'Product Management';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex flex-wrap gap-2" method="GET" action="products.php" role="search">
    <input type="search" name="q" class="form-control form-control-sm" style="width: 220px;" placeholder="Search name or SKU" value="<?= old($_GET, 'q') ?>">
    <select name="category_id" class="form-select form-select-sm" style="width: 180px;">
      <option value="">All Categories</option>
      <?php foreach ($categories as $cat): ?>
      <option value="<?= (int) $cat['id'] ?>" <?= $categoryFilter === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-secondary btn-sm" type="submit" title="Search"><i class="bi bi-search"></i></button>
    <?php if ($search !== '' || $categoryFilter > 0): ?>
    <a href="products.php" class="btn btn-outline-secondary btn-sm" title="Clear filters"><i class="bi bi-x-lg"></i></a>
    <?php endif; ?>
  </form>
  <?php if ($canManage): ?>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addProductModal">
    <i class="bi bi-plus-lg me-1"></i> Add Product
  </button>
  <?php endif; ?>
</div>

<div class="card shadow mb-4">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>SKU</th><th>Name</th><th>Category</th><th>Price</th><th>Cost</th>
            <th>Total Stock</th><th>Reorder Level</th><th>Status</th>
            <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($products)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No products found.</td></tr>
          <?php else: foreach ($products as $product): ?>
          <tr>
            <td><?= e($product['sku']) ?></td>
            <td><?= e($product['name']) ?></td>
            <td><?= e($product['category_name']) ?></td>
            <td><?= e(money($product['unit_price'])) ?></td>
            <td><?= e(money($product['cost_price'])) ?></td>
            <td class="<?= (int) $product['total_stock'] <= (int) $product['reorder_level'] ? 'text-danger fw-bold' : '' ?>">
              <?= (int) $product['total_stock'] ?>
            </td>
            <td><?= (int) $product['reorder_level'] ?></td>
            <td><span class="badge bg-<?= $product['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($product['status'])) ?></span></td>
            <?php if ($canManage): ?>
            <td class="text-nowrap">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProductModal<?= (int) $product['id'] ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <?php if (is_role('admin')): ?>
              <form method="POST" action="products.php" class="d-inline" data-confirm="Delete '<?= e($product['name']) ?>'? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>

          <?php if ($canManage): ?>
          <!-- Edit Modal -->
          <div class="modal fade" id="editProductModal<?= (int) $product['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="POST" action="products.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="edit_product" value="1">
                  <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <label class="form-label">Product Name</label>
                      <input type="text" class="form-control" name="name" value="<?= e($product['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">SKU</label>
                      <input type="text" class="form-control" name="sku" value="<?= e($product['sku']) ?>" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Category</label>
                      <select class="form-select" name="category_id" required>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= $cat['id'] == $product['category_id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Barcode</label>
                      <input type="text" class="form-control" name="barcode" value="<?= e($product['barcode']) ?>">
                    </div>
                    <div class="row">
                      <div class="col-md-6 mb-3">
                        <label class="form-label">Selling Price (<?= e(CURRENCY_SYMBOL) ?>)</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="unit_price" value="<?= e((string) $product['unit_price']) ?>" required>
                      </div>
                      <div class="col-md-6 mb-3">
                        <label class="form-label">Cost Price (<?= e(CURRENCY_SYMBOL) ?>)</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="cost_price" value="<?= e((string) $product['cost_price']) ?>" required>
                      </div>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Supplier</label>
                      <input type="text" class="form-control" name="supplier_name" value="<?= e($product['supplier_name']) ?>">
                    </div>
                    <div class="row">
                      <div class="col-md-4 mb-3">
                        <label class="form-label">Reorder Level</label>
                        <input type="number" min="0" class="form-control" name="reorder_level" value="<?= (int) $product['reorder_level'] ?>" required>
                      </div>
                      <div class="col-md-4 mb-3">
                        <label class="form-label">Min Stock</label>
                        <input type="number" min="0" class="form-control" name="min_stock" value="<?= (int) $product['min_stock'] ?>" required>
                      </div>
                      <div class="col-md-4 mb-3">
                        <label class="form-label">Max Stock</label>
                        <input type="number" min="0" class="form-control" name="max_stock" value="<?= (int) $product['max_stock'] ?>" required>
                      </div>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Product</button>
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

<?php if ($canManage): ?>
<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="products.php" id="addProductForm">
        <?= csrf_field() ?>
        <input type="hidden" name="add_product" value="1">
        <div class="modal-header">
          <h5 class="modal-title">Add New Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Product Name</label>
            <input type="text" class="form-control" name="name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">SKU</label>
            <input type="text" class="form-control" name="sku" placeholder="YB-CM-001" required>
          </div>
          <div class="mb-3 position-relative">
            <label class="form-label">Category</label>
            <input type="text" class="form-control" id="addCategorySearch" placeholder="Type to search categories..." autocomplete="off" required>
            <input type="hidden" name="category_id" id="addCategoryId">
            <div id="addCategoryResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:1060; max-height:200px; overflow-y:auto; display:none;"></div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Selling Price (<?= e(CURRENCY_SYMBOL) ?>)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="unit_price" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Cost Price (<?= e(CURRENCY_SYMBOL) ?>)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="cost_price" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Supplier</label>
            <input type="text" class="form-control" name="supplier_name">
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Reorder Level</label>
              <input type="number" min="0" class="form-control" name="reorder_level" value="10" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Min Stock</label>
              <input type="number" min="0" class="form-control" name="min_stock" value="5" required>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Max Stock</label>
              <input type="number" min="0" class="form-control" name="max_stock" value="100" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Add Product</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraScripts = '<script>
  const productCategories = ' . json_encode(array_map(function ($c) {
        return ['id' => (int) $c['id'], 'name' => $c['name']];
    }, $categories)) . ';

  function initCategorySearch(searchId, hiddenId, resultsId) {
    const searchInput = document.getElementById(searchId);
    const hiddenInput = document.getElementById(hiddenId);
    const resultsBox = document.getElementById(resultsId);
    if (!searchInput) return;

    function renderResults(query) {
      const q = query.trim().toLowerCase();
      if (!q) { resultsBox.style.display = "none"; resultsBox.innerHTML = ""; return; }
      const matches = productCategories.filter(function (c) { return c.name.toLowerCase().includes(q); }).slice(0, 8);
      if (!matches.length) {
        resultsBox.innerHTML = "<div class=\"list-group-item text-muted small\">No matching categories</div>";
        resultsBox.style.display = "block";
        return;
      }
      resultsBox.innerHTML = matches.map(function (c) {
        return "<button type=\"button\" class=\"list-group-item list-group-item-action py-2 cat-search-result\" data-id=\"" + c.id + "\" data-name=\"" + c.name + "\">" + c.name + "</button>";
      }).join("");
      resultsBox.style.display = "block";
      resultsBox.querySelectorAll(".cat-search-result").forEach(function (btn) {
        btn.addEventListener("click", function () {
          searchInput.value = btn.getAttribute("data-name");
          hiddenInput.value = btn.getAttribute("data-id");
          resultsBox.style.display = "none";
          resultsBox.innerHTML = "";
        });
      });
    }

    searchInput.addEventListener("input", function () {
      hiddenInput.value = ""; // typing invalidates any prior selection until they pick again
      renderResults(this.value);
    });
    searchInput.addEventListener("focus", function () { this.select(); });
    searchInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        const first = resultsBox.querySelector(".cat-search-result");
        if (first) first.click();
      }
    });
    document.addEventListener("click", function (e) {
      if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
        resultsBox.style.display = "none";
      }
    });
  }

  initCategorySearch("addCategorySearch", "addCategoryId", "addCategoryResults");

  const addProductForm = document.getElementById("addProductForm");
  if (addProductForm) {
    addProductForm.addEventListener("submit", function (e) {
      if (!document.getElementById("addCategoryId").value) {
        e.preventDefault();
        alert("Please search for and select a category from the list.");
      }
    });
  }

  const addProductModal = document.getElementById("addProductModal");
  if (addProductModal) {
    addProductModal.addEventListener("show.bs.modal", function () {
      document.getElementById("addCategorySearch").value = "";
      document.getElementById("addCategoryId").value = "";
    });
  }
</script>';
require __DIR__ . '/includes/footer.php';
?>
