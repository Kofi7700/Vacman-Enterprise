<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$miniManagerStoreId = is_role('mini_manager') && !is_role('admin', 'manager', 'warehouse') ? (int) (current_user()['store_id'] ?? 0) : 0;
$isInventoryStoreScoped = is_role('mini_manager', 'sales_clerk') && !is_role('admin', 'manager', 'warehouse');
$inventoryViewerStoreId = $isInventoryStoreScoped ? (int) (current_user()['store_id'] ?? 0) : 0;
$inventoryStoreScope = $isInventoryStoreScoped ? ' AND i.store_id = ' . $inventoryViewerStoreId : '';
$salesStoreScope = $miniManagerStoreId > 0 ? ' AND store_id = ' . $miniManagerStoreId : '';
$canViewMachineFees = is_role('admin', 'manager', 'cashier');
$machineFeeStoreId = is_role('cashier') && !is_role('admin', 'manager') ? (int) (current_user()['store_id'] ?? 0) : 0;
$machineFeeStoreScope = is_role('cashier') && !is_role('admin', 'manager')
    ? ($machineFeeStoreId > 0 ? ' AND store_id = ' . $machineFeeStoreId : ' AND 1 = 0')
    : '';

// ---- Key metrics ------------------------------------------------------
$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

$lowStockItems = $pdo->query("
    SELECT p.id, p.name, c.name AS category, i.quantity, p.reorder_level, s.name AS store_name
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    JOIN stores s ON s.id = i.store_id
    WHERE i.quantity <= p.reorder_level" . $inventoryStoreScope . "
    ORDER BY i.quantity ASC
    LIMIT 10
")->fetchAll();

$lowStockCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE i.quantity <= p.reorder_level" . $inventoryStoreScope . "
")->fetchColumn();

$monthlySales = (float) $pdo->query("
    SELECT COALESCE(SUM(total_amount), 0) FROM sales
    WHERE payment_status = 'approved' AND MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())" . $salesStoreScope . "
")->fetchColumn();

$transferStoreScope = $miniManagerStoreId > 0 ? ' AND (from_store_id = ' . $miniManagerStoreId . ' OR to_store_id = ' . $miniManagerStoreId . ')' : '';
$pendingTransfers = (int) $pdo->query("SELECT COUNT(*) FROM transfers WHERE status IN ('pending','approved','in_transit')" . $transferStoreScope)->fetchColumn();

$dailyMachineFeeAmount = 0.0;
$dailyMachineFeeCount = 0;
if ($canViewMachineFees) {
    $dailyMachineFeeAmount = (float) $pdo->query(
        "SELECT COALESCE(SUM(amount), 0) FROM machine_fee_payments WHERE DATE(paid_at) = CURDATE()" . $machineFeeStoreScope
    )->fetchColumn();
    $dailyMachineFeeCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM machine_fee_payments WHERE DATE(paid_at) = CURDATE()" . $machineFeeStoreScope
    )->fetchColumn();
}

// ---- Dashboard insights ----------------------------------------------
$stockByCategory = $pdo->query("
    SELECT c.name AS category, COALESCE(SUM(i.quantity), 0) AS total
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    LEFT JOIN inventory i ON i.product_id = p.id
    " . ($isInventoryStoreScoped ? 'WHERE i.store_id = ' . $inventoryViewerStoreId : '') . "
    GROUP BY c.id, c.name
    ORDER BY c.name
")->fetchAll();

$topSelling = $pdo->query("
    SELECT p.name, c.name AS category, SUM(si.quantity) AS qty_sold
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE s.payment_status = 'approved' AND MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())" . ($miniManagerStoreId > 0 ? ' AND s.store_id = ' . $miniManagerStoreId : '') . "
    GROUP BY p.id, p.name, c.name
    ORDER BY qty_sold DESC
    LIMIT 5
")->fetchAll();

$recentPurchases = $pdo->query("
    SELECT pu.id, pu.quantity, pu.status, pu.purchase_date, pu.supplier_name, p.name AS product_name
    FROM purchases pu
    JOIN products p ON p.id = pu.product_id
    " . ($miniManagerStoreId > 0 ? 'WHERE pu.store_id = ' . $miniManagerStoreId : '') . "
    ORDER BY pu.created_at DESC
    LIMIT 5
")->fetchAll();

$todaySales = $pdo->query("
    SELECT s.id, s.total_amount, s.payment_method, s.customer_name,
           GROUP_CONCAT(CONCAT(si.quantity, 'x ', p.name) ORDER BY p.name SEPARATOR ', ') AS items_summary
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN products p ON p.id = si.product_id
    WHERE s.payment_status = 'approved' AND DATE(s.sale_date) = CURDATE()" . ($miniManagerStoreId > 0 ? ' AND s.store_id = ' . $miniManagerStoreId : '') . "
    GROUP BY s.id
    ORDER BY s.sale_date DESC
    LIMIT 8
")->fetchAll();

function category_css_class(string $category): string
{
    $category = strtolower($category);
    if (str_contains($category, 'building')) return 'building';
    if (str_contains($category, 'electrical')) return 'electrical';
    return 'appliance';
}

$dashboardUser = current_user();
$firstName = explode(' ', trim($dashboardUser['full_name'] ?? 'User'))[0];
$maxTopSales = !empty($topSelling) ? max(array_map('intval', array_column($topSelling, 'qty_sold'))) : 1;

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>

<section class="dashboard-welcome mb-4">
  <div class="dashboard-welcome-copy">
    <span class="dashboard-overline"><i class="bi bi-grid-1x2-fill me-2"></i>Operations overview</span>
    <h2>Welcome, <?= e($firstName) ?>.</h2>
    <p>Here is what is happening across your inventory today.</p>
    <div class="dashboard-date"><i class="bi bi-calendar3 me-2"></i><?= e(date('l, F j, Y')) ?></div>
  </div>
  <div class="dashboard-welcome-actions">
    <?php if (is_role('admin', 'manager', 'mini_manager', 'sales_clerk')): ?>
    <a href="sales.php" class="btn dashboard-secondary-action"><i class="bi bi-cart-plus me-2"></i>Record sale</a>
    <?php endif; ?>
    <a href="reports.php" class="btn dashboard-primary-action"><i class="bi bi-bar-chart-line me-2"></i>View reports</a>
  </div>
</section>

<section class="dashboard-kpi-grid <?= $canViewMachineFees ? 'dashboard-kpi-grid-five' : '' ?> mb-4" aria-label="Key performance indicators">
  <a href="inventory.php" class="dashboard-kpi dashboard-kpi-alert">
    <span class="dashboard-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span>
    <span class="dashboard-kpi-content">
      <span class="dashboard-kpi-label">Low-stock items</span>
      <strong><?= $lowStockCount ?></strong>
      <small><?= $lowStockCount > 0 ? 'Needs your attention' : 'Stock levels look healthy' ?></small>
    </span>
    <i class="bi bi-arrow-up-right dashboard-kpi-arrow"></i>
  </a>

  <a href="products.php" class="dashboard-kpi dashboard-kpi-products">
    <span class="dashboard-kpi-icon"><i class="bi bi-box-seam"></i></span>
    <span class="dashboard-kpi-content">
      <span class="dashboard-kpi-label">Active products</span>
      <strong><?= $totalProducts ?></strong>
      <small>In your current catalogue</small>
    </span>
    <i class="bi bi-arrow-up-right dashboard-kpi-arrow"></i>
  </a>

  <a href="sales.php" class="dashboard-kpi dashboard-kpi-sales">
    <span class="dashboard-kpi-icon"><i class="bi bi-graph-up-arrow"></i></span>
    <span class="dashboard-kpi-content">
      <span class="dashboard-kpi-label">Sales this month</span>
      <strong><?= e(money($monthlySales)) ?></strong>
      <small><?= e(date('F Y')) ?> revenue</small>
    </span>
    <i class="bi bi-arrow-up-right dashboard-kpi-arrow"></i>
  </a>

  <a href="transfers.php" class="dashboard-kpi dashboard-kpi-transfers">
    <span class="dashboard-kpi-icon"><i class="bi bi-arrow-left-right"></i></span>
    <span class="dashboard-kpi-content">
      <span class="dashboard-kpi-label">Open transfers</span>
      <strong><?= $pendingTransfers ?></strong>
      <small>Pending or in transit</small>
    </span>
    <i class="bi bi-arrow-up-right dashboard-kpi-arrow"></i>
  </a>

  <?php if ($canViewMachineFees): ?>
  <a href="machine_fees.php" class="dashboard-kpi dashboard-kpi-fees">
    <span class="dashboard-kpi-icon"><i class="bi bi-gear-wide-connected"></i></span>
    <span class="dashboard-kpi-content">
      <span class="dashboard-kpi-label">Machine fees today</span>
      <strong><?= e(money($dailyMachineFeeAmount)) ?></strong>
      <small><?= $dailyMachineFeeCount ?> payment<?= $dailyMachineFeeCount === 1 ? '' : 's' ?> registered</small>
    </span>
    <i class="bi bi-arrow-up-right dashboard-kpi-arrow"></i>
  </a>
  <?php endif; ?>
</section>

<div class="row g-4 mb-4">
  <div class="col-xl-7">
    <section class="dashboard-panel h-100">
      <header class="dashboard-panel-header">
        <div>
          <span class="dashboard-section-label">Inventory mix</span>
          <h3>Stock by category</h3>
        </div>
        <span class="dashboard-panel-icon"><i class="bi bi-pie-chart"></i></span>
      </header>
      <div class="dashboard-inventory-charts">
        <div class="dashboard-inventory-chart">
          <span>Stock volume</span>
          <div class="dashboard-chart-wrap"><canvas id="stockChart"></canvas></div>
        </div>
        <div class="dashboard-inventory-chart dashboard-inventory-pie">
          <span>Category share</span>
          <div class="dashboard-chart-wrap"><canvas id="stockPieChart"></canvas></div>
        </div>
      </div>
    </section>
  </div>

  <div class="col-xl-5">
    <section class="dashboard-panel h-100">
      <header class="dashboard-panel-header">
        <div>
          <span class="dashboard-section-label">This month</span>
          <h3>Top-selling products</h3>
        </div>
        <a href="reports.php?report_type=top_products" class="dashboard-text-link">View report <i class="bi bi-arrow-right"></i></a>
      </header>
      <div class="dashboard-ranking">
        <?php if (empty($topSelling)): ?>
        <div class="dashboard-empty"><i class="bi bi-graph-up"></i><span>No sales recorded this month yet.</span></div>
        <?php else: foreach ($topSelling as $index => $item): ?>
        <?php $salesPercent = max(8, (int) round(((int) $item['qty_sold'] / $maxTopSales) * 100)); ?>
        <div class="dashboard-rank-item">
          <span class="dashboard-rank-number"><?= $index + 1 ?></span>
          <div class="dashboard-rank-content">
            <div class="d-flex justify-content-between gap-3">
              <div class="text-truncate"><strong><?= e($item['name']) ?></strong><small><?= e($item['category']) ?></small></div>
              <span class="dashboard-rank-value"><?= (int) $item['qty_sold'] ?> pieces sold</span>
            </div>
            <div class="dashboard-rank-bar"><span style="width: <?= $salesPercent ?>%"></span></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
  </div>
</div>

<section class="dashboard-panel mb-4">
  <header class="dashboard-panel-header">
    <div>
      <span class="dashboard-section-label">Inventory alerts</span>
      <h3>Low-stock products</h3>
    </div>
    <span class="dashboard-count-badge"><?= $lowStockCount ?> item<?= $lowStockCount === 1 ? '' : 's' ?></span>
  </header>
  <div class="table-responsive dashboard-table-wrap">
    <table class="table dashboard-table align-middle mb-0">
      <thead>
        <tr><th>Product</th><th>Category</th><th>Store</th><th>Available</th><th>Reorder at</th><th class="text-end">Action</th></tr>
      </thead>
      <tbody>
        <?php if (empty($lowStockItems)): ?>
        <tr><td colspan="6"><div class="dashboard-empty py-5"><i class="bi bi-check2-circle text-success"></i><span>No low-stock items right now.</span></div></td></tr>
        <?php else: foreach ($lowStockItems as $item): ?>
        <tr>
          <td><div class="dashboard-product-name"><span><?= e(strtoupper(substr($item['name'], 0, 1))) ?></span><strong><?= e($item['name']) ?></strong></div></td>
          <td><span class="product-category <?= category_css_class($item['category']) ?>"><?= e($item['category']) ?></span></td>
          <td><i class="bi bi-shop me-2 text-muted"></i><?= e($item['store_name']) ?></td>
          <td><span class="dashboard-stock-count"><?= (int) $item['quantity'] ?> pieces</span></td>
          <td><?= (int) $item['reorder_level'] ?> pieces</td>
          <td class="text-end"><a href="purchases.php?product_id=<?= (int) $item['id'] ?>" class="btn dashboard-reorder-button">Reorder <i class="bi bi-arrow-right ms-1"></i></a></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="row g-4">
  <div class="col-xl-6">
    <section class="dashboard-panel h-100">
      <header class="dashboard-panel-header">
        <div>
          <span class="dashboard-section-label">Supply activity</span>
          <h3>Recent purchases</h3>
        </div>
        <a href="purchases.php" class="dashboard-text-link">View all <i class="bi bi-arrow-right"></i></a>
      </header>
      <div class="dashboard-activity-list">
        <?php if (empty($recentPurchases)): ?>
        <div class="dashboard-empty"><i class="bi bi-truck"></i><span>No purchases recorded yet.</span></div>
        <?php else: foreach ($recentPurchases as $purchase): ?>
        <div class="dashboard-activity-item">
          <span class="dashboard-activity-icon purchase"><i class="bi bi-truck"></i></span>
          <div class="dashboard-activity-copy">
            <strong><?= (int) $purchase['quantity'] ?> pieces × <?= e($purchase['product_name']) ?></strong>
            <span><?= e($purchase['supplier_name']) ?> · <?= e(date('M j, Y', strtotime($purchase['purchase_date']))) ?></span>
          </div>
          <span class="dashboard-status status-<?= e($purchase['status']) ?>"><?= e(ucfirst($purchase['status'])) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
  </div>

  <div class="col-xl-6">
    <section class="dashboard-panel h-100">
      <header class="dashboard-panel-header">
        <div>
          <span class="dashboard-section-label">Live activity</span>
          <h3>Today's sales</h3>
        </div>
        <a href="sales.php" class="dashboard-text-link">View all <i class="bi bi-arrow-right"></i></a>
      </header>
      <div class="dashboard-activity-list">
        <?php if (empty($todaySales)): ?>
        <div class="dashboard-empty"><i class="bi bi-receipt"></i><span>No sales recorded today yet.</span></div>
        <?php else: foreach ($todaySales as $sale): ?>
        <div class="dashboard-activity-item">
          <span class="dashboard-activity-icon sale"><i class="bi bi-receipt"></i></span>
          <div class="dashboard-activity-copy">
            <strong><?= e($sale['items_summary'] ?: 'Sale #' . $sale['id']) ?></strong>
            <span><?= e($sale['customer_name'] ?: 'Walk-in customer') ?> · <?= e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></span>
          </div>
          <span class="dashboard-sale-value"><?= e(money($sale['total_amount'])) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
  </div>
</div>

<?php
$chartLabels = array_column($stockByCategory, 'category');
$chartData = array_map('intval', array_column($stockByCategory, 'total'));

$extraScripts = '<script>
  const stockChartLabels = ' . json_encode($chartLabels) . ';
  const stockChartData = ' . json_encode($chartData) . ';
  const stockChartColors = ["#167003", "#f6c23e", "#36b9cc", "#4e73df", "#e74a3b", "#8b5cf6", "#f97316", "#14b8a6"];
  const stockCanvas = document.getElementById("stockChart");
  if (stockCanvas) {
    new Chart(stockCanvas.getContext("2d"), {
      type: "bar",
      data: {
        labels: stockChartLabels,
        datasets: [{
          label: "Available stock",
          data: stockChartData,
          backgroundColor: stockChartLabels.map((label, index) => stockChartColors[index % stockChartColors.length]),
          hoverBackgroundColor: stockChartLabels.map((label, index) => stockChartColors[index % stockChartColors.length]),
          borderRadius: 8,
          borderSkipped: false,
          maxBarThickness: 48
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: "index" },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: "#17351d",
            padding: 12,
            displayColors: false,
            callbacks: { label: (context) => context.parsed.y + " pieces" }
          }
        },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { color: "#747b76" } },
          y: { beginAtZero: true, border: { display: false }, grid: { color: "rgba(80, 100, 84, 0.08)" }, ticks: { color: "#8a908b", precision: 0 } }
        }
      }
    });
  }
  const stockPieCanvas = document.getElementById("stockPieChart");
  if (stockPieCanvas) {
    new Chart(stockPieCanvas.getContext("2d"), {
      type: "pie",
      data: {
        labels: stockChartLabels,
        datasets: [{
          data: stockChartData,
          backgroundColor: stockChartLabels.map((label, index) => stockChartColors[index % stockChartColors.length]),
          borderColor: "#ffffff",
          borderWidth: 3,
          hoverOffset: 7
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "bottom", labels: { boxWidth: 10, boxHeight: 10, padding: 12, color: "#626a64", font: { size: 10 } } },
          tooltip: {
            backgroundColor: "#17351d",
            padding: 12,
            callbacks: {
              label: (context) => {
                const total = context.dataset.data.reduce((sum, value) => sum + Number(value), 0);
                const value = Number(context.raw);
                const percent = total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
                return context.label + ": " + value + " pieces (" + percent + "%)";
              }
            }
          }
        }
      }
    });
  }
</script>';

require __DIR__ . '/includes/footer.php';
?>
