<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();

$reportType = $_GET['report_type'] ?? 'sales_summary';
$storeId = (int) ($_GET['store_id'] ?? 0);
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

if (!in_array($reportType, ['sales_summary', 'low_stock', 'inventory_valuation', 'top_products'], true)) {
    $reportType = 'sales_summary';
}
// Basic date sanity check; fall back to sensible defaults rather than erroring.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = date('Y-m-d');

$stores = $pdo->query('SELECT id, name FROM stores ORDER BY name')->fetchAll();

$storeClause = $storeId > 0 ? ' AND store_id = :store_id' : '';
$results = [];
$chartLabels = [];
$chartValues = [];
$summary = [];

if ($reportType === 'sales_summary') {
    $sql = "SELECT DATE(sale_date) AS d, COUNT(*) AS tx_count, SUM(total_amount) AS total
            FROM sales
            WHERE DATE(sale_date) BETWEEN :start AND :end" . $storeClause . "
            GROUP BY DATE(sale_date) ORDER BY d";
    $stmt = $pdo->prepare($sql);
    $params = [':start' => $startDate, ':end' => $endDate];
    if ($storeId > 0) $params[':store_id'] = $storeId;
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    foreach ($results as $r) { $chartLabels[] = $r['d']; $chartValues[] = (float) $r['total']; }
    $summary['Total Revenue'] = money(array_sum(array_column($results, 'total')));
    $summary['Total Transactions'] = array_sum(array_column($results, 'tx_count'));

} elseif ($reportType === 'top_products') {
    $sql = "SELECT p.name, c.name AS category, SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            JOIN products p ON p.id = si.product_id
            JOIN categories c ON c.id = p.category_id
            WHERE DATE(s.sale_date) BETWEEN :start AND :end" . $storeClause . "
            GROUP BY p.id, p.name, c.name ORDER BY revenue DESC LIMIT 15";
    $stmt = $pdo->prepare($sql);
    $params = [':start' => $startDate, ':end' => $endDate];
    if ($storeId > 0) $params[':store_id'] = $storeId;
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    foreach ($results as $r) { $chartLabels[] = $r['name']; $chartValues[] = (float) $r['revenue']; }
    $summary['Products Sold'] = count($results);
    $summary['Total Revenue'] = money(array_sum(array_column($results, 'revenue')));

} elseif ($reportType === 'low_stock') {
    $sql = "SELECT p.name, p.sku, c.name AS category, i.quantity, p.reorder_level, s.name AS store_name
            FROM inventory i JOIN products p ON p.id = i.product_id JOIN categories c ON c.id = p.category_id JOIN stores s ON s.id = i.store_id
            WHERE i.quantity <= p.reorder_level" . ($storeId > 0 ? ' AND i.store_id = :store_id' : '') . "
            ORDER BY i.quantity ASC";
    $stmt = $pdo->prepare($sql);
    $params = $storeId > 0 ? [':store_id' => $storeId] : [];
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    $summary['Items Below Reorder Level'] = count($results);

} elseif ($reportType === 'inventory_valuation') {
    $sql = "SELECT p.name, p.sku, c.name AS category, s.name AS store_name, i.quantity, p.cost_price, p.unit_price,
                   (i.quantity * p.cost_price) AS cost_value, (i.quantity * p.unit_price) AS retail_value
            FROM inventory i JOIN products p ON p.id = i.product_id JOIN categories c ON c.id = p.category_id JOIN stores s ON s.id = i.store_id
            WHERE 1=1" . ($storeId > 0 ? ' AND i.store_id = :store_id' : '') . "
            ORDER BY retail_value DESC";
    $stmt = $pdo->prepare($sql);
    $params = $storeId > 0 ? [':store_id' => $storeId] : [];
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    $summary['Total Cost Value'] = money(array_sum(array_column($results, 'cost_value')));
    $summary['Total Retail Value'] = money(array_sum(array_column($results, 'retail_value')));
}

// ---- CSV export ------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($results)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $reportType . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_keys($results[0]));
    foreach ($results as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$reportLabels = [
    'sales_summary' => 'Sales Summary',
    'top_products' => 'Top Selling Products',
    'low_stock' => 'Low Stock Report',
    'inventory_valuation' => 'Inventory Valuation',
];

$pageTitle = 'Reports';
require __DIR__ . '/includes/header.php';
?>

<form method="GET" action="reports.php" class="card shadow mb-4">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Report Type</label>
        <select class="form-select" name="report_type" onchange="this.form.submit()">
          <?php foreach ($reportLabels as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $reportType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Store</label>
        <select class="form-select" name="store_id">
          <option value="0">All Stores</option>
          <?php foreach ($stores as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $storeId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (in_array($reportType, ['sales_summary', 'top_products'], true)): ?>
      <div class="col-md-2">
        <label class="form-label">Start Date</label>
        <input type="date" class="form-control" name="start_date" value="<?= e($startDate) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">End Date</label>
        <input type="date" class="form-control" name="end_date" value="<?= e($endDate) ?>">
      </div>
      <?php endif; ?>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-danger flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
      </div>
    </div>
  </div>
</form>

<div class="row g-3 mb-4">
  <?php foreach ($summary as $label => $value): ?>
  <div class="col-md-3">
    <div class="card card-dashboard border-left-primary shadow h-100 py-2">
      <div class="card-body">
        <div class="text-xs fw-bold text-primary text-uppercase mb-1"><?= e($label) ?></div>
        <div class="h5 mb-0 fw-bold"><?= e((string) $value) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (in_array($reportType, ['sales_summary', 'top_products'], true) && !empty($chartLabels)): ?>
<div class="card shadow mb-4">
  <div class="card-header py-3"><h6 class="m-0 fw-bold text-primary"><?= e($reportLabels[$reportType]) ?></h6></div>
  <div class="card-body"><canvas id="reportChart" height="90"></canvas></div>
</div>
<?php endif; ?>

<div class="card shadow mb-4">
  <div class="card-header py-3 d-flex justify-content-between align-items-center">
    <h6 class="m-0 fw-bold text-primary"><?= e($reportLabels[$reportType]) ?> - Detail</h6>
    <?php if (!empty($results)): ?>
    <a class="btn btn-sm btn-outline-secondary" href="reports.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">
      <i class="bi bi-download me-1"></i> Export CSV
    </a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover">
        <thead>
          <tr>
            <?php if (!empty($results)): foreach (array_keys($results[0]) as $col): ?>
              <th><?= e(ucwords(str_replace('_', ' ', $col))) ?></th>
            <?php endforeach; endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($results)): ?>
          <tr><td class="text-center text-muted py-4">No data for the selected filters.</td></tr>
          <?php else: foreach ($results as $row): ?>
          <tr>
            <?php foreach ($row as $col => $val): ?>
              <td><?= e(is_numeric($val) && str_contains((string) $col, 'value') ? money($val) : (string) $val) ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
if (in_array($reportType, ['sales_summary', 'top_products'], true) && !empty($chartLabels)) {
    $extraScripts = '<script>
      new Chart(document.getElementById("reportChart").getContext("2d"), {
        type: "bar",
        data: {
          labels: ' . json_encode($chartLabels) . ',
          datasets: [{ label: "' . e(CURRENCY_CODE) . '", data: ' . json_encode($chartValues) . ', backgroundColor: "#e74a3b" }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
      });
    </script>';
}
require __DIR__ . '/includes/footer.php';
