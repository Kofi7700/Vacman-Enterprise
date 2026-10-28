<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager', 'mini_manager', 'warehouse');

$pdo = db();
$canReceive = is_role('admin', 'warehouse');
$canCancel = is_role('admin', 'manager', 'mini_manager');
$miniManagerStoreId = is_role('mini_manager') && !is_role('admin', 'manager', 'warehouse') ? (int) (current_user()['store_id'] ?? 0) : 0;

// ---- Create purchase order -------------------------------------------
if (is_post() && isset($_POST['add_purchase'])) {
    verify_csrf();

    $errors = validate($_POST, [
        'product_id' => 'required|int',
        'store_id' => 'required|int',
        'supplier_name' => 'required',
        'quantity' => 'required|int|positive',
        'unit_cost' => 'required|numeric|positive',
        'purchase_date' => 'required',
    ]);

    $quantity = (int) ($_POST['quantity'] ?? 0);
    $unitCost = (float) ($_POST['unit_cost'] ?? 0);
    if ($quantity < 1) $errors[] = 'Quantity must be at least 1.';
    if ($unitCost <= 0) $errors[] = 'Cost per piece must be greater than 0.';
    if ($miniManagerStoreId > 0 && (int) ($_POST['store_id'] ?? 0) !== $miniManagerStoreId) $errors[] = 'Mini managers can create purchase orders only for their assigned store.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO purchases (product_id, store_id, supplier_name, purchase_order_number, quantity, unit_cost, total_cost, purchase_date, expected_delivery_date, notes)
                 VALUES (:product_id, :store_id, :supplier_name, :po_number, :quantity, :unit_cost, :total_cost, :purchase_date, :expected_date, :notes)'
            );
            $stmt->execute([
                ':product_id' => (int) $_POST['product_id'],
                ':store_id' => (int) $_POST['store_id'],
                ':supplier_name' => trim($_POST['supplier_name']),
                ':po_number' => trim($_POST['purchase_order_number'] ?? '') ?: ('PO-' . date('Ymd-His')),
                ':quantity' => $quantity,
                ':unit_cost' => $unitCost,
                ':total_cost' => $unitCost * $quantity,
                ':purchase_date' => $_POST['purchase_date'],
                ':expected_date' => trim($_POST['expected_delivery_date'] ?? '') ?: null,
                ':notes' => trim($_POST['notes'] ?? '') ?: null,
            ]);
            $purchaseId = (int) $pdo->lastInsertId();
            log_audit('create', 'purchase', $purchaseId, null, [
                'product_id' => (int) $_POST['product_id'],
                'store_id' => (int) $_POST['store_id'],
                'quantity' => $quantity,
                'total_cost' => $unitCost * $quantity,
            ]);
            flash_set('success', 'Purchase order created.');
        } catch (RuntimeException $e) {
            flash_set('danger', $e->getMessage());
        } catch (PDOException $e) {
            error_log('Create purchase failed: ' . $e->getMessage());
            flash_set('danger', 'Could not create the purchase order.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('purchases.php');
}

// ---- Receive full order and add stock --------------------------------
if (is_post() && isset($_POST['mark_delivered'])) {
    require_role('admin', 'warehouse');
    verify_csrf();
    $purchaseId = (int) ($_POST['id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $purchaseStmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? FOR UPDATE');
        $purchaseStmt->execute([$purchaseId]);
        $purchase = $purchaseStmt->fetch();

        if (!$purchase) {
            throw new RuntimeException('Purchase order not found.');
        }
        if ($purchase['status'] !== 'ordered') {
            throw new RuntimeException('Only an ordered purchase can be marked delivered.');
        }

        $inventoryStmt = $pdo->prepare('SELECT id FROM inventory WHERE product_id = ? AND store_id = ? FOR UPDATE');
        $inventoryStmt->execute([$purchase['product_id'], $purchase['store_id']]);
        $inventory = $inventoryStmt->fetch();

        if ($inventory) {
            $pdo->prepare('UPDATE inventory SET quantity = quantity + ?, last_received_date = CURDATE() WHERE id = ?')
                ->execute([$purchase['quantity'], $inventory['id']]);
        } else {
            $pdo->prepare('INSERT INTO inventory (product_id, store_id, quantity, last_received_date) VALUES (?, ?, ?, CURDATE())')
                ->execute([$purchase['product_id'], $purchase['store_id'], $purchase['quantity']]);
        }

        $pdo->prepare(
            'UPDATE purchases SET status = "delivered", received_quantity = quantity, actual_delivery_date = CURDATE(), received_by = ? WHERE id = ?'
        )->execute([current_user()['id'], $purchaseId]);

        $pdo->commit();
        log_audit('status_change', 'purchase', $purchaseId, ['status' => 'ordered'], ['status' => 'delivered']);
        flash_set('success', number_format((int) $purchase['quantity']) . ' pieces received and added to inventory.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Mark delivered failed: ' . $e->getMessage());
        flash_set('danger', 'Could not receive the purchase order.');
    }
    redirect('purchases.php');
}

// ---- Cancel an outstanding order -------------------------------------
if (is_post() && isset($_POST['cancel_purchase'])) {
    require_role('admin', 'manager', 'mini_manager');
    verify_csrf();
    $purchaseId = (int) ($_POST['id'] ?? 0);

    $cancelSql = "UPDATE purchases SET status = 'cancelled' WHERE id = ? AND status = 'ordered'";
    $cancelParams = [$purchaseId];
    if ($miniManagerStoreId > 0) { $cancelSql .= ' AND store_id = ?'; $cancelParams[] = $miniManagerStoreId; }
    $stmt = $pdo->prepare($cancelSql);
    $stmt->execute($cancelParams);
    if ($stmt->rowCount() === 1) {
        log_audit('status_change', 'purchase', $purchaseId, ['status' => 'ordered'], ['status' => 'cancelled']);
        flash_set('success', 'Purchase order cancelled.');
    } else {
        flash_set('warning', 'Only an ordered purchase can be cancelled.');
    }
    redirect('purchases.php');
}

// ---- Data and filters -------------------------------------------------
$products = $pdo->query('SELECT id, name, sku, supplier_name FROM products WHERE status = "active" ORDER BY name')->fetchAll();
$storeSql = 'SELECT id, name FROM stores WHERE status = "active"';
if ($miniManagerStoreId > 0) $storeSql .= ' AND id = ' . $miniManagerStoreId;
$stores = $pdo->query($storeSql . ' ORDER BY name')->fetchAll();

$preselectProduct = (int) ($_GET['product_id'] ?? 0);
$preselectProductLabel = '';
foreach ($products as $product) {
    if ((int) $product['id'] === $preselectProduct) {
        $preselectProductLabel = $product['name'] . ' (' . $product['sku'] . ')';
        break;
    }
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$storeFilter = (int) ($_GET['store_id'] ?? 0);
if (!in_array($statusFilter, ['all', 'ordered', 'delivered', 'cancelled'], true)) $statusFilter = 'all';

$sql = "SELECT pu.*, p.name AS product_name, p.sku, s.name AS store_name
        FROM purchases pu
        JOIN products p ON p.id = pu.product_id
        JOIN stores s ON s.id = pu.store_id";
$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(pu.purchase_order_number LIKE :q_po OR p.name LIKE :q_product OR pu.supplier_name LIKE :q_supplier)';
    $params[':q_po'] = '%' . $search . '%';
    $params[':q_product'] = '%' . $search . '%';
    $params[':q_supplier'] = '%' . $search . '%';
}
if ($statusFilter !== 'all') {
    $conditions[] = 'pu.status = :status';
    $params[':status'] = $statusFilter;
}
if ($storeFilter > 0) {
    $conditions[] = 'pu.store_id = :store_id';
    $params[':store_id'] = $storeFilter;
}
if ($miniManagerStoreId > 0) {
    $conditions[] = 'pu.store_id = :mini_manager_store';
    $params[':mini_manager_store'] = $miniManagerStoreId;
}
if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
$sql .= ' ORDER BY pu.created_at DESC LIMIT 100';

$purchaseStmt = $pdo->prepare($sql);
$purchaseStmt->execute($params);
$purchases = $purchaseStmt->fetchAll();

$purchaseStoreScope = $miniManagerStoreId > 0 ? ' WHERE store_id = ' . $miniManagerStoreId : '';
$purchaseMetrics = $pdo->query("
    SELECT
      COUNT(*) AS total_orders,
      SUM(status = 'ordered') AS open_orders,
      COALESCE(SUM(CASE WHEN status = 'ordered' THEN total_cost ELSE 0 END), 0) AS outstanding_value,
      COALESCE(SUM(CASE WHEN status = 'delivered' AND MONTH(actual_delivery_date) = MONTH(CURDATE()) AND YEAR(actual_delivery_date) = YEAR(CURDATE()) THEN total_cost ELSE 0 END), 0) AS received_month
    FROM purchases" . $purchaseStoreScope . "
")->fetch();

function purchase_badge(string $status): string
{
    return match ($status) {
        'ordered' => 'ordered',
        'partial' => 'partial',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
        default => 'cancelled',
    };
}

$pageTitle = 'Purchases';
require __DIR__ . '/includes/header.php';
?>

<section class="purchase-hero mb-4">
  <div>
    <span class="purchase-overline"><i class="bi bi-truck me-2"></i>Procurement workspace</span>
    <h2>Order stock. Receive with confidence.</h2>
    <p>Create supplier orders, track expected deliveries, and add received stock to the correct store.</p>
  </div>
  <button class="btn purchase-new-button" data-bs-toggle="modal" data-bs-target="#addPurchaseModal" <?= !$products || !$stores ? 'disabled' : '' ?>><i class="bi bi-plus-lg me-2"></i>New purchase order</button>
</section>

<?php if (!$products || !$stores): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>An active product and store are required before creating a purchase order.</div>
<?php endif; ?>

<section class="purchase-kpi-grid mb-4" aria-label="Purchase summary">
  <div class="purchase-kpi purchase-kpi-total"><span class="purchase-kpi-icon"><i class="bi bi-file-earmark-text"></i></span><div><span>Total orders</span><strong><?= (int) $purchaseMetrics['total_orders'] ?></strong><small>All purchase records</small></div></div>
  <div class="purchase-kpi purchase-kpi-open"><span class="purchase-kpi-icon"><i class="bi bi-hourglass-split"></i></span><div><span>Open orders</span><strong><?= (int) $purchaseMetrics['open_orders'] ?></strong><small>Awaiting delivery</small></div></div>
  <div class="purchase-kpi purchase-kpi-value"><span class="purchase-kpi-icon"><i class="bi bi-wallet2"></i></span><div><span>Outstanding value</span><strong><?= e(money($purchaseMetrics['outstanding_value'])) ?></strong><small>Value still on order</small></div></div>
  <div class="purchase-kpi purchase-kpi-received"><span class="purchase-kpi-icon"><i class="bi bi-box-arrow-in-down"></i></span><div><span>Received this month</span><strong><?= e(money($purchaseMetrics['received_month'])) ?></strong><small><?= e(date('F Y')) ?></small></div></div>
</section>

<section class="purchase-panel">
  <header class="purchase-panel-header">
    <div><span class="purchase-section-label">Purchase register</span><h3>Supplier orders</h3></div>
    <span class="purchase-result-count"><?= count($purchases) ?> result<?= count($purchases) === 1 ? '' : 's' ?></span>
  </header>

  <form class="purchase-filters" method="GET" action="purchases.php" role="search">
    <div class="purchase-search"><i class="bi bi-search"></i><input type="search" class="form-control" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Search PO, product, or supplier"></div>
    <select name="store_id" class="form-select"><option value="0">All stores</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select>
    <select name="status" class="form-select"><option value="all">All statuses</option><option value="ordered" <?= $statusFilter === 'ordered' ? 'selected' : '' ?>>Ordered</option><option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option><option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select>
    <button class="btn purchase-filter-button" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
    <?php if ($search !== '' || $storeFilter > 0 || $statusFilter !== 'all'): ?><a href="purchases.php" class="btn purchase-clear-button" title="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
  </form>

  <div class="table-responsive purchase-table-wrap">
    <table class="table purchase-table align-middle mb-0">
      <thead><tr><th>Purchase order</th><th>Product</th><th>Destination</th><th>Supplier</th><th>Order value</th><th>Delivery</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$purchases): ?>
        <tr><td colspan="8"><div class="purchase-empty"><i class="bi bi-truck"></i><strong>No purchase orders found</strong><span>Create a new order or change your filters.</span></div></td></tr>
        <?php else: foreach ($purchases as $purchase): ?>
        <tr>
          <td><div class="purchase-reference"><span><i class="bi bi-file-earmark-text"></i></span><div><strong><?= e($purchase['purchase_order_number'] ?: 'PO-' . $purchase['id']) ?></strong><small><?= e(date('M j, Y', strtotime($purchase['purchase_date']))) ?></small></div></div></td>
          <td><div class="purchase-product"><strong><?= e($purchase['product_name']) ?></strong><small><?= e($purchase['sku']) ?> · <?= (int) $purchase['quantity'] ?> pieces</small></div></td>
          <td><span class="purchase-store"><i class="bi bi-shop"></i><?= e($purchase['store_name']) ?></span></td>
          <td><?= e($purchase['supplier_name']) ?></td>
          <td><div class="purchase-value"><strong><?= e(money($purchase['total_cost'])) ?></strong><small><?= e(money($purchase['unit_cost'])) ?> each</small></div></td>
          <td><div class="purchase-date"><strong><?= $purchase['expected_delivery_date'] ? e(date('M j, Y', strtotime($purchase['expected_delivery_date']))) : 'Not scheduled' ?></strong><small><?= $purchase['actual_delivery_date'] ? 'Received ' . e(date('M j, Y', strtotime($purchase['actual_delivery_date']))) : 'Expected date' ?></small></div></td>
          <td><span class="purchase-status <?= purchase_badge($purchase['status']) ?>"><i></i><?= e(ucfirst($purchase['status'])) ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ($purchase['status'] === 'ordered' && $canReceive): ?>
            <form method="POST" action="purchases.php" class="d-inline" data-confirm="Receive this order and add <?= (int) $purchase['quantity'] ?> pieces to inventory?">
              <?= csrf_field() ?><input type="hidden" name="mark_delivered" value="1"><input type="hidden" name="id" value="<?= (int) $purchase['id'] ?>">
              <button class="btn purchase-receive-button" title="Mark delivered"><i class="bi bi-box-arrow-in-down"></i><span>Receive</span></button>
            </form>
            <?php endif; ?>
            <?php if ($purchase['status'] === 'ordered' && $canCancel): ?>
            <form method="POST" action="purchases.php" class="d-inline" data-confirm="Cancel this purchase order?">
              <?= csrf_field() ?><input type="hidden" name="cancel_purchase" value="1"><input type="hidden" name="id" value="<?= (int) $purchase['id'] ?>">
              <button class="btn purchase-cancel-button" title="Cancel order"><i class="bi bi-x-lg"></i></button>
            </form>
            <?php endif; ?>
            <?php if ($purchase['status'] !== 'ordered' || (!$canReceive && !$canCancel)): ?><span class="purchase-no-action">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="modal fade" id="addPurchaseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content purchase-modal">
      <form method="POST" action="purchases.php" id="addPurchaseForm">
        <?= csrf_field() ?><input type="hidden" name="add_purchase" value="1">
        <div class="modal-header purchase-modal-header">
          <div><span class="purchase-section-label">New supplier order</span><h5 class="modal-title">Create purchase order</h5><small>Stock is added only after this order is received.</small></div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="purchase-order-note mb-4"><i class="bi bi-info-circle"></i><span><strong>Order first, receive later</strong><small>Creating this order will not change inventory quantities.</small></span><div><span>Estimated total</span><strong id="purchaseEstimatedTotal"><?= e(money(0)) ?></strong></div></div>
          <div class="row g-3">
            <div class="col-12 position-relative">
              <label class="form-label">Product</label>
              <div class="purchase-product-search"><i class="bi bi-search"></i><input type="text" class="form-control" id="purchaseProductSearch" placeholder="Search product name or SKU" autocomplete="off" value="<?= e($preselectProductLabel) ?>" required></div>
              <input type="hidden" name="product_id" id="purchaseProductId" value="<?= $preselectProduct ?: '' ?>">
              <div id="purchaseProductResults" class="purchase-search-results"></div>
            </div>
            <div class="col-md-6"><label class="form-label">Deliver to store</label><select class="form-select" name="store_id" required><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Supplier</label><input type="text" class="form-control" name="supplier_name" placeholder="Supplier or distributor" required></div>
            <div class="col-md-6"><label class="form-label">PO number <span class="text-muted fw-normal">(optional)</span></label><input type="text" class="form-control" name="purchase_order_number" placeholder="Generated automatically if blank"></div>
            <div class="col-md-6"><label class="form-label">Quantity (pieces)</label><input type="number" class="form-control" id="purchaseQuantity" name="quantity" min="1" required></div>
            <div class="col-md-6"><label class="form-label">Cost per piece (<?= e(CURRENCY_SYMBOL) ?>)</label><input type="number" class="form-control" id="purchaseUnitCost" name="unit_cost" step="0.01" min="0.01" required></div>
            <div class="col-md-6"><label class="form-label">Purchase date</label><input type="date" class="form-control" name="purchase_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Expected delivery <span class="text-muted fw-normal">(optional)</span></label><input type="date" class="form-control" name="expected_delivery_date"></div>
            <div class="col-12"><label class="form-label">Notes <span class="text-muted fw-normal">(optional)</span></label><textarea class="form-control" name="notes" rows="3" placeholder="Delivery instructions or additional details"></textarea></div>
          </div>
        </div>
        <div class="modal-footer border-0 px-4 pb-4"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn purchase-create-button"><i class="bi bi-check2 me-1"></i>Create order</button></div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script>
  const purchaseProducts = ' . json_encode(array_map(static function ($product) {
        return ['id' => (int) $product['id'], 'name' => $product['name'], 'sku' => $product['sku']];
    }, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
  const currencySymbol = ' . json_encode(CURRENCY_SYMBOL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
  const purchaseProductSearch = document.getElementById("purchaseProductSearch");
  const purchaseProductId = document.getElementById("purchaseProductId");
  const purchaseProductResults = document.getElementById("purchaseProductResults");
  const purchaseQuantity = document.getElementById("purchaseQuantity");
  const purchaseUnitCost = document.getElementById("purchaseUnitCost");
  const purchaseEstimatedTotal = document.getElementById("purchaseEstimatedTotal");

  function escapePurchaseHtml(value) {
    const element = document.createElement("div");
    element.textContent = String(value);
    return element.innerHTML;
  }

  function updatePurchaseTotal() {
    const quantity = parseInt(purchaseQuantity.value, 10) || 0;
    const unitCost = parseFloat(purchaseUnitCost.value) || 0;
    purchaseEstimatedTotal.textContent = currencySymbol + (quantity * unitCost).toFixed(2);
  }

  function renderPurchaseResults(query) {
    const normalized = query.trim().toLowerCase();
    if (!normalized) { purchaseProductResults.style.display = "none"; purchaseProductResults.innerHTML = ""; return; }
    const matches = purchaseProducts.filter(function (product) {
      return product.name.toLowerCase().includes(normalized) || product.sku.toLowerCase().includes(normalized);
    }).slice(0, 8);
    if (!matches.length) {
      purchaseProductResults.innerHTML = "<div class=\"purchase-search-empty\">No matching products</div>";
      purchaseProductResults.style.display = "block";
      return;
    }
    purchaseProductResults.innerHTML = matches.map(function (product) {
      return "<button type=\"button\" class=\"purchase-search-result\" data-id=\"" + product.id + "\"><span><strong>" + escapePurchaseHtml(product.name) + "</strong><small>" + escapePurchaseHtml(product.sku) + "</small></span><i class=\"bi bi-plus-circle\"></i></button>";
    }).join("");
    purchaseProductResults.style.display = "block";
    purchaseProductResults.querySelectorAll(".purchase-search-result").forEach(function (button) {
      button.addEventListener("click", function () {
        const product = purchaseProducts.find(function (item) { return String(item.id) === button.getAttribute("data-id"); });
        if (product) {
          purchaseProductSearch.value = product.name + " (" + product.sku + ")";
          purchaseProductId.value = product.id;
        }
        purchaseProductResults.style.display = "none";
        purchaseProductResults.innerHTML = "";
      });
    });
  }

  purchaseQuantity.addEventListener("input", updatePurchaseTotal);
  purchaseUnitCost.addEventListener("input", updatePurchaseTotal);

  if (purchaseProductSearch) {
    purchaseProductSearch.addEventListener("input", function () { purchaseProductId.value = ""; renderPurchaseResults(this.value); });
    purchaseProductSearch.addEventListener("focus", function () { this.select(); });
    purchaseProductSearch.addEventListener("keydown", function (event) {
      if (event.key === "Enter") { event.preventDefault(); const first = purchaseProductResults.querySelector(".purchase-search-result"); if (first) first.click(); }
    });
    document.addEventListener("click", function (event) {
      if (!purchaseProductSearch.contains(event.target) && !purchaseProductResults.contains(event.target)) purchaseProductResults.style.display = "none";
    });
  }

  const addPurchaseForm = document.getElementById("addPurchaseForm");
  if (addPurchaseForm) {
    addPurchaseForm.addEventListener("submit", function (event) {
      if (!purchaseProductId.value) { event.preventDefault(); alert("Please search for and select a product from the list."); }
    });
  }';

if ($preselectProduct > 0 && $preselectProductLabel !== '') {
    $extraScripts .= 'document.addEventListener("DOMContentLoaded", function () { new bootstrap.Modal(document.getElementById("addPurchaseModal")).show(); });';
}
$extraScripts .= '</script>';

require __DIR__ . '/includes/footer.php';
?>
