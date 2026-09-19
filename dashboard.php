<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();

// ---- Key metrics --------------------------------------------------
$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

$lowStockItems = $pdo->query("
    SELECT p.id, p.name, c.name AS category, i.quantity, p.reorder_level, s.name AS store_name
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    JOIN stores s ON s.id = i.store_id
    WHERE i.quantity <= p.reorder_level
    ORDER BY i.quantity ASC
    LIMIT 10
")->fetchAll();
$lowStockCount = count($lowStockItems);

$monthlySales = (float) $pdo->query("
    SELECT COALESCE(SUM(total_amount), 0) FROM sales
    WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())
")->fetchColumn();

$pendingTransfers = (int) $pdo->query("SELECT COUNT(*) FROM transfers WHERE status IN ('pending','approved','in_transit')")->fetchColumn();

// ---- Stock by category (for the chart) -----------------------------
$stockByCategory = $pdo->query("
    SELECT c.name AS category, COALESCE(SUM(i.quantity), 0) AS total
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    LEFT JOIN inventory i ON i.product_id = p.id
    GROUP BY c.id, c.name
    ORDER BY c.name
")->fetchAll();

// ---- Top selling products this month --------------------------------
$topSelling = $pdo->query("
    SELECT p.name, c.name AS category, SUM(si.quantity) AS qty_sold
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())
    GROUP BY p.id, p.name, c.name
    ORDER BY qty_sold DESC
    LIMIT 5
")->fetchAll();

// ---- Recent purchases -------------------------------------------------
$recentPurchases = $pdo->query("
    SELECT pu.id, pu.quantity, pu.status, pu.purchase_date, pu.supplier_name, p.name AS product_name
    FROM purchases pu
    JOIN products p ON p.id = pu.product_id
    ORDER BY pu.created_at DESC
    LIMIT 5
")->fetchAll();

// ---- Today's sales ---------------------------------------------------
$todaySales = $pdo->query("
    SELECT s.id, s.total_amount, s.payment_method, s.customer_name,
           GROUP_CONCAT(CONCAT(si.quantity, 'x ', p.name) ORDER BY p.name SEPARATOR ', ') AS items_summary
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN products p ON p.id = si.product_id
    WHERE DATE(s.sale_date) = CURDATE()
    GROUP BY s.id
    ORDER BY s.sale_date DESC
    LIMIT 8
")->fetchAll();

function category_css_class(string $category): string
{
    $c = strtolower($category);
    if (str_contains($c, 'building')) return 'building';
    if (str_contains($c, 'electrical')) return 'electrical';
    return 'appliance';
}

$pageTitle = 'Inventory Dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-end mb-3">
  <a href="reports.php" class="btn btn-danger btn-sm shadow-sm">
    <i class="bi bi-file-earmark-pdf me-1"></i> Generate Report
  </a>
</div>

<!-- Key Metrics -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card card-dashboard border-left-danger shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col me-2">
            <div class="text-xs fw-bold text-danger text-uppercase mb-1">Low Stock Items</div>
            <div class="h5 mb-0 fw-bold text-gray-800"><?= $lowStockCount ?></div>
          </div>
          <div class="col-auto"><i class="bi bi-exclamation-triangle fs-2 text-secondary opacity-25"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-dashboard border-left-primary shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col me-2">
            <div class="text-xs fw-bold text-primary text-uppercase mb-1">Total Products</div>
            <div class="h5 mb-0 fw-bold text-gray-800"><?= $totalProducts ?></div>
          </div>
          <div class="col-auto"><i class="bi bi-box-seam fs-2 text-secondary opacity-25"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-dashboard border-left-success shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col me-2">
            <div class="text-xs fw-bold text-success text-uppercase mb-1">Monthly Sales</div>
            <div class="h5 mb-0 fw-bold text-gray-800"><?= e(money($monthlySales)) ?></div>
          </div>
          <div class="col-auto"><i class="bi bi-graph-up fs-2 text-secondary opacity-25"></i></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-dashboard border-left-info shadow h-100 py-2">
      <div class="card-body">
        <div class="row no-gutters align-items-center">
          <div class="col me-2">
            <div class="text-xs fw-bold text-info text-uppercase mb-1">Pending Transfers</div>
            <div class="h5 mb-0 fw-bold text-gray-800"><?= $pendingTransfers ?></div>
          </div>
          <div class="col-auto"><i class="bi bi-arrow-left-right fs-2 text-secondary opacity-25"></i></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary">Stock by Category</h6></div>
      <div class="card-body"><canvas id="stockChart" height="200"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary">Top Selling This Month</h6></div>
      <div class="card-body">
        <?php if (empty($topSelling)): ?>
          <p class="text-muted mb-0">No sales recorded this month yet.</p>
        <?php else: ?>
        <ol class="list-group list-group-numbered">
          <?php foreach ($topSelling as $item): ?>
          <li class="list-group-item d-flex justify-content-between align-items-start">
            <div class="ms-2 me-auto">
              <div class="fw-bold"><?= e($item['name']) ?></div>
              <small class="text-muted"><?= e($item['category']) ?></small>
            </div>
            <span class="badge bg-danger rounded-pill"><?= (int) $item['qty_sold'] ?> sold</span>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Low Stock Alerts -->
<div class="row">
  <div class="col-lg-12">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-warning">Low Stock Alerts</h6></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr><th>Product</th><th>Category</th><th>Store</th><th>Current Stock</th><th>Reorder Level</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php if (empty($lowStockItems)): ?>
              <tr><td colspan="6" class="text-center text-muted">No low stock items right now.</td></tr>
              <?php else: foreach ($lowStockItems as $item): ?>
              <tr class="table-warning">
                <td><?= e($item['name']) ?></td>
                <td><span class="product-category <?= category_css_class($item['category']) ?>"><?= e($item['category']) ?></span></td>
                <td><?= e($item['store_name']) ?></td>
                <td><?= (int) $item['quantity'] ?></td>
                <td><?= (int) $item['reorder_level'] ?></td>
                <td><a href="purchases.php?product_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-danger">Reorder</a></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Recent Activity -->
<div class="row">
  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-info">Recent Purchases</h6></div>
      <div class="card-body">
        <ul class="list-group list-group-flush">
          <?php if (empty($recentPurchases)): ?>
          <li class="list-group-item text-muted">No purchases recorded yet.</li>
          <?php else: foreach ($recentPurchases as $p): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <strong><?= (int) $p['quantity'] ?> x <?= e($p['product_name']) ?></strong>
              <div class="small text-muted">Supplier: <?= e($p['supplier_name']) ?> &bull; <?= e(date('M j, Y', strtotime($p['purchase_date']))) ?></div>
            </div>
            <span class="badge status-badge text-dark bg-<?= $p['status'] === 'delivered' ? 'success' : ($p['status'] === 'cancelled' ? 'secondary' : 'warning') ?>">
              <?= e(ucfirst($p['status'])) ?>
            </span>
          </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow mb-4">
      <div class="card-header py-3"><h6 class="m-0 fw-bold text-success">Today's Sales</h6></div>
      <div class="card-body">
        <ul class="list-group list-group-flush">
          <?php if (empty($todaySales)): ?>
          <li class="list-group-item text-muted">No sales recorded today yet.</li>
          <?php else: foreach ($todaySales as $s): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <strong><?= e($s['items_summary'] ?: '—') ?></strong>
              <div class="small text-muted">Customer: <?= e($s['customer_name'] ?: 'Walk-in') ?> &bull; <?= e(ucwords(str_replace('_', ' ', $s['payment_method']))) ?></div>
            </div>
            <span class="text-success fw-semibold"><?= e(money($s['total_amount'])) ?></span>
          </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php
$chartLabels = array_column($stockByCategory, 'category');
$chartData = array_map('intval', array_column($stockByCategory, 'total'));

$extraScripts = '<script>
  const stockChartLabels = ' . json_encode($chartLabels) . ';
  const stockChartData = ' . json_encode($chartData) . ';
  const stockCtx = document.getElementById("stockChart").getContext("2d");
  new Chart(stockCtx, {
    type: "bar",
    data: {
      labels: stockChartLabels,
      datasets: [{
        label: "Available Stock",
        data: stockChartData,
        backgroundColor: ["#e74a3b", "#f6c23e", "#36b9cc", "#1cc88a", "#4e73df"],
        borderColor: ["#c9302c", "#d9a744", "#2a8dc0", "#17a673", "#2e59d9"],
        borderWidth: 1
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
