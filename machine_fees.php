<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager', 'cashier');

$pdo = db();
$canViewAllStores = is_role('admin', 'manager');
$assignedStoreId = $canViewAllStores ? 0 : (int) (current_user()['store_id'] ?? 0);
$availableServices = [
    'sawing' => 'Sawing',
    'planning' => 'Planning',
    'design' => 'Design',
    'shooting' => 'Shooting',
    'facial_board' => 'Facial Board',
];

if (is_post() && isset($_POST['delete_machine_fee'])) {
    require_role('admin');
    verify_csrf();

    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    if ($paymentId <= 0) {
        flash_set('danger', 'Invalid machine fee payment.');
        redirect('machine_fees.php');
    }

    try {
        $pdo->beginTransaction();
        $paymentStmt = $pdo->prepare(
            'SELECT transaction_id, store_id, services, payer_name, amount, payment_method, paid_at
             FROM machine_fee_payments WHERE id = ? FOR UPDATE'
        );
        $paymentStmt->execute([$paymentId]);
        $payment = $paymentStmt->fetch();
        if (!$payment) throw new RuntimeException('Machine fee payment not found.');

        $pdo->prepare('DELETE FROM machine_fee_payments WHERE id = ?')->execute([$paymentId]);
        $pdo->commit();

        log_audit('delete', 'machine_fee', $paymentId, [
            'transaction_id' => $payment['transaction_id'],
            'store_id' => (int) $payment['store_id'],
            'services' => $payment['services'],
            'payer_name' => $payment['payer_name'],
            'amount' => (float) $payment['amount'],
            'payment_method' => $payment['payment_method'],
            'paid_at' => $payment['paid_at'],
        ], null);
        flash_set('success', 'Machine fee payment deleted successfully.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Delete machine fee failed: ' . $e->getMessage());
        flash_set('danger', 'Could not delete the machine fee payment.');
    }
    redirect('machine_fees.php');
}

if (is_post() && isset($_POST['register_machine_fee'])) {
    verify_csrf();

    $storeId = (int) ($_POST['store_id'] ?? 0);
    $rawServiceKeys = is_array($_POST['services'] ?? null) ? $_POST['services'] : [];
    $invalidServiceShape = count(array_filter($rawServiceKeys, 'is_string')) !== count($rawServiceKeys);
    $selectedServiceKeys = array_values(array_unique(array_filter($rawServiceKeys, 'is_string')));
    $payerName = trim($_POST['payer_name'] ?? '');
    $payerPhone = trim($_POST['payer_phone'] ?? '');
    $amountInput = $_POST['amount'] ?? '';
    $paymentMethod = $_POST['payment_method'] ?? '';
    $errors = validate($_POST, [
        'store_id' => 'required|int',
        'payer_name' => 'required',
        'amount' => 'required|numeric|positive',
        'payment_method' => 'required',
    ]);

    if ((float) $amountInput <= 0) $errors[] = 'Amount must be greater than zero.';
    if (!in_array($paymentMethod, ['cash', 'mobile_money', 'card'], true)) $errors[] = 'Invalid payment method.';
    if (!$selectedServiceKeys) $errors[] = 'Select at least one service.';
    if ($invalidServiceShape) $errors[] = 'One or more selected services are invalid.';
    $selectedServices = [];
    foreach ($selectedServiceKeys as $serviceKey) {
        if (!is_string($serviceKey) || !array_key_exists($serviceKey, $availableServices)) {
            $errors[] = 'One or more selected services are invalid.';
            break;
        }
        $selectedServices[] = $availableServices[$serviceKey];
    }
    $servicesValue = implode(', ', $selectedServices);
    if (strlen($payerName) > 100) $errors[] = 'Payer name is too long.';
    if (strlen($payerPhone) > 20) $errors[] = 'Payer phone is too long.';
    if (!$canViewAllStores && ($assignedStoreId <= 0 || $storeId !== $assignedStoreId)) {
        $errors[] = 'You can register machine fees only for your assigned store.';
    }

    if (!$errors) {
        $storeStmt = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND status = 'active'");
        $storeStmt->execute([$storeId]);
        if (!$storeStmt->fetchColumn()) $errors[] = 'Select an active store.';
    }

    if (!$errors) {
        try {
            $transactionId = 'MFE-' . date('Ymd-His') . '-' . random_int(100, 999);
            $stmt = $pdo->prepare(
                'INSERT INTO machine_fee_payments
                    (store_id, collected_by, services, payer_name, payer_phone, amount, payment_method, transaction_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $storeId,
                (int) current_user()['id'],
                $servicesValue,
                $payerName,
                $payerPhone !== '' ? $payerPhone : null,
                round((float) $amountInput, 2),
                $paymentMethod,
                $transactionId,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            log_audit('create', 'machine_fee', $paymentId, null, [
                'transaction_id' => $transactionId,
                'store_id' => $storeId,
                'services' => $selectedServices,
                'payer_name' => $payerName,
                'amount' => round((float) $amountInput, 2),
                'payment_method' => $paymentMethod,
            ]);
            redirect('machine_fee_receipt.php?id=' . $paymentId . '&created=1');
        } catch (PDOException $e) {
            error_log('Register machine fee failed: ' . $e->getMessage());
            flash_set('danger', 'Could not register the machine fee payment.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('machine_fees.php');
}

$storeSql = "SELECT id, name FROM stores WHERE status = 'active'";
if (!$canViewAllStores) $storeSql .= ' AND id = ' . $assignedStoreId;
$stores = $pdo->query($storeSql . ' ORDER BY name')->fetchAll();

$search = trim($_GET['q'] ?? '');
$dateFilter = trim($_GET['paid_date'] ?? '');
$storeFilter = $canViewAllStores ? (int) ($_GET['store_id'] ?? 0) : $assignedStoreId;
$parsedDate = $dateFilter !== '' ? DateTime::createFromFormat('!Y-m-d', $dateFilter) : false;
if ($dateFilter !== '' && (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateFilter)) $dateFilter = '';

$conditions = [];
$params = [];
if ($canViewAllStores && $storeFilter > 0) {
    $conditions[] = 'm.store_id = :store_id';
    $params[':store_id'] = $storeFilter;
} elseif (!$canViewAllStores) {
    if ($assignedStoreId > 0) {
        $conditions[] = 'm.store_id = :assigned_store';
        $params[':assigned_store'] = $assignedStoreId;
    } else {
        $conditions[] = '1 = 0';
    }
}
if ($search !== '') {
    $conditions[] = '(m.transaction_id LIKE :query_transaction OR m.services LIKE :query_services OR m.payer_name LIKE :query_payer OR m.payer_phone LIKE :query_phone)';
    $params[':query_transaction'] = '%' . $search . '%';
    $params[':query_services'] = '%' . $search . '%';
    $params[':query_payer'] = '%' . $search . '%';
    $params[':query_phone'] = '%' . $search . '%';
}
if ($dateFilter !== '') {
    $conditions[] = 'DATE(m.paid_at) = :paid_date';
    $params[':paid_date'] = $dateFilter;
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$sql = "SELECT m.*, s.name AS store_name, u.full_name AS collected_by_name
        FROM machine_fee_payments m
        JOIN stores s ON s.id = m.store_id
        JOIN users u ON u.id = m.collected_by" . $where . '
        ORDER BY s.name, m.paid_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$metricScope = !$canViewAllStores && $assignedStoreId > 0 ? ' AND store_id = ' . $assignedStoreId : (!$canViewAllStores ? ' AND 1 = 0' : '');
$todayAmount = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM machine_fee_payments WHERE DATE(paid_at) = CURDATE()" . $metricScope)->fetchColumn();
$todayCount = (int) $pdo->query("SELECT COUNT(*) FROM machine_fee_payments WHERE DATE(paid_at) = CURDATE()" . $metricScope)->fetchColumn();
$totalAmount = array_sum(array_map(static fn (array $payment): float => (float) $payment['amount'], $payments));

$pageTitle = 'Machine Fees';
require __DIR__ . '/includes/header.php';
?>

<section class="fee-hero mb-4">
  <div><span class="fee-overline"><i class="bi bi-gear-wide-connected me-2"></i>Machine payments</span><h2>Register machine fees and issue receipts.</h2><p>Record paid machine charges, track collections by store, and print a receipt immediately.</p></div>
  <div class="fee-hero-total"><span>Collected today</span><strong><?= e(money($todayAmount)) ?></strong><small><?= $todayCount ?> payment<?= $todayCount === 1 ? '' : 's' ?></small></div>
</section>

<div class="row g-4 align-items-start">
  <div class="col-xl-8">
    <section class="fee-panel">
      <header class="fee-panel-header"><div><span class="fee-section-label">Payment register</span><h3>Machine fee history</h3></div><span class="fee-result-count"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?> · <?= e(money($totalAmount)) ?></span></header>
      <form class="fee-filters" method="GET" action="machine_fees.php">
        <div class="fee-search"><i class="bi bi-search"></i><input type="search" class="form-control" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Receipt, service, payer, or phone"></div>
        <select class="form-select" name="store_id" <?= !$canViewAllStores ? 'disabled' : '' ?> aria-label="Filter by store"><?php if ($canViewAllStores): ?><option value="0">All stores</option><?php endif; ?><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select>
        <input type="date" class="form-control" name="paid_date" value="<?= e($dateFilter) ?>" aria-label="Filter by payment date">
        <button class="btn fee-filter-button" type="submit"><i class="bi bi-search"></i><span>Search</span></button>
        <?php if ($search !== '' || $dateFilter !== '' || ($canViewAllStores && $storeFilter > 0)): ?><a class="btn fee-clear-button" href="machine_fees.php" title="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </form>
      <div class="table-responsive fee-table-wrap"><table class="table fee-table align-middle mb-0"><thead><tr><th>Receipt</th><th>Services</th><th>Payer</th><th>Store</th><th>Amount</th><th>Collected by</th><th class="text-end">Action</th></tr></thead><tbody>
        <?php if (!$payments): ?><tr><td colspan="7"><div class="fee-empty"><i class="bi bi-receipt"></i><strong>No machine fees found</strong><span>Register a payment or adjust the filters.</span></div></td></tr>
        <?php else: $currentStoreId = null; foreach ($payments as $payment): ?>
        <?php if ($currentStoreId !== (int) $payment['store_id']): $currentStoreId = (int) $payment['store_id']; ?><tr class="fee-store-row"><td colspan="7"><i class="bi bi-shop"></i><strong><?= e($payment['store_name']) ?></strong></td></tr><?php endif; ?>
        <tr><td><div class="fee-reference"><strong><?= e($payment['transaction_id']) ?></strong><small><?= e(date('M j, Y · g:i A', strtotime($payment['paid_at']))) ?></small></div></td><td><strong><?= e($payment['services']) ?></strong></td><td><div class="fee-payer"><strong><?= e($payment['payer_name']) ?></strong><small><?= e($payment['payer_phone'] ?: 'No phone') ?></small></div></td><td><span class="fee-store"><i class="bi bi-shop"></i><?= e($payment['store_name']) ?></span></td><td><strong class="fee-amount"><?= e(money($payment['amount'])) ?></strong><small class="fee-method"><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></small></td><td><?= e($payment['collected_by_name']) ?></td><td class="text-end"><div class="fee-row-actions"><a class="btn fee-receipt-button" href="machine_fee_receipt.php?id=<?= (int) $payment['id'] ?>" target="_blank" title="View receipt"><i class="bi bi-receipt"></i></a><?php if (is_role('admin')): ?><form method="POST" action="machine_fees.php" data-confirm="Delete receipt <?= e($payment['transaction_id']) ?>? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="delete_machine_fee" value="1"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><button class="btn fee-delete-button" type="submit" title="Delete payment"><i class="bi bi-trash3"></i></button></form><?php endif; ?></div></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </section>
  </div>

  <div class="col-xl-4">
    <section class="fee-form-panel">
      <header><span><i class="bi bi-cash-coin"></i></span><div><span class="fee-section-label">New payment</span><h3>Register machine fee</h3></div></header>
      <form method="POST" action="machine_fees.php">
        <?= csrf_field() ?><input type="hidden" name="register_machine_fee" value="1">
        <div class="fee-form-body">
          <div class="mb-3"><label class="form-label">Store</label><select class="form-select" name="store_id" required><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?></select></div>
          <fieldset class="mb-3"><legend class="form-label">Services <span>(select one or more)</span></legend><div class="fee-service-grid">
            <?php foreach ($availableServices as $serviceKey => $serviceLabel): ?>
            <label class="fee-service-option"><input type="checkbox" name="services[]" value="<?= e($serviceKey) ?>"><span><i class="bi bi-check2"></i><?= e($serviceLabel) ?></span></label>
            <?php endforeach; ?>
          </div></fieldset>
          <div class="row g-2 mb-3"><div class="col-7"><label class="form-label">Payer name</label><input type="text" class="form-control" name="payer_name" maxlength="100" required></div><div class="col-5"><label class="form-label">Phone</label><input type="tel" class="form-control" name="payer_phone" maxlength="20"></div></div>
          <div class="row g-2"><div class="col-6"><label class="form-label">Amount</label><div class="input-group"><span class="input-group-text"><?= e(CURRENCY_CODE) ?></span><input type="number" class="form-control" name="amount" min="0.01" step="0.01" required></div></div><div class="col-6"><label class="form-label">Payment method</label><select class="form-select" name="payment_method" required><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="card">Card</option></select></div></div>
        </div>
        <footer><div><span>Receipt</span><strong>Generated after saving</strong></div><button class="btn fee-save-button" type="submit" <?= !$stores ? 'disabled' : '' ?>><i class="bi bi-check2-circle"></i>Register payment</button></footer>
      </form>
    </section>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
