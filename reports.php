<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$miniManagerStoreId = is_role('mini_manager') && !is_role('admin', 'manager', 'warehouse') ? (int) (current_user()['store_id'] ?? 0) : 0;

function valid_report_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function report_csv_cell($value)
{
    if (is_string($value) && preg_match('/^[=+\-@]/', $value)) return "'" . $value;
    return $value;
}

$reportType = $_GET['report_type'] ?? 'sales_summary';
$storeId = (int) ($_GET['store_id'] ?? 0);
if ($miniManagerStoreId > 0) $storeId = $miniManagerStoreId;
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$validReportTypes = ['sales_summary', 'low_stock', 'inventory_valuation', 'top_products'];
if (!in_array($reportType, $validReportTypes, true)) $reportType = 'sales_summary';
if (!valid_report_date($startDate)) $startDate = date('Y-m-01');
if (!valid_report_date($endDate)) $endDate = date('Y-m-d');
if ($startDate > $endDate) [$startDate, $endDate] = [$endDate, $startDate];

$storeSql = 'SELECT id, name FROM stores';
if ($miniManagerStoreId > 0) $storeSql .= ' WHERE id = ' . $miniManagerStoreId;
$stores = $pdo->query($storeSql . ' ORDER BY name')->fetchAll();
$selectedStore = 'All stores';
foreach ($stores as $store) {
    if ((int) $store['id'] === $storeId) {
        $selectedStore = $store['name'];
        break;
    }
}

$reportMeta = [
    'sales_summary' => ['label' => 'Sales Summary', 'description' => 'Daily revenue and transaction performance.', 'icon' => 'graph-up-arrow'],
    'top_products' => ['label' => 'Top Products', 'description' => 'Best sellers ranked by revenue.', 'icon' => 'trophy'],
    'low_stock' => ['label' => 'Low Stock', 'description' => 'Items at or below their reorder level.', 'icon' => 'exclamation-triangle'],
    'inventory_valuation' => ['label' => 'Stock Value', 'description' => 'Inventory value at current selling prices.', 'icon' => 'boxes'],
];

$results = [];
$chartLabels = [];
$chartValues = [];
$summaryCards = [];
$usesDateRange = in_array($reportType, ['sales_summary', 'top_products'], true);

if ($reportType === 'sales_summary') {
    $sql = "SELECT DATE(s.sale_date) AS report_date, COUNT(*) AS transaction_count,
                   COALESCE(SUM(s.total_amount), 0) AS revenue,
                   COALESCE(AVG(s.total_amount), 0) AS average_sale
            FROM sales s
            WHERE s.payment_status = 'approved' AND DATE(s.sale_date) BETWEEN :start AND :end";
    $params = [':start' => $startDate, ':end' => $endDate];
    if ($storeId > 0) { $sql .= ' AND s.store_id = :store_id'; $params[':store_id'] = $storeId; }
    $sql .= ' GROUP BY DATE(s.sale_date) ORDER BY report_date';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $totalRevenue = (float) array_sum(array_column($results, 'revenue'));
    $totalTransactions = (int) array_sum(array_column($results, 'transaction_count'));
    foreach ($results as $row) { $chartLabels[] = $row['report_date']; $chartValues[] = (float) $row['revenue']; }
    $summaryCards = [
        ['label' => 'Total revenue', 'value' => money($totalRevenue), 'hint' => 'Selected period', 'icon' => 'cash-stack', 'tone' => 'revenue'],
        ['label' => 'Transactions', 'value' => number_format($totalTransactions), 'hint' => 'Completed checkouts', 'icon' => 'receipt', 'tone' => 'transactions'],
        ['label' => 'Average sale', 'value' => money($totalTransactions ? $totalRevenue / $totalTransactions : 0), 'hint' => 'Revenue per transaction', 'icon' => 'calculator', 'tone' => 'average'],
        ['label' => 'Active sales days', 'value' => number_format(count($results)), 'hint' => 'Days with transactions', 'icon' => 'calendar-check', 'tone' => 'days'],
    ];
} elseif ($reportType === 'top_products') {
    $sql = "SELECT p.name, p.sku, c.name AS category, SUM(si.quantity) AS quantity_sold,
                   COALESCE(SUM(si.subtotal), 0) AS revenue
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id
            JOIN products p ON p.id = si.product_id
            JOIN categories c ON c.id = p.category_id
            WHERE s.payment_status = 'approved' AND DATE(s.sale_date) BETWEEN :start AND :end";
    $params = [':start' => $startDate, ':end' => $endDate];
    if ($storeId > 0) { $sql .= ' AND s.store_id = :store_id'; $params[':store_id'] = $storeId; }
    $sql .= ' GROUP BY p.id, p.name, p.sku, c.name ORDER BY revenue DESC LIMIT 15';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $unitsSold = (int) array_sum(array_column($results, 'quantity_sold'));
    $revenue = (float) array_sum(array_column($results, 'revenue'));
    foreach ($results as $row) { $chartLabels[] = $row['name']; $chartValues[] = (float) $row['revenue']; }
    $summaryCards = [
        ['label' => 'Top products', 'value' => number_format(count($results)), 'hint' => 'Ranked products shown', 'icon' => 'trophy', 'tone' => 'revenue'],
        ['label' => 'Pieces sold', 'value' => number_format($unitsSold), 'hint' => 'Across ranked products', 'icon' => 'box-seam', 'tone' => 'transactions'],
        ['label' => 'Generated revenue', 'value' => money($revenue), 'hint' => 'From ranked products', 'icon' => 'cash-coin', 'tone' => 'average'],
        ['label' => 'Average per piece', 'value' => money($unitsSold ? $revenue / $unitsSold : 0), 'hint' => 'Blended selling price', 'icon' => 'tag', 'tone' => 'days'],
    ];
} elseif ($reportType === 'low_stock') {
    $sql = "SELECT p.name, p.sku, c.name AS category, i.quantity, p.reorder_level,
                   GREATEST(p.reorder_level - i.quantity, 0) AS reorder_gap, s.name AS store_name
            FROM inventory i
            JOIN products p ON p.id = i.product_id
            JOIN categories c ON c.id = p.category_id
            JOIN stores s ON s.id = i.store_id
            WHERE i.quantity <= p.reorder_level";
    $params = [];
    if ($storeId > 0) { $sql .= ' AND i.store_id = :store_id'; $params[':store_id'] = $storeId; }
    $sql .= ' ORDER BY i.quantity ASC, p.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $outOfStock = count(array_filter($results, static fn($row) => (int) $row['quantity'] === 0));
    $reorderUnits = (int) array_sum(array_column($results, 'reorder_gap'));
    $affectedStores = count(array_unique(array_column($results, 'store_name')));
    $summaryCards = [
        ['label' => 'Low-stock items', 'value' => number_format(count($results)), 'hint' => 'At or below threshold', 'icon' => 'exclamation-triangle', 'tone' => 'warning'],
        ['label' => 'Out of stock', 'value' => number_format($outOfStock), 'hint' => 'Zero pieces available', 'icon' => 'x-octagon', 'tone' => 'danger'],
        ['label' => 'Reorder gap', 'value' => number_format($reorderUnits), 'hint' => 'Pieces to reach threshold', 'icon' => 'cart-plus', 'tone' => 'average'],
        ['label' => 'Affected stores', 'value' => number_format($affectedStores), 'hint' => 'Locations needing attention', 'icon' => 'shop', 'tone' => 'days'],
    ];
} else {
    $sql = "SELECT p.name, p.sku, c.name AS category, s.name AS store_name, i.quantity,
                   p.unit_price AS selling_price, (i.quantity * p.unit_price) AS selling_value
            FROM inventory i
            JOIN products p ON p.id = i.product_id
            JOIN categories c ON c.id = p.category_id
            JOIN stores s ON s.id = i.store_id
            WHERE 1 = 1";
    $params = [];
    if ($storeId > 0) { $sql .= ' AND i.store_id = :store_id'; $params[':store_id'] = $storeId; }
    $sql .= ' ORDER BY selling_value DESC, p.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $totalUnits = (int) array_sum(array_column($results, 'quantity'));
    $sellingValue = (float) array_sum(array_column($results, 'selling_value'));
    $stockedLines = count(array_filter($results, static fn($row) => (int) $row['quantity'] > 0));
    $summaryCards = [
        ['label' => 'Selling value', 'value' => money($sellingValue), 'hint' => 'At current selling prices', 'icon' => 'cash-stack', 'tone' => 'revenue'],
        ['label' => 'Stock pieces', 'value' => number_format($totalUnits), 'hint' => 'Pieces currently recorded', 'icon' => 'boxes', 'tone' => 'transactions'],
        ['label' => 'Stocked lines', 'value' => number_format($stockedLines), 'hint' => 'Product-store combinations', 'icon' => 'clipboard-data', 'tone' => 'average'],
        ['label' => 'Average piece value', 'value' => money($totalUnits ? $sellingValue / $totalUnits : 0), 'hint' => 'Across available stock', 'icon' => 'tag', 'tone' => 'days'],
    ];
}

// ---- CSV export ------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv' && $results) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $reportType . '_' . date('Ymd') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_map(static fn($key) => ucwords(str_replace('_', ' ', $key)), array_keys($results[0])));
    foreach ($results as $row) fputcsv($output, array_map('report_csv_cell', $row));
    fclose($output);
    exit;
}

$filterQuery = ['report_type' => $reportType, 'store_id' => $storeId, 'start_date' => $startDate, 'end_date' => $endDate];
$exportUrl = 'reports.php?' . http_build_query(array_merge($filterQuery, ['export' => 'csv']));
$pageTitle = 'Reports';
require __DIR__ . '/includes/header.php';
?>

<section class="report-hero mb-4">
  <div>
    <span class="report-overline"><i class="bi bi-bar-chart-line me-2"></i>Business intelligence</span>
    <h2>Turn daily activity into clear decisions.</h2>
    <p>Explore sales performance, product demand, stock risks, and the selling value of inventory across every store.</p>
  </div>
  <?php if ($results): ?><a class="btn report-export-button" href="<?= e($exportUrl) ?>"><i class="bi bi-download me-2"></i>Export CSV</a><?php endif; ?>
</section>

<nav class="report-type-grid mb-4" aria-label="Report types">
  <?php foreach ($reportMeta as $key => $meta):
    $typeUrl = 'reports.php?' . http_build_query(['report_type' => $key, 'store_id' => $storeId, 'start_date' => $startDate, 'end_date' => $endDate]);
  ?>
  <a href="<?= e($typeUrl) ?>" class="report-type-card <?= $reportType === $key ? 'active' : '' ?>" <?= $reportType === $key ? 'aria-current="page"' : '' ?>>
    <span><i class="bi bi-<?= e($meta['icon']) ?>"></i></span><div><strong><?= e($meta['label']) ?></strong><small><?= e($meta['description']) ?></small></div><i class="bi bi-chevron-right"></i>
  </a>
  <?php endforeach; ?>
</nav>

<section class="report-filter-panel mb-4">
  <header><div><span class="report-section-label">Report controls</span><h3><?= e($reportMeta[$reportType]['label']) ?></h3></div><span class="report-scope"><i class="bi bi-geo-alt"></i><?= e($selectedStore) ?></span></header>
  <form method="GET" action="reports.php" class="report-filters">
    <div><label class="form-label" for="reportType">Report type</label><select class="form-select" name="report_type" id="reportType"><?php foreach ($reportMeta as $key => $meta): ?><option value="<?= e($key) ?>" <?= $reportType === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="reportStore">Store</label><select class="form-select" name="store_id" id="reportStore"><option value="0">All stores</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= $storeId === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select></div>
    <div class="report-date-field <?= $usesDateRange ? '' : 'is-disabled' ?>"><label class="form-label" for="reportStartDate">Start date</label><input type="date" class="form-control" name="start_date" id="reportStartDate" value="<?= e($startDate) ?>" <?= !$usesDateRange ? 'disabled' : '' ?>></div>
    <div class="report-date-field <?= $usesDateRange ? '' : 'is-disabled' ?>"><label class="form-label" for="reportEndDate">End date</label><input type="date" class="form-control" name="end_date" id="reportEndDate" value="<?= e($endDate) ?>" <?= !$usesDateRange ? 'disabled' : '' ?>></div>
    <button type="submit" class="btn report-run-button"><i class="bi bi-play-fill me-1"></i>Run report</button>
  </form>
</section>

<section class="report-kpi-grid mb-4" aria-label="Report summary">
  <?php foreach ($summaryCards as $card): ?>
  <div class="report-kpi report-kpi-<?= e($card['tone']) ?>"><span class="report-kpi-icon"><i class="bi bi-<?= e($card['icon']) ?>"></i></span><div><span><?= e($card['label']) ?></span><strong><?= e((string) $card['value']) ?></strong><small><?= e($card['hint']) ?></small></div></div>
  <?php endforeach; ?>
</section>

<?php if ($chartLabels): ?>
<section class="report-panel mb-4">
  <header class="report-panel-header"><div><span class="report-section-label">Visual overview</span><h3><?= $reportType === 'sales_summary' ? 'Revenue trend' : 'Revenue by product' ?></h3></div><span class="report-period"><i class="bi bi-calendar3"></i><?= e(date('M j, Y', strtotime($startDate))) ?> – <?= e(date('M j, Y', strtotime($endDate))) ?></span></header>
  <div class="report-chart-wrap"><canvas id="reportChart" aria-label="<?= e($reportMeta[$reportType]['label']) ?> chart" role="img"></canvas></div>
</section>
<?php endif; ?>

<section class="report-panel">
  <header class="report-panel-header"><div><span class="report-section-label">Detailed results</span><h3><?= e($reportMeta[$reportType]['label']) ?></h3></div><div class="report-panel-actions"><span class="report-result-count"><?= count($results) ?> row<?= count($results) === 1 ? '' : 's' ?></span><?php if ($results): ?><a href="<?= e($exportUrl) ?>" class="btn report-table-export"><i class="bi bi-download"></i><span>Export</span></a><?php endif; ?></div></header>
  <div class="table-responsive report-table-wrap">
    <table class="table align-middle report-table mb-0">
      <?php if ($reportType === 'sales_summary'): ?>
      <thead><tr><th>Date</th><th>Transactions</th><th>Revenue</th><th>Average sale</th><th>Share</th></tr></thead>
      <tbody><?php if (!$results): ?><tr><td colspan="5"><?php require __DIR__ . '/includes/report-empty.php'; ?></td></tr><?php else: foreach ($results as $row): $share = $totalRevenue > 0 ? ((float) $row['revenue'] / $totalRevenue) * 100 : 0; ?><tr><td><div class="report-date"><span><?= e(date('d', strtotime($row['report_date']))) ?></span><div><strong><?= e(date('M Y', strtotime($row['report_date']))) ?></strong><small><?= e(date('l', strtotime($row['report_date']))) ?></small></div></div></td><td><strong class="report-number"><?= number_format((int) $row['transaction_count']) ?></strong></td><td><strong class="report-money"><?= e(money($row['revenue'])) ?></strong></td><td><?= e(money($row['average_sale'])) ?></td><td><div class="report-share"><span style="width: <?= min(100, $share) ?>%"></span></div><small class="report-share-label"><?= number_format($share, 1) ?>%</small></td></tr><?php endforeach; endif; ?></tbody>
      <?php elseif ($reportType === 'top_products'): ?>
      <thead><tr><th>Rank</th><th>Product</th><th>Category</th><th>Pieces sold</th><th>Revenue</th></tr></thead>
      <tbody><?php if (!$results): ?><tr><td colspan="5"><?php require __DIR__ . '/includes/report-empty.php'; ?></td></tr><?php else: foreach ($results as $index => $row): ?><tr><td><span class="report-rank <?= $index < 3 ? 'top' : '' ?>"><?= $index + 1 ?></span></td><td><div class="report-product"><strong><?= e($row['name']) ?></strong><small><?= e($row['sku']) ?></small></div></td><td><span class="report-category"><?= e($row['category']) ?></span></td><td><strong class="report-number"><?= number_format((int) $row['quantity_sold']) ?> pieces</strong></td><td><strong class="report-money"><?= e(money($row['revenue'])) ?></strong></td></tr><?php endforeach; endif; ?></tbody>
      <?php elseif ($reportType === 'low_stock'): ?>
      <thead><tr><th>Product</th><th>Store</th><th>Category</th><th>Available</th><th>Reorder level</th><th>Shortfall</th></tr></thead>
      <tbody><?php if (!$results): ?><tr><td colspan="6"><?php require __DIR__ . '/includes/report-empty.php'; ?></td></tr><?php else: foreach ($results as $row): ?><tr><td><div class="report-product"><strong><?= e($row['name']) ?></strong><small><?= e($row['sku']) ?></small></div></td><td><span class="report-store"><i class="bi bi-shop"></i><?= e($row['store_name']) ?></span></td><td><span class="report-category"><?= e($row['category']) ?></span></td><td><span class="report-stock-level <?= (int) $row['quantity'] === 0 ? 'empty' : 'low' ?>"><?= number_format((int) $row['quantity']) ?> pieces</span></td><td><?= number_format((int) $row['reorder_level']) ?> pieces</td><td><strong class="report-shortfall">+<?= number_format((int) $row['reorder_gap']) ?></strong></td></tr><?php endforeach; endif; ?></tbody>
      <?php else: ?>
      <thead><tr><th>Product</th><th>Store</th><th>Category</th><th>Quantity</th><th>Selling price</th><th>Selling value</th></tr></thead>
      <tbody><?php if (!$results): ?><tr><td colspan="6"><?php require __DIR__ . '/includes/report-empty.php'; ?></td></tr><?php else: foreach ($results as $row): ?><tr><td><div class="report-product"><strong><?= e($row['name']) ?></strong><small><?= e($row['sku']) ?></small></div></td><td><span class="report-store"><i class="bi bi-shop"></i><?= e($row['store_name']) ?></span></td><td><span class="report-category"><?= e($row['category']) ?></span></td><td><strong class="report-number"><?= number_format((int) $row['quantity']) ?></strong></td><td><?= e(money($row['selling_price'])) ?></td><td><strong class="report-money"><?= e(money($row['selling_value'])) ?></strong></td></tr><?php endforeach; endif; ?></tbody>
      <?php endif; ?>
    </table>
  </div>
</section>

<?php
if ($chartLabels) {
    $safeLabels = json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $safeValues = json_encode($chartValues, JSON_NUMERIC_CHECK);
    $safeCurrency = json_encode(CURRENCY_SYMBOL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $chartType = $reportType === 'sales_summary' ? 'line' : 'bar';
    $extraScripts = '<script>
    (function () {
      const canvas = document.getElementById("reportChart");
      if (!canvas) return;
      new Chart(canvas.getContext("2d"), {
        type: "' . $chartType . '",
        data: { labels: ' . $safeLabels . ', datasets: [{ data: ' . $safeValues . ', borderColor: "#167003", backgroundColor: "rgba(22, 112, 3, 0.14)", borderWidth: 2, borderRadius: 6, pointBackgroundColor: "#167003", pointRadius: 3, fill: ' . ($chartType === 'line' ? 'true' : 'false') . ', tension: 0.32 }] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: "index" }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (context) { return ' . $safeCurrency . ' + " " + Number(context.raw).toLocaleString(); } } } }, scales: { x: { grid: { display: false }, ticks: { color: "#7c847e", maxRotation: 35 } }, y: { beginAtZero: true, grid: { color: "rgba(90, 110, 94, 0.08)" }, ticks: { color: "#7c847e", callback: function (value) { return ' . $safeCurrency . ' + " " + Number(value).toLocaleString(); } } } } }
      });
    })();
    </script>';
}
require __DIR__ . '/includes/footer.php';
?>
