<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canSell = is_role('admin', 'manager', 'sales_clerk');

// ---- Handle Record Sale (one or more products in a single checkout) --
if (is_post() && isset($_POST['add_sale'])) {
    require_role('admin', 'manager', 'sales_clerk');
    verify_csrf();

    $storeId = (int) ($_POST['store_id'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? '';
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $rawProductIds = $_POST['product_id'] ?? [];
    $rawQuantities = $_POST['quantity'] ?? [];

    $errors = validate($_POST, [
        'store_id' => 'required|int',
        'payment_method' => 'required',
    ]);
    if (!in_array($paymentMethod, ['cash', 'mobile_money', 'card', 'credit'], true)) {
        $errors[] = 'Invalid payment method.';
    }
    if (!is_array($rawProductIds) || !is_array($rawQuantities)) {
        $errors[] = 'Invalid product list.';
    }

    // Collapse the submitted rows into product_id => quantity, ignoring
    // blank rows and merging duplicate product rows together.
    $lines = [];
    if (empty($errors)) {
        $rowCount = max(count($rawProductIds), count($rawQuantities));
        for ($i = 0; $i < $rowCount; $i++) {
            $pid = (int) ($rawProductIds[$i] ?? 0);
            $qty = (int) ($rawQuantities[$i] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue; // blank/incomplete row from the form, skip it
            }
            $lines[$pid] = ($lines[$pid] ?? 0) + $qty;
        }
        if (empty($lines)) {
            $errors[] = 'Add at least one product to the sale.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock inventory rows in a consistent order (by product_id) so
            // two simultaneous multi-item sales can't deadlock each other.
            ksort($lines);

            $lockStmt = $pdo->prepare(
                'SELECT i.id, i.quantity, p.unit_price, p.name
                 FROM inventory i JOIN products p ON p.id = i.product_id
                 WHERE i.product_id = ? AND i.store_id = ? FOR UPDATE'
            );

            $items = [];
            $total = 0.0;
            foreach ($lines as $pid => $qty) {
                $lockStmt->execute([$pid, $storeId]);
                $inv = $lockStmt->fetch();

                if (!$inv) {
                    throw new RuntimeException('One of the selected products is not stocked at the selected store.');
                }
                if ((int) $inv['quantity'] < $qty) {
                    throw new RuntimeException('Not enough stock: only ' . (int) $inv['quantity'] . ' of "' . $inv['name'] . '" available.');
                }

                $unitPrice = (float) $inv['unit_price'];
                $subtotal = $unitPrice * $qty;
                $total += $subtotal;
                $items[] = [
                    'product_id' => $pid,
                    'inventory_id' => (int) $inv['id'],
                    'name' => $inv['name'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ];
            }

            $transactionId = 'TXN-' . date('Ymd-His') . '-' . random_int(100, 999);

            $insertSale = $pdo->prepare(
                'INSERT INTO sales (store_id, user_id, customer_name, customer_phone, total_amount, payment_method, transaction_id)
                 VALUES (:store_id, :user_id, :customer_name, :customer_phone, :total_amount, :payment_method, :transaction_id)'
            );
            $insertSale->execute([
                ':store_id' => $storeId,
                ':user_id' => current_user()['id'],
                ':customer_name' => $customerName !== '' ? $customerName : null,
                ':customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                ':total_amount' => $total,
                ':payment_method' => $paymentMethod,
                ':transaction_id' => $transactionId,
            ]);
            $saleId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal)
                 VALUES (:sale_id, :product_id, :quantity, :unit_price, :subtotal)'
            );
            $updateInv = $pdo->prepare('UPDATE inventory SET quantity = quantity - ?, last_sold_date = CURDATE() WHERE id = ?');

            foreach ($items as $item) {
                $insertItem->execute([
                    ':sale_id' => $saleId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['unit_price'],
                    ':subtotal' => $item['subtotal'],
                ]);
                $updateInv->execute([$item['quantity'], $item['inventory_id']]);
            }

            $pdo->commit();
            $itemCount = count($items);
            log_audit('create', 'sale', $saleId, null, ['store_id' => $storeId, 'items' => $itemCount, 'total' => $total]);
            $label = $itemCount === 1 ? "{$items[0]['quantity']} x {$items[0]['name']}" : "{$itemCount} products";
            flash_set('success', "Sale recorded: {$label} for " . money($total) . '.');
            redirect('receipt.php?id=' . $saleId);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            flash_set('danger', $e->getMessage());
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Record sale failed: ' . $e->getMessage());
            flash_set('danger', 'Could not record the sale.');
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('sales.php');
}

// ---- Data for the page --------------------------------------------
$stores = $pdo->query('SELECT id, name FROM stores WHERE status = "active" ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT id, name, sku, unit_price FROM products WHERE status = "active" ORDER BY name')->fetchAll();

$storeFilter = (int) ($_GET['store_id'] ?? 0);
$sql = "SELECT s.id, s.total_amount, s.payment_method, s.customer_name, s.sale_date,
               st.name AS store_name, u.full_name AS sold_by,
               COUNT(si.id) AS item_count, COALESCE(SUM(si.quantity), 0) AS total_qty,
               GROUP_CONCAT(CONCAT(si.quantity, 'x ', p.name) ORDER BY p.name SEPARATOR ', ') AS items_summary
        FROM sales s
        JOIN stores st ON st.id = s.store_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN products p ON p.id = si.product_id";
$params = [];
if ($storeFilter > 0) {
    $sql .= ' WHERE s.store_id = :store_id';
    $params[':store_id'] = $storeFilter;
}
$sql .= ' GROUP BY s.id ORDER BY s.sale_date DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$todayTotal = (float) $pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) = CURDATE()')->fetchColumn();
$todayCount = (int) $pdo->query('SELECT COUNT(*) FROM sales WHERE DATE(sale_date) = CURDATE()')->fetchColumn();

// Last 7 days totals for the chart.
$last7 = $pdo->query("
    SELECT DATE(sale_date) AS d, SUM(total_amount) AS total
    FROM sales
    WHERE sale_date >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(sale_date)
")->fetchAll(PDO::FETCH_KEY_PAIR);
$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $chartLabels[] = date('D', strtotime($d));
    $chartValues[] = isset($last7[$d]) ? (float) $last7[$d] : 0;
}

$pageTitle = 'Sales Management';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card card-dashboard border-left-success shadow h-100 py-2">
      <div class="card-body">
        <div class="text-xs fw-bold text-success text-uppercase mb-1">Today's Sales</div>
        <div class="h5 mb-0 fw-bold"><?= e(money($todayTotal)) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card card-dashboard border-left-primary shadow h-100 py-2">
      <div class="card-body">
        <div class="text-xs fw-bold text-primary text-uppercase mb-1">Transactions Today</div>
        <div class="h5 mb-0 fw-bold"><?= $todayCount ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4 d-flex align-items-center justify-content-end">
    <?php if ($canSell): ?>
    <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addSaleModal">
      <i class="bi bi-plus-lg me-1"></i> Record New Sale
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow mb-4">
  <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary">Sales - Last 7 Days</h6></div>
  <div class="card-body"><canvas id="salesChart" height="70"></canvas></div>
</div>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <h6 class="m-0 fw-bold text-primary">Recent Sales</h6>
    <form method="GET" action="sales.php" class="d-flex align-items-center">
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
      <table class="table table-hover">
        <thead>
          <tr><th>Date</th><th>Items</th><th>Store</th><th>Qty</th><th>Total</th><th>Payment</th><th>Customer</th><th>Sold By</th><th>Receipt</th></tr>
        </thead>
        <tbody>
          <?php if (empty($sales)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No sales recorded yet.</td></tr>
          <?php else: foreach ($sales as $sale): ?>
          <tr>
            <td><?= e(date('M j, Y g:i A', strtotime($sale['sale_date']))) ?></td>
            <td>
              <?= e($sale['items_summary'] ?: '—') ?>
              <?php if ((int) $sale['item_count'] > 1): ?>
              <span class="badge bg-light text-dark border ms-1"><?= (int) $sale['item_count'] ?> products</span>
              <?php endif; ?>
            </td>
            <td><?= e($sale['store_name']) ?></td>
            <td><?= (int) $sale['total_qty'] ?></td>
            <td class="fw-semibold"><?= e(money($sale['total_amount'])) ?></td>
            <td><span class="badge bg-secondary"><?= e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></span></td>
            <td><?= e($sale['customer_name'] ?: 'Walk-in') ?></td>
            <td><?= e($sale['sold_by']) ?></td>
            <td>
              <a href="receipt.php?id=<?= (int) $sale['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="View / print receipt">
                <i class="bi bi-receipt"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($canSell): ?>
<div class="modal fade" id="addSaleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="sales.php" id="saleForm">
        <?= csrf_field() ?>
        <input type="hidden" name="add_sale" value="1">
        <div class="modal-header">
          <h5 class="modal-title">Record New Sale</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Store</label>
            <select class="form-select" name="store_id" required>
              <?php foreach ($stores as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3 position-relative">
            <label class="form-label">Search &amp; Add Product</label>
            <input type="text" class="form-control" id="saleProductSearch" placeholder="Type a product name or SKU..." autocomplete="off">
            <div id="saleProductResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:1060; max-height:240px; overflow-y:auto; display:none;"></div>
          </div>

          <table class="table table-sm align-middle" id="saleItemsTable">
            <thead>
              <tr>
                <th style="width:45%">Product</th>
                <th style="width:15%">Qty</th>
                <th style="width:20%">Line Total</th>
                <th style="width:5%"></th>
              </tr>
            </thead>
            <tbody id="saleItemsBody"></tbody>
          </table>
          <div id="saleItemsEmpty" class="text-muted small mb-3">No products added yet. Search above to add one.</div>

          <div class="d-flex justify-content-end mb-3">
            <div class="fw-bold">Total: <span id="saleGrandTotal"><?= e(CURRENCY_SYMBOL) ?>0.00</span></div>
          </div>

          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <select class="form-select" name="payment_method" required>
              <option value="cash">Cash</option>
              <option value="mobile_money">Mobile Money</option>
              <option value="card">Card</option>
              <option value="credit">Credit</option>
            </select>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Customer Name (optional)</label>
              <input type="text" class="form-control" name="customer_name">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Customer Phone (optional)</label>
              <input type="text" class="form-control" name="customer_phone">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Record Sale</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$extraScripts = '<script>
  const saleProducts = ' . json_encode(array_map(function ($p) {
        return ['id' => (int) $p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'price' => (float) $p['unit_price']];
    }, $products)) . ';
  const currencySymbol = ' . json_encode(CURRENCY_SYMBOL) . ';
  const saleItemsBody = document.getElementById("saleItemsBody");
  const saleItemsEmpty = document.getElementById("saleItemsEmpty");
  const saleGrandTotal = document.getElementById("saleGrandTotal");
  const saleProductSearch = document.getElementById("saleProductSearch");
  const saleProductResults = document.getElementById("saleProductResults");
  const saleForm = document.getElementById("saleForm");

  function rowSubtotal(row) {
    const price = parseFloat(row.getAttribute("data-price")) || 0;
    const qtyInput = row.querySelector("input[name=\"quantity[]\"]");
    const qty = parseInt(qtyInput.value, 10) || 0;
    const subtotal = price * qty;
    row.querySelector(".sale-line-total").textContent = currencySymbol + subtotal.toFixed(2);
    return subtotal;
  }

  function recalcGrandTotal() {
    let total = 0;
    saleItemsBody.querySelectorAll("tr").forEach(function (row) { total += rowSubtotal(row); });
    saleGrandTotal.textContent = currencySymbol + total.toFixed(2);
    saleItemsEmpty.style.display = saleItemsBody.querySelectorAll("tr").length ? "none" : "block";
  }

  function addProductToSale(product) {
    // Already on the list? Just bump the quantity instead of a duplicate row.
    const existing = saleItemsBody.querySelector("tr[data-product-id=\"" + product.id + "\"]");
    if (existing) {
      const qtyInput = existing.querySelector("input[name=\"quantity[]\"]");
      qtyInput.value = (parseInt(qtyInput.value, 10) || 0) + 1;
      recalcGrandTotal();
      return;
    }

    const row = document.createElement("tr");
    row.setAttribute("data-product-id", product.id);
    row.setAttribute("data-price", product.price);
    row.innerHTML =
      "<td>" + product.name + " <span class=\"text-muted small\">(" + product.sku + ")</span>" +
        "<input type=\"hidden\" name=\"product_id[]\" value=\"" + product.id + "\"></td>" +
      "<td><input type=\"number\" class=\"form-control form-control-sm\" name=\"quantity[]\" min=\"1\" value=\"1\" required></td>" +
      "<td class=\"sale-line-total\">" + currencySymbol + "0.00</td>" +
      "<td><button type=\"button\" class=\"btn btn-sm btn-outline-danger remove-sale-item\" title=\"Remove\"><i class=\"bi bi-x-lg\"></i></button></td>";
    saleItemsBody.appendChild(row);
    row.querySelector("input[name=\"quantity[]\"]").addEventListener("input", recalcGrandTotal);
    row.querySelector(".remove-sale-item").addEventListener("click", function () {
      row.remove();
      recalcGrandTotal();
    });
    recalcGrandTotal();
  }

  function renderSearchResults(query) {
    const q = query.trim().toLowerCase();
    if (!q) {
      saleProductResults.style.display = "none";
      saleProductResults.innerHTML = "";
      return;
    }
    const matches = saleProducts.filter(function (p) {
      return p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q);
    }).slice(0, 8);

    if (!matches.length) {
      saleProductResults.innerHTML = "<div class=\"list-group-item text-muted small\">No matching products</div>";
      saleProductResults.style.display = "block";
      return;
    }
    saleProductResults.innerHTML = matches.map(function (p) {
      return "<button type=\"button\" class=\"list-group-item list-group-item-action py-2 sale-search-result\" data-id=\"" + p.id + "\">" +
        "<div class=\"d-flex justify-content-between\"><span>" + p.name + "</span>" +
        "<span class=\"text-muted small\">" + currencySymbol + p.price.toFixed(2) + "</span></div>" +
        "<div class=\"text-muted small\">SKU: " + p.sku + "</div></button>";
    }).join("");
    saleProductResults.style.display = "block";
    saleProductResults.querySelectorAll(".sale-search-result").forEach(function (btn) {
      btn.addEventListener("click", function () {
        const product = saleProducts.find(function (p) { return String(p.id) === btn.getAttribute("data-id"); });
        if (product) {
          addProductToSale(product);
        }
        saleProductSearch.value = "";
        saleProductResults.style.display = "none";
        saleProductResults.innerHTML = "";
        saleProductSearch.focus();
      });
    });
  }

  if (saleProductSearch) {
    saleProductSearch.addEventListener("input", function () { renderSearchResults(this.value); });
    saleProductSearch.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        const first = saleProductResults.querySelector(".sale-search-result");
        if (first) first.click();
      }
    });
    document.addEventListener("click", function (e) {
      if (!saleProductSearch.contains(e.target) && !saleProductResults.contains(e.target)) {
        saleProductResults.style.display = "none";
      }
    });
  }

  if (saleForm) {
    saleForm.addEventListener("submit", function (e) {
      if (!saleItemsBody.querySelectorAll("tr").length) {
        e.preventDefault();
        alert("Add at least one product to the sale.");
      }
    });
  }

  const addSaleModal = document.getElementById("addSaleModal");
  if (addSaleModal) {
    addSaleModal.addEventListener("show.bs.modal", function () {
      saleItemsBody.innerHTML = "";
      saleProductSearch.value = "";
      saleProductResults.style.display = "none";
      recalcGrandTotal();
    });
    addSaleModal.addEventListener("shown.bs.modal", function () {
      saleProductSearch.focus();
    });
  }

  const salesCtx = document.getElementById("salesChart").getContext("2d");
  new Chart(salesCtx, {
    type: "line",
    data: {
      labels: ' . json_encode($chartLabels) . ',
      datasets: [{
        label: "Sales (' . e(CURRENCY_CODE) . ')",
        data: ' . json_encode($chartValues) . ',
        borderColor: "#e74a3b",
        backgroundColor: "rgba(231,74,59,0.15)",
        fill: true,
        tension: 0.3
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
</script>';
require __DIR__ . '/includes/footer.php';
