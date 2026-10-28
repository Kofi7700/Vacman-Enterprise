<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canSell = is_role('admin', 'manager', 'mini_manager', 'sales_clerk');
$canApprovePayment = is_role('admin', 'manager', 'mini_manager', 'cashier');
$canCorrectSale = is_role('admin', 'manager');
$canViewAllStores = is_role('admin', 'manager');
$assignedStoreId = $canViewAllStores ? 0 : (int) (current_user()['store_id'] ?? 0);

// ---- Delete sale and reverse its stock movement (admin only) ---------
if (is_post() && isset($_POST['delete_sale'])) {
    require_role('admin');
    verify_csrf();

    $saleId = (int) ($_POST['sale_id'] ?? 0);
    if ($saleId <= 0) {
        flash_set('danger', 'Invalid sale.');
        redirect('sales.php');
    }

    try {
        $pdo->beginTransaction();

        $saleStmt = $pdo->prepare(
            'SELECT id, store_id, transaction_id, total_amount, payment_status, sale_date
             FROM sales WHERE id = ? FOR UPDATE'
        );
        $saleStmt->execute([$saleId]);
        $sale = $saleStmt->fetch();
        if (!$sale) {
            throw new RuntimeException('Sale not found.');
        }

        $itemsStmt = $pdo->prepare(
            'SELECT product_id, SUM(quantity) AS quantity
             FROM sale_items WHERE sale_id = ? GROUP BY product_id'
        );
        $itemsStmt->execute([$saleId]);
        $deletedItems = $itemsStmt->fetchAll();

        $restoreStock = $pdo->prepare(
            'INSERT INTO inventory (product_id, store_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
        );
        foreach ($deletedItems as $item) {
            $restoreStock->execute([(int) $item['product_id'], (int) $sale['store_id'], (int) $item['quantity']]);
        }

        $pdo->prepare('DELETE FROM sales WHERE id = ?')->execute([$saleId]);

        $refreshLastSold = $pdo->prepare(
            'UPDATE inventory
             SET last_sold_date = (
                 SELECT DATE(MAX(s.sale_date))
                 FROM sales s
                 JOIN sale_items si ON si.sale_id = s.id
                 WHERE s.store_id = ? AND si.product_id = ?
             )
             WHERE store_id = ? AND product_id = ?'
        );
        foreach ($deletedItems as $item) {
            $productId = (int) $item['product_id'];
            $storeId = (int) $sale['store_id'];
            $refreshLastSold->execute([$storeId, $productId, $storeId, $productId]);
        }

        $pdo->commit();

        log_audit('delete', 'sale', $saleId, [
            'transaction_id' => $sale['transaction_id'],
            'store_id' => (int) $sale['store_id'],
            'total_amount' => (float) $sale['total_amount'],
            'payment_status' => $sale['payment_status'],
            'sale_date' => $sale['sale_date'],
            'items' => array_map(static fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ], $deletedItems),
        ], null);
        flash_set('success', 'Sale deleted and its stock quantities were restored.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Delete sale failed: ' . $e->getMessage());
        flash_set('danger', 'Could not delete the sale.');
    }
    redirect('sales.php');
}

// ---- Approve a pending payment ---------------------------------------
if (is_post() && isset($_POST['approve_payment'])) {
    require_role('admin', 'manager', 'mini_manager', 'cashier');
    verify_csrf();
    $saleId = (int) ($_POST['sale_id'] ?? 0);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT payment_status, store_id FROM sales WHERE id = ? FOR UPDATE');
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) throw new RuntimeException('Sale not found.');
        if ($sale['payment_status'] !== 'pending') throw new RuntimeException('This payment has already been approved.');
        if (!$canViewAllStores && ($assignedStoreId <= 0 || (int) $sale['store_id'] !== $assignedStoreId)) {
            throw new RuntimeException('You can approve payments only for your assigned store.');
        }

        $pdo->prepare("UPDATE sales SET payment_status = 'approved', balance_amount = 0, approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([(int) current_user()['id'], $saleId]);
        $pdo->commit();
        log_audit('approve_payment', 'sale', $saleId, ['payment_status' => 'pending'], ['payment_status' => 'approved']);
        flash_set('success', 'Payment approved successfully.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Approve payment failed: ' . $e->getMessage());
        flash_set('danger', 'Could not approve the payment.');
    }
    redirect('sales.php');
}

// ---- Correct receipt details (admin and manager only) ----------------
if (is_post() && isset($_POST['edit_sale'])) {
    require_role('admin', 'manager');
    verify_csrf();

    $saleId = (int) ($_POST['sale_id'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? '';
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $discountInput = $_POST['discount_amount'] ?? '0';
    $vatInput = $_POST['vat_amount'] ?? '0';
    $errors = [];

    if (!in_array($paymentMethod, ['cash', 'mobile_money', 'card', 'credit'], true)) $errors[] = 'Invalid payment method.';
    foreach (['Discount' => $discountInput, 'VAT' => $vatInput] as $label => $value) {
        if ($value === '' || !is_numeric($value) || (float) $value < 0) $errors[] = $label . ' must be zero or a positive amount.';
    }
    if (strlen($customerName) > 100) $errors[] = 'Customer name is too long.';
    if (strlen($customerPhone) > 20) $errors[] = 'Customer phone is too long.';

    if (!$errors && $saleId > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT customer_name, customer_phone, payment_method, payment_status, subtotal_amount, discount_amount, vat_amount, total_amount, balance_amount, notes FROM sales WHERE id = ? FOR UPDATE');
            $stmt->execute([$saleId]);
            $existingSale = $stmt->fetch();
            if (!$existingSale) throw new RuntimeException('Sale not found.');

            $subtotalStmt = $pdo->prepare('SELECT COALESCE(SUM(subtotal), 0) FROM sale_items WHERE sale_id = ?');
            $subtotalStmt->execute([$saleId]);
            $subtotal = round((float) $subtotalStmt->fetchColumn(), 2);
            $discountAmount = round((float) $discountInput, 2);
            $vatAmount = round((float) $vatInput, 2);
            if ($discountAmount > $subtotal) throw new RuntimeException('Discount cannot be greater than the sale subtotal.');

            $total = round($subtotal - $discountAmount + $vatAmount, 2);
            $balance = $existingSale['payment_status'] === 'approved' ? 0.0 : $total;
            $newValues = [
                'customer_name' => $customerName !== '' ? $customerName : null,
                'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                'payment_method' => $paymentMethod,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discountAmount,
                'vat_amount' => $vatAmount,
                'total_amount' => $total,
                'balance_amount' => $balance,
                'notes' => $notes !== '' ? $notes : null,
            ];

            $update = $pdo->prepare('UPDATE sales SET customer_name = ?, customer_phone = ?, payment_method = ?, subtotal_amount = ?, discount_amount = ?, vat_amount = ?, total_amount = ?, balance_amount = ?, notes = ?, corrected_by = ?, corrected_at = NOW() WHERE id = ?');
            $update->execute([
                $newValues['customer_name'], $newValues['customer_phone'], $paymentMethod, $subtotal,
                $discountAmount, $vatAmount, $total, $balance, $newValues['notes'], (int) current_user()['id'], $saleId,
            ]);
            $pdo->commit();
            log_audit('correct_receipt', 'sale', $saleId, $existingSale, $newValues);
            flash_set('success', 'Receipt corrected successfully.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('warning', $e->getMessage());
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Correct receipt failed: ' . $e->getMessage());
            flash_set('danger', 'Could not correct the receipt.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)) ?: 'Invalid sale.');
    }
    redirect('sales.php');
}

// ---- Handle Record Sale (one or more products in a single checkout) --
if (is_post() && isset($_POST['add_sale'])) {
    require_role('admin', 'manager', 'mini_manager', 'sales_clerk');
    verify_csrf();

    $storeId = (int) ($_POST['store_id'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? '';
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $discountInput = $_POST['discount_amount'] ?? '0';
    $vatInput = $_POST['vat_amount'] ?? '0';
    $rawProductIds = $_POST['product_id'] ?? [];
    $rawQuantities = $_POST['quantity'] ?? [];

    $errors = validate($_POST, [
        'store_id' => 'required|int',
        'payment_method' => 'required',
    ]);
    if (!in_array($paymentMethod, ['cash', 'mobile_money', 'card', 'credit'], true)) {
        $errors[] = 'Invalid payment method.';
    }
    if (!$canViewAllStores && ($assignedStoreId <= 0 || $storeId !== $assignedStoreId)) {
        $errors[] = 'You can record sales only for your assigned store.';
    }
    if (!is_array($rawProductIds) || !is_array($rawQuantities)) {
        $errors[] = 'Invalid product list.';
    }
    foreach (['Discount' => $discountInput, 'VAT' => $vatInput] as $label => $value) {
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            $errors[] = $label . ' must be zero or a positive amount.';
        }
    }

    $discountAmount = round((float) $discountInput, 2);
    $vatAmount = round((float) $vatInput, 2);

    $lines = [];
    if (empty($errors)) {
        $rowCount = max(count($rawProductIds), count($rawQuantities));
        for ($i = 0; $i < $rowCount; $i++) {
            $productId = (int) ($rawProductIds[$i] ?? 0);
            $quantity = (int) ($rawQuantities[$i] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }
            $lines[$productId] = ($lines[$productId] ?? 0) + $quantity;
        }
        if (!$lines) {
            $errors[] = 'Add at least one product to the sale.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            ksort($lines);

            $lockStmt = $pdo->prepare(
                'SELECT i.id, i.quantity, p.unit_price, p.name
                 FROM inventory i JOIN products p ON p.id = i.product_id
                 WHERE i.product_id = ? AND i.store_id = ? FOR UPDATE'
            );

            $items = [];
            $total = 0.0;
            foreach ($lines as $productId => $quantity) {
                $lockStmt->execute([$productId, $storeId]);
                $inventory = $lockStmt->fetch();

                if (!$inventory) {
                    throw new RuntimeException('One of the selected products is not stocked at the selected store.');
                }
                if ((int) $inventory['quantity'] < $quantity) {
                    throw new RuntimeException('Not enough stock: only ' . (int) $inventory['quantity'] . ' pieces of "' . $inventory['name'] . '" available.');
                }

                $unitPrice = (float) $inventory['unit_price'];
                $subtotal = $unitPrice * $quantity;
                $total += $subtotal;
                $items[] = [
                    'product_id' => $productId,
                    'inventory_id' => (int) $inventory['id'],
                    'name' => $inventory['name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ];
            }

            $subTotal = round($total, 2);
            if ($discountAmount > $subTotal) {
                throw new RuntimeException('Discount cannot be greater than the sale subtotal.');
            }
            $total = round($subTotal - $discountAmount + $vatAmount, 2);
            $balanceAmount = $total;

            $transactionId = 'TXN-' . date('Ymd-His') . '-' . random_int(100, 999);
            $insertSale = $pdo->prepare(
                'INSERT INTO sales (store_id, user_id, customer_name, customer_phone, subtotal_amount, discount_amount, vat_amount, total_amount, balance_amount, payment_method, transaction_id, payment_status)
                 VALUES (:store_id, :user_id, :customer_name, :customer_phone, :subtotal_amount, :discount_amount, :vat_amount, :total_amount, :balance_amount, :payment_method, :transaction_id, \'pending\')'
            );
            $insertSale->execute([
                ':store_id' => $storeId,
                ':user_id' => current_user()['id'],
                ':customer_name' => $customerName !== '' ? $customerName : null,
                ':customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                ':subtotal_amount' => $subTotal,
                ':discount_amount' => $discountAmount,
                ':vat_amount' => $vatAmount,
                ':total_amount' => $total,
                ':balance_amount' => $balanceAmount,
                ':payment_method' => $paymentMethod,
                ':transaction_id' => $transactionId,
            ]);
            $saleId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal)
                 VALUES (:sale_id, :product_id, :quantity, :unit_price, :subtotal)'
            );
            $updateInventory = $pdo->prepare('UPDATE inventory SET quantity = quantity - ?, last_sold_date = CURDATE() WHERE id = ?');

            foreach ($items as $item) {
                $insertItem->execute([
                    ':sale_id' => $saleId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['unit_price'],
                    ':subtotal' => $item['subtotal'],
                ]);
                $updateInventory->execute([$item['quantity'], $item['inventory_id']]);
            }

            $pdo->commit();
            $itemCount = count($items);
            log_audit('create', 'sale', $saleId, null, ['store_id' => $storeId, 'items' => $itemCount, 'subtotal' => $subTotal, 'discount' => $discountAmount, 'vat' => $vatAmount, 'total' => $total, 'balance' => $balanceAmount]);
            $label = $itemCount === 1 ? "{$items[0]['quantity']} pieces × {$items[0]['name']}" : "{$itemCount} products";
            flash_set('success', "Sale recorded: {$label} for " . money($total) . '. Payment is awaiting approval.');
            redirect('sales.php');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('danger', $e->getMessage());
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Record sale failed: ' . $e->getMessage());
            flash_set('danger', 'Could not record the sale.');
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('sales.php');
}

// ---- Data -------------------------------------------------------------
$storeSql = 'SELECT id, name FROM stores WHERE status = "active"';
if (!$canViewAllStores) $storeSql .= ' AND id = ' . $assignedStoreId;
$stores = $pdo->query($storeSql . ' ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT id, name, sku, unit_price FROM products WHERE status = "active" ORDER BY name')->fetchAll();

$storeFilter = $canViewAllStores ? (int) ($_GET['store_id'] ?? 0) : $assignedStoreId;
$dateFilter = trim($_GET['sale_date'] ?? '');
$dayFilter = ucfirst(strtolower(trim($_GET['day_name'] ?? '')));
$validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$parsedDate = $dateFilter !== '' ? DateTime::createFromFormat('!Y-m-d', $dateFilter) : false;
if ($dateFilter !== '' && (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateFilter)) $dateFilter = '';
if ($dayFilter !== '' && !in_array($dayFilter, $validDays, true)) $dayFilter = '';

$sql = "SELECT s.id, s.store_id, s.subtotal_amount, s.discount_amount, s.vat_amount, s.total_amount, s.balance_amount, s.payment_method, s.payment_status, s.customer_name, s.customer_phone, s.notes, s.corrected_at, s.sale_date,
               st.name AS store_name, u.full_name AS sold_by,
               COUNT(si.id) AS item_count, COALESCE(SUM(si.quantity), 0) AS total_qty,
               GROUP_CONCAT(CONCAT(si.quantity, 'x ', p.name) ORDER BY p.name SEPARATOR ', ') AS items_summary
        FROM (
            SELECT ranked_sales.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY ranked_sales.store_id, DATE(ranked_sales.sale_date)
                       ORDER BY ranked_sales.sale_date DESC, ranked_sales.id DESC
                   ) AS daily_store_rank
            FROM sales ranked_sales
        ) s
        JOIN stores st ON st.id = s.store_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN products p ON p.id = si.product_id";
$conditions = [];
$params = [];
$conditions[] = 's.daily_store_rank <= 5';
if ($canViewAllStores && $storeFilter > 0) {
    $conditions[] = 's.store_id = :store_id';
    $params[':store_id'] = $storeFilter;
} elseif (!$canViewAllStores) {
    if ($assignedStoreId > 0) {
        $conditions[] = 's.store_id = :assigned_store';
        $params[':assigned_store'] = $assignedStoreId;
    } else {
        $conditions[] = '1 = 0';
    }
}
if ($dateFilter !== '') {
    $conditions[] = 'DATE(s.sale_date) = :sale_date';
    $params[':sale_date'] = $dateFilter;
}
if ($dayFilter !== '') {
    $conditions[] = 'DAYNAME(s.sale_date) = :day_name';
    $params[':day_name'] = $dayFilter;
}
if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
$sql .= ' GROUP BY s.id ORDER BY s.sale_date DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$saleItemsBySale = [];
if ($sales) {
    $saleIds = array_map(static fn (array $sale): int => (int) $sale['id'], $sales);
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT si.sale_id, si.quantity, si.unit_price, si.subtotal, p.name, p.sku
        FROM sale_items si JOIN products p ON p.id = si.product_id
        WHERE si.sale_id IN ({$placeholders}) ORDER BY si.sale_id, si.id");
    $itemsStmt->execute($saleIds);
    foreach ($itemsStmt->fetchAll() as $item) {
        $saleItemsBySale[(int) $item['sale_id']][] = [
            'name' => $item['name'],
            'sku' => $item['sku'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'subtotal' => (float) $item['subtotal'],
        ];
    }
}

$salesByStore = [];
$todayDate = date('Y-m-d');
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));
foreach ($sales as $sale) {
    $saleStoreId = (int) $sale['store_id'];
    $day = date('Y-m-d', strtotime($sale['sale_date']));
    if (!isset($salesByStore[$saleStoreId])) {
        $salesByStore[$saleStoreId] = [
            'name' => $sale['store_name'],
            'sales_count' => 0,
            'approved_total' => 0.0,
            'pending_count' => 0,
            'dates' => [],
        ];
    }
    if (!isset($salesByStore[$saleStoreId]['dates'][$day])) {
        $salesByStore[$saleStoreId]['dates'][$day] = [
            'label' => $day === $todayDate ? 'Today' : ($day === $yesterdayDate ? 'Yesterday' : date('l', strtotime($day))),
            'sales' => [],
            'approved_total' => 0.0,
            'pending_count' => 0,
        ];
    }
    $salesByStore[$saleStoreId]['sales_count']++;
    $salesByStore[$saleStoreId]['dates'][$day]['sales'][] = $sale;
    if ($sale['payment_status'] === 'approved') {
        $salesByStore[$saleStoreId]['approved_total'] += (float) $sale['total_amount'];
        $salesByStore[$saleStoreId]['dates'][$day]['approved_total'] += (float) $sale['total_amount'];
    } else {
        $salesByStore[$saleStoreId]['pending_count']++;
        $salesByStore[$saleStoreId]['dates'][$day]['pending_count']++;
    }
}

$salesStoreScope = !$canViewAllStores && $assignedStoreId > 0 ? ' AND store_id = ' . $assignedStoreId : (!$canViewAllStores ? ' AND 1 = 0' : '');
$todayTotal = (float) $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE payment_status = 'approved' AND DATE(sale_date) = CURDATE()" . $salesStoreScope)->fetchColumn();
$todayCount = (int) $pdo->query("SELECT COUNT(*) FROM sales WHERE payment_status = 'approved' AND DATE(sale_date) = CURDATE()" . $salesStoreScope)->fetchColumn();
$todayAverage = $todayCount > 0 ? $todayTotal / $todayCount : 0;

$last7 = $pdo->query("
    SELECT DATE(sale_date) AS d, SUM(total_amount) AS total
    FROM sales
    WHERE payment_status = 'approved' AND sale_date >= CURDATE() - INTERVAL 6 DAY" . $salesStoreScope . "
    GROUP BY DATE(sale_date)
")->fetchAll(PDO::FETCH_KEY_PAIR);
$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i day"));
    $chartLabels[] = date('D', strtotime($date));
    $chartValues[] = isset($last7[$date]) ? (float) $last7[$date] : 0;
}
$sevenDayTotal = array_sum($chartValues);

$pageTitle = 'Sales';
require __DIR__ . '/includes/header.php';
?>

<section class="sales-hero mb-4">
  <div>
    <span class="sales-overline"><i class="bi bi-bag-check me-2"></i>Sales workspace</span>
    <h2>Sell faster. Track every transaction.</h2>
    <p>Build customer orders, complete checkout, and review store performance from one workspace.</p>
  </div>
  <div class="sales-hero-date"><i class="bi bi-calendar3"></i><span><strong><?= e(date('l')) ?></strong><small><?= e(date('F j, Y')) ?></small></span></div>
</section>

<section class="sales-kpi-grid mb-4" aria-label="Sales summary">
  <div class="sales-kpi sales-kpi-revenue"><span class="sales-kpi-icon"><i class="bi bi-cash-stack"></i></span><div><span>Today's revenue</span><strong><?= e(money($todayTotal)) ?></strong><small>Completed sales today</small></div></div>
  <div class="sales-kpi sales-kpi-orders"><span class="sales-kpi-icon"><i class="bi bi-receipt"></i></span><div><span>Transactions</span><strong><?= $todayCount ?></strong><small>Receipts created today</small></div></div>
  <div class="sales-kpi sales-kpi-average"><span class="sales-kpi-icon"><i class="bi bi-calculator"></i></span><div><span>Average sale</span><strong><?= e(money($todayAverage)) ?></strong><small>Average ticket today</small></div></div>
  <div class="sales-kpi sales-kpi-week"><span class="sales-kpi-icon"><i class="bi bi-graph-up-arrow"></i></span><div><span>Seven-day sales</span><strong><?= e(money($sevenDayTotal)) ?></strong><small>Rolling revenue total</small></div></div>
</section>

<div class="row g-4 align-items-start">
  <div class="<?= $canSell ? 'col-xl-8' : 'col-12' ?>">
    <section class="sales-panel mb-4">
      <header class="sales-panel-header">
        <div><span class="sales-section-label">Revenue trend</span><h3>Sales over the last seven days</h3></div>
        <span class="sales-panel-icon"><i class="bi bi-activity"></i></span>
      </header>
      <div class="sales-chart-wrap"><canvas id="salesChart"></canvas></div>
    </section>

    <section class="sales-panel">
      <header class="sales-panel-header sales-history-header">
        <div><span class="sales-section-label">Transaction history</span><h3>Daily sales</h3><small class="sales-history-limit">Latest five transactions per store each day</small></div>
        <form method="GET" action="sales.php" class="sales-history-filters">
          <select name="store_id" class="form-select" aria-label="Filter sales by store" <?= !$canViewAllStores ? 'disabled' : '' ?>>
            <?php if ($canViewAllStores): ?><option value="0">All stores</option><?php endif; ?>
            <?php foreach ($stores as $store): ?>
            <option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="sale_date" class="form-control" value="<?= e($dateFilter) ?>" aria-label="Search sales by date">
          <select name="day_name" class="form-select" aria-label="Search sales by day name">
            <option value="">Any day</option>
            <?php foreach ($validDays as $dayName): ?><option value="<?= e($dayName) ?>" <?= $dayFilter === $dayName ? 'selected' : '' ?>><?= e($dayName) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn sales-search-button"><i class="bi bi-search"></i><span>Search</span></button>
          <?php if (($canViewAllStores && $storeFilter > 0) || $dateFilter !== '' || $dayFilter !== ''): ?><a href="sales.php" class="btn sales-clear-button" title="Clear search" aria-label="Clear sales search"><i class="bi bi-x-lg"></i></a><?php endif; ?>
        </form>
      </header>
      <div class="table-responsive sales-table-wrap">
        <table class="table sales-table align-middle mb-0">
          <thead><tr><th>Transaction</th><th>Items</th><th>Store</th><th>Total</th><th>Payment</th><th>Customer</th><th class="text-end">Action</th></tr></thead>
          <tbody>
            <?php if (!$sales): ?>
            <?php $hasSalesFilters = $dateFilter !== '' || $dayFilter !== '' || ($canViewAllStores && $storeFilter > 0); ?>
            <tr><td colspan="7"><div class="sales-empty"><i class="bi bi-receipt"></i><strong><?= $hasSalesFilters ? 'No matching sales found' : 'No sales recorded yet' ?></strong><span><?= $hasSalesFilters ? 'Try another date, day, or store.' : 'New transactions will appear here.' ?></span></div></td></tr>
            <?php else: foreach ($salesByStore as $storeSales): ?>
            <tr class="sales-store-row">
              <td colspan="7">
                <div class="sales-store-heading">
                  <div><i class="bi bi-shop"></i><strong><?= e($storeSales['name']) ?></strong></div>
                  <span><?= (int) $storeSales['sales_count'] ?> transaction<?= (int) $storeSales['sales_count'] === 1 ? '' : 's' ?> · <?= e(money($storeSales['approved_total'])) ?> approved<?php if ($storeSales['pending_count'] > 0): ?> · <?= (int) $storeSales['pending_count'] ?> pending<?php endif; ?></span>
                </div>
              </td>
            </tr>
            <?php foreach ($storeSales['dates'] as $day => $dailySales): ?>
            <tr class="sales-day-row">
              <td colspan="7">
                <div class="sales-day-heading">
                  <div><i class="bi bi-calendar3"></i><span><strong><?= e($dailySales['label']) ?></strong><small><?= e(date('F j, Y', strtotime($day))) ?></small></span></div>
                  <div class="sales-day-summary">
                    <span><?= count($dailySales['sales']) ?> transaction<?= count($dailySales['sales']) === 1 ? '' : 's' ?></span>
                    <strong><?= e(money($dailySales['approved_total'])) ?> approved</strong>
                    <?php if ($dailySales['pending_count'] > 0): ?><em><?= (int) $dailySales['pending_count'] ?> pending</em><?php endif; ?>
                  </div>
                </div>
              </td>
            </tr>
            <?php foreach ($dailySales['sales'] as $sale): ?>
            <tr>
              <td><div class="sales-transaction"><span>#<?= (int) $sale['id'] ?></span><div><strong><?= e(date('M j, Y', strtotime($sale['sale_date']))) ?></strong><small><?= e(date('g:i A', strtotime($sale['sale_date']))) ?> · <?= e($sale['sold_by']) ?></small></div></div></td>
              <td><div class="sales-items-summary" title="<?= e($sale['items_summary'] ?: 'No item details') ?>"><strong><?= e($sale['items_summary'] ?: 'No item details') ?></strong><small><?= (int) $sale['total_qty'] ?> piece<?= (int) $sale['total_qty'] === 1 ? '' : 's' ?> · <?= (int) $sale['item_count'] ?> product<?= (int) $sale['item_count'] === 1 ? '' : 's' ?></small></div></td>
              <td><span class="sales-store"><i class="bi bi-shop"></i><?= e($sale['store_name']) ?></span></td>
              <td><strong class="sales-total"><?= e(money($sale['total_amount'])) ?></strong><small class="sales-balance <?= (float) $sale['balance_amount'] > 0 ? 'due' : '' ?>">Balance <?= e(money($sale['balance_amount'])) ?></small></td>
              <td><span class="sales-payment payment-<?= e($sale['payment_method']) ?>"><?= e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></span><br><span class="sales-payment-status <?= e($sale['payment_status']) ?>"><i class="bi bi-<?= $sale['payment_status'] === 'approved' ? 'check-circle' : 'clock' ?>"></i><?= e(ucfirst($sale['payment_status'])) ?></span></td>
              <td><?= e($sale['customer_name'] ?: 'Walk-in') ?></td>
              <td class="text-end"><div class="sales-row-actions">
                <?php if ($canCorrectSale): ?>
                <button type="button" class="btn sales-edit-button" data-bs-toggle="modal" data-bs-target="#editSaleModal" data-sale-id="<?= (int) $sale['id'] ?>" data-sale-customer="<?= e($sale['customer_name'] ?? '') ?>" data-sale-phone="<?= e($sale['customer_phone'] ?? '') ?>" data-sale-payment="<?= e($sale['payment_method']) ?>" data-sale-discount="<?= e(number_format((float) $sale['discount_amount'], 2, '.', '')) ?>" data-sale-vat="<?= e(number_format((float) $sale['vat_amount'], 2, '.', '')) ?>" data-sale-subtotal="<?= e(number_format((float) $sale['subtotal_amount'], 2, '.', '')) ?>" data-sale-notes="<?= e($sale['notes'] ?? '') ?>" data-sale-items="<?= e(json_encode($saleItemsBySale[(int) $sale['id']] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" title="Correct receipt"><i class="bi bi-pencil-square"></i></button>
                <?php endif; ?>
                <?php if ($sale['payment_status'] === 'pending' && $canApprovePayment): ?>
                <form method="POST" action="sales.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="approve_payment" value="1"><input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>"><button class="btn sales-approve-button" type="submit"><i class="bi bi-check2-circle"></i>Approve</button></form>
                <?php elseif ($sale['payment_status'] === 'approved'): ?>
                <a href="receipt.php?id=<?= (int) $sale['id'] ?>" target="_blank" class="btn sales-receipt-button" title="View or print receipt"><i class="bi bi-receipt"></i></a>
                <?php else: ?><span class="text-muted small">Awaiting approval</span><?php endif; ?>
                <?php if (is_role('admin')): ?>
                <form method="POST" action="sales.php" class="d-inline" data-confirm="Delete sale #<?= (int) $sale['id'] ?>? Its sold quantities will be restored to <?= e($sale['store_name']) ?> inventory.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="delete_sale" value="1">
                  <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                  <button class="btn sales-delete-button" type="submit" title="Delete sale"><i class="bi bi-trash3"></i></button>
                </form>
                <?php endif; ?>
              </div></td>
            </tr>
            <?php endforeach; endforeach; endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <?php if ($canSell): ?>
  <div class="col-xl-4">
    <section class="sales-checkout-panel">
      <header class="sales-checkout-header">
        <span class="sales-checkout-icon"><i class="bi bi-cart3"></i></span>
        <div><span class="sales-section-label">Point of sale</span><h3>New sale</h3></div>
      </header>
      <form method="POST" action="sales.php" id="saleForm">
        <?= csrf_field() ?>
        <input type="hidden" name="add_sale" value="1">
        <div class="sales-checkout-body">
          <div class="row g-3 mb-3">
            <div class="col-sm-6 col-xl-12 col-xxl-6">
              <label class="form-label">Store</label>
              <select class="form-select" name="store_id" required>
                <?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6 col-xl-12 col-xxl-6">
              <label class="form-label">Payment</label>
              <select class="form-select" name="payment_method" required>
                <option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="card">Card</option><option value="credit">Credit</option>
              </select>
            </div>
          </div>

          <div class="sales-product-picker mb-3">
            <label class="form-label">Add products</label>
            <div class="sales-product-search"><i class="bi bi-search"></i><input type="search" class="form-control" id="saleProductSearch" placeholder="Search by product or SKU" autocomplete="off"></div>
            <div id="saleProductResults" class="list-group sales-search-results"></div>
          </div>

          <div class="sales-cart">
            <div class="sales-cart-heading"><span>Order items</span><small id="saleItemCount">0 items</small></div>
            <div class="table-responsive">
              <table class="table sales-cart-table align-middle mb-0" id="saleItemsTable">
                <thead><tr><th>Product</th><th>Qty</th><th class="text-end">Total</th><th></th></tr></thead>
                <tbody id="saleItemsBody"></tbody>
              </table>
            </div>
            <div id="saleItemsEmpty" class="sales-cart-empty"><i class="bi bi-cart-plus"></i><span>Search above to add products</span></div>
          </div>

          <div class="sales-payment-details">
            <span class="sales-customer-label"><i class="bi bi-calculator me-2"></i>Payment details</span>
            <div class="row g-2">
              <div class="col-6"><label class="form-label" for="saleDiscount">Discount</label><input type="number" class="form-control" name="discount_amount" id="saleDiscount" min="0" step="0.01" value="0.00" required></div>
              <div class="col-6"><label class="form-label" for="saleVat">VAT</label><input type="number" class="form-control" name="vat_amount" id="saleVat" min="0" step="0.01" value="0.00" required></div>
            </div>
            <div class="sales-payment-summary">
              <span>Subtotal <strong id="saleSubtotal"><?= e(CURRENCY_SYMBOL) ?>0.00</strong></span>
              <span>Discount <strong id="saleDiscountDisplay"><?= e(CURRENCY_SYMBOL) ?>0.00</strong></span>
              <span>VAT <strong id="saleVatDisplay"><?= e(CURRENCY_SYMBOL) ?>0.00</strong></span>
              <span>Balance <strong id="saleBalance"><?= e(CURRENCY_SYMBOL) ?>0.00</strong></span>
            </div>
          </div>

          <div class="sales-customer-fields">
            <span class="sales-customer-label"><i class="bi bi-person me-2"></i>Customer details <small>optional</small></span>
            <div class="row g-2">
              <div class="col-sm-6 col-xl-12 col-xxl-6"><input type="text" class="form-control" name="customer_name" placeholder="Customer name"></div>
              <div class="col-sm-6 col-xl-12 col-xxl-6"><input type="tel" class="form-control" name="customer_phone" placeholder="Phone number"></div>
            </div>
          </div>
        </div>
        <footer class="sales-checkout-footer">
          <div><span>Amount due</span><strong id="saleGrandTotal"><?= e(CURRENCY_SYMBOL) ?>0.00</strong></div>
          <button type="submit" class="btn sales-complete-button"><i class="bi bi-check2-circle me-2"></i>Complete sale</button>
        </footer>
      </form>
    </section>
  </div>
  <?php endif; ?>
</div>

<?php if ($canCorrectSale): ?>
<div class="modal fade" id="editSaleModal" tabindex="-1" aria-labelledby="editSaleTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content sales-edit-modal"><form method="POST" action="sales.php">
    <?= csrf_field() ?><input type="hidden" name="edit_sale" value="1"><input type="hidden" name="sale_id" id="editSaleId">
    <div class="modal-header"><div><h5 class="modal-title" id="editSaleTitle"><i class="bi bi-receipt-cutoff me-2"></i>Correct sales receipt</h5><small>Update receipt details while preserving the original products and stock movement.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body p-4">
      <div class="sales-edit-notice"><i class="bi bi-shield-check"></i><div><strong>Corrections are audited</strong><small>The administrator or manager making this change, the previous values, and the corrected values will be recorded.</small></div></div>
      <div class="row g-3 mt-1">
        <div class="col-md-6"><label class="form-label" for="editSaleCustomer">Customer name</label><input type="text" class="form-control" name="customer_name" id="editSaleCustomer" maxlength="100"></div>
        <div class="col-md-6"><label class="form-label" for="editSalePhone">Customer phone</label><input type="tel" class="form-control" name="customer_phone" id="editSalePhone" maxlength="20"></div>
        <div class="col-md-4"><label class="form-label" for="editSalePayment">Payment method</label><select class="form-select" name="payment_method" id="editSalePayment" required><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="card">Card</option><option value="credit">Credit</option></select></div>
        <div class="col-md-4"><label class="form-label" for="editSaleDiscount">Discount</label><input type="number" class="form-control" name="discount_amount" id="editSaleDiscount" min="0" step="0.01" required></div>
        <div class="col-md-4"><label class="form-label" for="editSaleVat">VAT</label><input type="number" class="form-control" name="vat_amount" id="editSaleVat" min="0" step="0.01" required></div>
        <div class="col-12">
          <div class="sales-edit-items">
        <div class="sales-edit-items-heading"><span><i class="bi bi-basket2"></i>Receipt items</span><small id="editSaleItemCount"></small></div>
            <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Item</th><th class="text-center">Pieces</th><th class="text-end">Price per piece</th><th class="text-end">Line total</th></tr></thead><tbody id="editSaleItemsBody"></tbody></table></div>
            <div class="sales-edit-items-empty" id="editSaleItemsEmpty">No item details are available for this receipt.</div>
          </div>
        </div>
        <div class="col-12"><div class="sales-edit-total"><span>Receipt subtotal</span><strong id="editSaleSubtotal"></strong><small>The final total is recalculated from this subtotal, discount, and VAT.</small></div></div>
        <div class="col-12"><label class="form-label" for="editSaleNotes">Correction note</label><textarea class="form-control" name="notes" id="editSaleNotes" rows="3" maxlength="1000" placeholder="Describe the correction for future reference"></textarea></div>
      </div>
    </div>
    <div class="modal-footer border-0 px-4 pb-4 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn sales-save-correction"><i class="bi bi-check2-circle me-2"></i>Save correction</button></div>
  </form></div></div>
</div>
<?php endif; ?>

<?php
$extraScripts = '<script>
  const saleProducts = ' . json_encode(array_map(static function ($product) {
        return ['id' => (int) $product['id'], 'name' => $product['name'], 'sku' => $product['sku'], 'price' => (float) $product['unit_price']];
    }, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
  const currencySymbol = ' . json_encode(CURRENCY_SYMBOL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
  const saleItemsBody = document.getElementById("saleItemsBody");
  const saleItemsEmpty = document.getElementById("saleItemsEmpty");
  const saleGrandTotal = document.getElementById("saleGrandTotal");
  const saleItemCount = document.getElementById("saleItemCount");
  const saleSubtotal = document.getElementById("saleSubtotal");
  const saleDiscount = document.getElementById("saleDiscount");
  const saleVat = document.getElementById("saleVat");
  const saleDiscountDisplay = document.getElementById("saleDiscountDisplay");
  const saleVatDisplay = document.getElementById("saleVatDisplay");
  const saleBalance = document.getElementById("saleBalance");
  const saleProductSearch = document.getElementById("saleProductSearch");
  const saleProductResults = document.getElementById("saleProductResults");
  const saleForm = document.getElementById("saleForm");
  const editSaleModal = document.getElementById("editSaleModal");

  if (editSaleModal) {
    editSaleModal.addEventListener("show.bs.modal", function (event) {
      const button = event.relatedTarget;
      if (!button) return;
      document.getElementById("editSaleId").value = button.dataset.saleId || "";
      document.getElementById("editSaleCustomer").value = button.dataset.saleCustomer || "";
      document.getElementById("editSalePhone").value = button.dataset.salePhone || "";
      document.getElementById("editSalePayment").value = button.dataset.salePayment || "cash";
      document.getElementById("editSaleDiscount").value = button.dataset.saleDiscount || "0.00";
      document.getElementById("editSaleVat").value = button.dataset.saleVat || "0.00";
      document.getElementById("editSaleNotes").value = button.dataset.saleNotes || "";
      document.getElementById("editSaleSubtotal").textContent = currencySymbol + (parseFloat(button.dataset.saleSubtotal) || 0).toFixed(2);
      document.getElementById("editSaleTitle").innerHTML = "<i class=\"bi bi-receipt-cutoff me-2\"></i>Correct receipt #" + (button.dataset.saleId || "");
      let items = [];
      try { items = JSON.parse(button.dataset.saleItems || "[]"); } catch (error) { items = []; }
      const itemsBody = document.getElementById("editSaleItemsBody");
      const itemsEmpty = document.getElementById("editSaleItemsEmpty");
      const itemCount = document.getElementById("editSaleItemCount");
      itemsBody.innerHTML = items.map(function (item) {
        return "<tr><td><strong>" + escapeHtml(item.name) + "</strong><small>" + escapeHtml(item.sku) + "</small></td>" +
          "<td class=\"text-center\">" + Number(item.quantity) + " pieces</td>" +
          "<td class=\"text-end\">" + currencySymbol + Number(item.unit_price).toFixed(2) + "</td>" +
          "<td class=\"text-end fw-semibold\">" + currencySymbol + Number(item.subtotal).toFixed(2) + "</td></tr>";
      }).join("");
      const totalUnits = items.reduce(function (sum, item) { return sum + Number(item.quantity || 0); }, 0);
      itemCount.textContent = items.length + (items.length === 1 ? " product" : " products") + " · " + totalUnits + (totalUnits === 1 ? " piece" : " pieces");
      itemsEmpty.style.display = items.length ? "none" : "block";
    });
  }

  function escapeHtml(value) {
    const element = document.createElement("div");
    element.textContent = String(value);
    return element.innerHTML;
  }

  function rowSubtotal(row) {
    const price = parseFloat(row.getAttribute("data-price")) || 0;
    const quantityInput = row.querySelector("input[name=\"quantity[]\"]");
    const quantity = parseInt(quantityInput.value, 10) || 0;
    const subtotal = price * quantity;
    row.querySelector(".sale-line-total").textContent = currencySymbol + subtotal.toFixed(2);
    return subtotal;
  }

  function recalcGrandTotal() {
    if (!saleItemsBody) return;
    const rows = saleItemsBody.querySelectorAll("tr");
    let total = 0;
    rows.forEach(function (row) { total += rowSubtotal(row); });
    const discount = Math.max(parseFloat(saleDiscount.value) || 0, 0);
    const vat = Math.max(parseFloat(saleVat.value) || 0, 0);
    const grandTotal = Math.max(total - discount + vat, 0);
    const balance = grandTotal;
    saleSubtotal.textContent = currencySymbol + total.toFixed(2);
    saleDiscountDisplay.textContent = currencySymbol + discount.toFixed(2);
    saleVatDisplay.textContent = currencySymbol + vat.toFixed(2);
    saleGrandTotal.textContent = currencySymbol + grandTotal.toFixed(2);
    saleBalance.textContent = currencySymbol + balance.toFixed(2);
    saleBalance.classList.toggle("due", balance > 0);
    saleItemsEmpty.style.display = rows.length ? "none" : "flex";
    saleItemCount.textContent = rows.length + (rows.length === 1 ? " item" : " items");
  }

  function addProductToSale(product) {
    const existing = saleItemsBody.querySelector("tr[data-product-id=\"" + product.id + "\"]");
    if (existing) {
      const quantityInput = existing.querySelector("input[name=\"quantity[]\"]");
      quantityInput.value = (parseInt(quantityInput.value, 10) || 0) + 1;
      recalcGrandTotal();
      return;
    }

    const row = document.createElement("tr");
    row.setAttribute("data-product-id", product.id);
    row.setAttribute("data-price", product.price);
    row.innerHTML =
      "<td><div class=\"sales-cart-product\"><strong>" + escapeHtml(product.name) + "</strong><small>" + escapeHtml(product.sku) + " · " + currencySymbol + product.price.toFixed(2) + " per piece</small></div>" +
      "<input type=\"hidden\" name=\"product_id[]\" value=\"" + product.id + "\"></td>" +
      "<td><input type=\"number\" class=\"form-control sales-quantity-input\" name=\"quantity[]\" min=\"1\" value=\"1\" required></td>" +
      "<td class=\"sale-line-total text-end\">" + currencySymbol + "0.00</td>" +
      "<td class=\"text-end\"><button type=\"button\" class=\"btn remove-sale-item\" title=\"Remove\"><i class=\"bi bi-x-lg\"></i></button></td>";
    saleItemsBody.appendChild(row);
    row.querySelector("input[name=\"quantity[]\"]").addEventListener("input", recalcGrandTotal);
    row.querySelector(".remove-sale-item").addEventListener("click", function () { row.remove(); recalcGrandTotal(); });
    recalcGrandTotal();
  }

  function renderSearchResults(query) {
    const normalized = query.trim().toLowerCase();
    if (!normalized) {
      saleProductResults.style.display = "none";
      saleProductResults.innerHTML = "";
      return;
    }
    const matches = saleProducts.filter(function (product) {
      return product.name.toLowerCase().includes(normalized) || product.sku.toLowerCase().includes(normalized);
    }).slice(0, 8);

    if (!matches.length) {
      saleProductResults.innerHTML = "<div class=\"sales-search-empty\">No matching products</div>";
      saleProductResults.style.display = "block";
      return;
    }
    saleProductResults.innerHTML = matches.map(function (product) {
      return "<button type=\"button\" class=\"sale-search-result\" data-id=\"" + product.id + "\">" +
        "<span><strong>" + escapeHtml(product.name) + "</strong><small>" + escapeHtml(product.sku) + "</small></span>" +
        "<b>" + currencySymbol + product.price.toFixed(2) + "</b></button>";
    }).join("");
    saleProductResults.style.display = "block";
    saleProductResults.querySelectorAll(".sale-search-result").forEach(function (button) {
      button.addEventListener("click", function () {
        const product = saleProducts.find(function (item) { return String(item.id) === button.getAttribute("data-id"); });
        if (product) addProductToSale(product);
        saleProductSearch.value = "";
        saleProductResults.style.display = "none";
        saleProductResults.innerHTML = "";
        saleProductSearch.focus();
      });
    });
  }

  if (saleProductSearch) {
    saleProductSearch.addEventListener("input", function () { renderSearchResults(this.value); });
    saleProductSearch.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        const firstResult = saleProductResults.querySelector(".sale-search-result");
        if (firstResult) firstResult.click();
      }
    });
    document.addEventListener("click", function (event) {
      if (!saleProductSearch.contains(event.target) && !saleProductResults.contains(event.target)) saleProductResults.style.display = "none";
    });
  }

  if (saleForm) {
    [saleDiscount, saleVat].forEach(function (input) { input.addEventListener("input", recalcGrandTotal); });
    saleForm.addEventListener("submit", function (event) {
      if (!saleItemsBody.querySelectorAll("tr").length) {
        event.preventDefault();
        alert("Add at least one product to the sale.");
      }
    });
  }

  const salesCanvas = document.getElementById("salesChart");
  if (salesCanvas) {
    new Chart(salesCanvas.getContext("2d"), {
      type: "line",
      data: {
        labels: ' . json_encode($chartLabels) . ',
        datasets: [{
          label: "Sales (' . e(CURRENCY_CODE) . ')",
          data: ' . json_encode($chartValues) . ',
          borderColor: "#167003",
          backgroundColor: "rgba(22, 112, 3, 0.1)",
          pointBackgroundColor: "#fff",
          pointBorderColor: "#167003",
          pointBorderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 6,
          fill: true,
          tension: 0.38
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: "index" },
        plugins: { legend: { display: false }, tooltip: { backgroundColor: "#17351d", padding: 12, displayColors: false } },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { color: "#747b76" } },
          y: { beginAtZero: true, border: { display: false }, grid: { color: "rgba(80, 100, 84, 0.08)" }, ticks: { color: "#8a908b" } }
        }
      }
    });
  }
</script>';

require __DIR__ . '/includes/footer.php';
?>
