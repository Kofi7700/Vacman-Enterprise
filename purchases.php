<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager', 'warehouse');

$pdo = db();
$canReceive = is_role('admin', 'warehouse');

// ---- Handle New Purchase Order -----------------------------------------
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

    if (empty($errors)) {
        try {
            $quantity = (int) $_POST['quantity'];
            $unitCost = (float) $_POST['unit_cost'];
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
            $id = (int) $pdo->lastInsertId();
            log_audit('create', 'purchase', $id, null, $_POST);
            flash_set('success', 'Purchase order created.');
        } catch (PDOException $e) {
            error_log('Create purchase failed: ' . $e->getMessage());
            flash_set('danger', 'Could not create the purchase order.');
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('purchases.php');
}

// ---- Handle Mark Delivered (adds real stock) -----------------------
if (is_post() && isset($_POST['mark_delivered'])) {
    require_role('admin', 'warehouse');
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ?');
    $stmt->execute([$id]);
    $purchase = $stmt->fetch();

    if (!$purchase) {
        flash_set('danger', 'Purchase order not found.');
        redirect('purchases.php');
    }
    if ($purchase['status'] === 'delivered') {
        flash_set('warning', 'That order is already marked delivered.');
        redirect('purchases.php');
    }

    try {
        $pdo->beginTransaction();

        $inv = $pdo->prepare('SELECT id FROM inventory WHERE product_id = ? AND store_id = ? FOR UPDATE');
        $inv->execute([$purchase['product_id'], $purchase['store_id']]);
        $invRow = $inv->fetch();

        if ($invRow) {
            $pdo->prepare('UPDATE inventory SET quantity = quantity + ?, last_received_date = CURDATE() WHERE id = ?')
                ->execute([$purchase['quantity'], $invRow['id']]);
        } else {
            $pdo->prepare('INSERT INTO inventory (product_id, store_id, quantity, last_received_date) VALUES (?, ?, ?, CURDATE())')
                ->execute([$purchase['product_id'], $purchase['store_id'], $purchase['quantity']]);
        }

        $pdo->prepare(
            'UPDATE purchases SET status = "delivered", received_quantity = quantity, actual_delivery_date = CURDATE(), received_by = ? WHERE id = ?'
        )->execute([current_user()['id'], $id]);

        $pdo->commit();
        log_audit('status_change', 'purchase', $id, ['status' => $purchase['status']], ['status' => 'delivered']);
        flash_set('success', 'Stock received and added to inventory.');
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Mark delivered failed: ' . $e->getMessage());
        flash_set('danger', 'Could not mark the order as delivered.');
    }
    redirect('purchases.php');
}

// ---- Handle Cancel ---------------------------------------------------
if (is_post() && isset($_POST['cancel_purchase'])) {
    require_role('admin', 'manager');
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE purchases SET status = 'cancelled' WHERE id = ? AND status != 'delivered'")->execute([$id]);
    log_audit('status_change', 'purchase', $id, null, ['status' => 'cancelled']);
    flash_set('success', 'Purchase order cancelled.');
    redirect('purchases.php');
}

// ---- Data -----------------------------------------------------------
$products = $pdo->query('SELECT id, name, sku, supplier_name FROM products ORDER BY name')->fetchAll();
$stores = $pdo->query('SELECT id, name FROM stores WHERE status = "active" ORDER BY name')->fetchAll();
$preselectProduct = (int) ($_GET['product_id'] ?? 0);
$preselectProductLabel = '';
if ($preselectProduct > 0) {
    foreach ($products as $p) {
        if ((int) $p['id'] === $preselectProduct) {
            $preselectProductLabel = $p['name'] . ' (' . $p['sku'] . ')';
            break;
        }
    }
}

$purchases = $pdo->query(
    "SELECT pu.*, p.name AS product_name, s.name AS store_name
     FROM purchases pu
     JOIN products p ON p.id = pu.product_id
     JOIN stores s ON s.id = pu.store_id
     ORDER BY pu.created_at DESC
     LIMIT 100"
)->fetchAll();

function purchase_badge(string $status): string
{
    return match ($status) {
        'ordered' => 'warning',
        'partial' => 'info',
        'delivered' => 'success',
        'cancelled' => 'secondary',
        default => 'secondary',
    };
}

$pageTitle = 'Purchases';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addPurchaseModal">
    <i class="bi bi-plus-lg me-1"></i> New Purchase Order
  </button>
</div>

<div class="card shadow mb-4">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr><th>PO Number</th><th>Product</th><th>Store</th><th>Supplier</th><th>Qty</th><th>Unit Cost</th><th>Total</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($purchases)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No purchase orders yet.</td></tr>
          <?php else: foreach ($purchases as $p): ?>
          <tr>
            <td><?= e($p['purchase_order_number']) ?></td>
            <td><?= e($p['product_name']) ?></td>
            <td><?= e($p['store_name']) ?></td>
            <td><?= e($p['supplier_name']) ?></td>
            <td><?= (int) $p['quantity'] ?></td>
            <td><?= e(money($p['unit_cost'])) ?></td>
            <td class="fw-semibold"><?= e(money($p['total_cost'])) ?></td>
            <td><span class="badge bg-<?= purchase_badge($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
            <td class="text-nowrap">
              <?php if ($p['status'] === 'ordered' && $canReceive): ?>
              <form method="POST" action="purchases.php" class="d-inline" data-confirm="Mark this order delivered and add <?= (int) $p['quantity'] ?> units to inventory?">
                <?= csrf_field() ?><input type="hidden" name="mark_delivered" value="1"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-outline-success">Mark Delivered</button>
              </form>
              <form method="POST" action="purchases.php" class="d-inline" data-confirm="Cancel this purchase order?">
                <?= csrf_field() ?><input type="hidden" name="cancel_purchase" value="1"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Cancel</button>
              </form>
              <?php else: ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="addPurchaseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="purchases.php" id="addPurchaseForm">
        <?= csrf_field() ?>
        <input type="hidden" name="add_purchase" value="1">
        <div class="modal-header">
          <h5 class="modal-title">New Purchase Order</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3 position-relative">
            <label class="form-label">Product</label>
            <input type="text" class="form-control" id="purchaseProductSearch" placeholder="Type a product name or SKU..." autocomplete="off" value="<?= e($preselectProductLabel) ?>" required>
            <input type="hidden" name="product_id" id="purchaseProductId" value="<?= $preselectProduct ?: '' ?>">
            <div id="purchaseProductResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:1060; max-height:240px; overflow-y:auto; display:none;"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Deliver To Store</label>
            <select class="form-select" name="store_id" required>
              <?php foreach ($stores as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Supplier</label>
            <input type="text" class="form-control" name="supplier_name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">PO Number (optional, auto-generated if blank)</label>
            <input type="text" class="form-control" name="purchase_order_number">
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Quantity</label>
              <input type="number" class="form-control" name="quantity" min="1" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Unit Cost (<?= e(CURRENCY_SYMBOL) ?>)</label>
              <input type="number" step="0.01" min="0" class="form-control" name="unit_cost" required>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Purchase Date</label>
              <input type="date" class="form-control" name="purchase_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Expected Delivery (optional)</label>
              <input type="date" class="form-control" name="expected_delivery_date">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes (optional)</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Create Order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script>
  const purchaseProducts = ' . json_encode(array_map(function ($p) {
        return ['id' => (int) $p['id'], 'name' => $p['name'], 'sku' => $p['sku']];
    }, $products)) . ';
  const purchaseProductSearch = document.getElementById("purchaseProductSearch");
  const purchaseProductId = document.getElementById("purchaseProductId");
  const purchaseProductResults = document.getElementById("purchaseProductResults");

  function renderPurchaseResults(query) {
    const q = query.trim().toLowerCase();
    if (!q) { purchaseProductResults.style.display = "none"; purchaseProductResults.innerHTML = ""; return; }
    const matches = purchaseProducts.filter(function (p) {
      return p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q);
    }).slice(0, 8);
    if (!matches.length) {
      purchaseProductResults.innerHTML = "<div class=\"list-group-item text-muted small\">No matching products</div>";
      purchaseProductResults.style.display = "block";
      return;
    }
    purchaseProductResults.innerHTML = matches.map(function (p) {
      return "<button type=\"button\" class=\"list-group-item list-group-item-action py-2 purchase-search-result\" data-id=\"" + p.id + "\" data-label=\"" + p.name + " (" + p.sku + ")\">" + p.name + " <span class=\"text-muted small\">(" + p.sku + ")</span></button>";
    }).join("");
    purchaseProductResults.style.display = "block";
    purchaseProductResults.querySelectorAll(".purchase-search-result").forEach(function (btn) {
      btn.addEventListener("click", function () {
        purchaseProductSearch.value = btn.getAttribute("data-label");
        purchaseProductId.value = btn.getAttribute("data-id");
        purchaseProductResults.style.display = "none";
        purchaseProductResults.innerHTML = "";
      });
    });
  }

  if (purchaseProductSearch) {
    purchaseProductSearch.addEventListener("input", function () {
      purchaseProductId.value = "";
      renderPurchaseResults(this.value);
    });
    purchaseProductSearch.addEventListener("focus", function () { this.select(); });
    purchaseProductSearch.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        const first = purchaseProductResults.querySelector(".purchase-search-result");
        if (first) first.click();
      }
    });
    document.addEventListener("click", function (e) {
      if (!purchaseProductSearch.contains(e.target) && !purchaseProductResults.contains(e.target)) {
        purchaseProductResults.style.display = "none";
      }
    });
  }

  const addPurchaseForm = document.getElementById("addPurchaseForm");
  if (addPurchaseForm) {
    addPurchaseForm.addEventListener("submit", function (e) {
      if (!purchaseProductId.value) {
        e.preventDefault();
        alert("Please search for and select a product from the list.");
      }
    });
  }';

if ($preselectProduct > 0) {
    $extraScripts .= 'document.addEventListener("DOMContentLoaded",function(){new bootstrap.Modal(document.getElementById("addPurchaseModal")).show();});';
}
$extraScripts .= '</script>';
require __DIR__ . '/includes/footer.php';
?>
