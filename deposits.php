<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager', 'mini_manager', 'cashier', 'sales_clerk');

$pdo = db();
$canViewAllStores = is_role('admin', 'manager');
$assignedStoreId = $canViewAllStores ? 0 : (int) (current_user()['store_id'] ?? 0);
$methods = ['cash' => 'Cash', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque'];

function require_deposit_store_access(array $deposit, bool $canViewAllStores, int $assignedStoreId): void
{
    if (!$canViewAllStores && ($assignedStoreId <= 0 || (int) $deposit['store_id'] !== $assignedStoreId)) {
        throw new RuntimeException('You can manage deposit orders only for your assigned store.');
    }
}

// Create a deposit order. Inventory is checked for guidance but is not changed.
if (is_post() && isset($_POST['create_deposit'])) {
    verify_csrf();
    $storeId = (int) ($_POST['store_id'] ?? 0);
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $initialPaymentInput = trim((string) ($_POST['initial_payment'] ?? '0'));
    $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
    $rawProductIds = $_POST['product_id'] ?? [];
    $rawQuantities = $_POST['quantity'] ?? [];
    $errors = validate($_POST, ['store_id' => 'required|int', 'customer_name' => 'required', 'initial_payment' => 'required|numeric|positive']);
    if (!$canViewAllStores && ($assignedStoreId <= 0 || $storeId !== $assignedStoreId)) $errors[] = 'Select your assigned store.';
    if (strlen($customerName) > 100) $errors[] = 'Customer name is too long.';
    if (strlen($customerPhone) > 20) $errors[] = 'Customer phone is too long.';
    if (!array_key_exists($paymentMethod, $methods)) $errors[] = 'Select a valid payment method.';
    if (!is_array($rawProductIds) || !is_array($rawQuantities)) $errors[] = 'Invalid item list.';

    $lines = [];
    if (!$errors) {
        $rowCount = max(count($rawProductIds), count($rawQuantities));
        for ($i = 0; $i < $rowCount; $i++) {
            $productId = (int) ($rawProductIds[$i] ?? 0);
            $quantity = (int) ($rawQuantities[$i] ?? 0);
            if ($productId > 0 && $quantity > 0) $lines[$productId] = ($lines[$productId] ?? 0) + $quantity;
        }
        if (!$lines) $errors[] = 'Add at least one item to the deposit order.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $storeStmt = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND status = 'active'");
            $storeStmt->execute([$storeId]);
            if (!$storeStmt->fetchColumn()) throw new RuntimeException('Select an active store.');

            ksort($lines);
            $itemStmt = $pdo->prepare('SELECT p.id, p.name, p.unit_price FROM products p WHERE p.id = ? AND p.status = "active"');
            $items = [];
            $total = 0.0;
            foreach ($lines as $productId => $quantity) {
                $itemStmt->execute([$productId]);
                $product = $itemStmt->fetch();
                if (!$product) throw new RuntimeException('One of the selected products is unavailable.');
                $subtotal = round((float) $product['unit_price'] * $quantity, 2);
                $total += $subtotal;
                $items[] = ['product_id' => $productId, 'quantity' => $quantity, 'unit_price' => (float) $product['unit_price'], 'subtotal' => $subtotal];
            }
            $total = round($total, 2);
            $initialPayment = round((float) $initialPaymentInput, 2);
            if ($initialPayment > $total) throw new RuntimeException('Initial payment cannot exceed the order total of ' . money($total) . '.');
            $balance = round($total - $initialPayment, 2);
            $status = $balance <= 0 ? 'paid' : 'pending';
            $depositNumber = 'DEP-' . date('Ymd-His') . '-' . random_int(100, 999);

            $insert = $pdo->prepare('INSERT INTO deposits (store_id, created_by, deposit_number, customer_name, customer_phone, total_amount, paid_amount, balance_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$storeId, (int) current_user()['id'], $depositNumber, $customerName, $customerPhone ?: null, $total, $initialPayment, $balance, $status]);
            $depositId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare('INSERT INTO deposit_items (deposit_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)');
            foreach ($items as $item) $insertItem->execute([$depositId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            if ($initialPayment > 0) {
                $pdo->prepare('INSERT INTO deposit_payments (deposit_id, received_by, amount, payment_method) VALUES (?, ?, ?, ?)')
                    ->execute([$depositId, (int) current_user()['id'], $initialPayment, $paymentMethod]);
            }
            $pdo->commit();
            log_audit('create', 'deposit', $depositId, null, ['deposit_number' => $depositNumber, 'customer' => $customerName, 'total' => $total, 'paid' => $initialPayment, 'balance' => $balance, 'items' => count($items)]);
            flash_set('success', 'Deposit order ' . $depositNumber . ' created. Inventory will change only when the customer collects the items.');
            redirect('deposit_receipt.php?id=' . $depositId . '&created=1');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('warning', $e->getMessage());
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Create deposit order failed: ' . $e->getMessage());
            flash_set('danger', 'Could not create the deposit order.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('deposits.php');
}

// Accept another instalment without moving inventory.
if (is_post() && isset($_POST['add_payment'])) {
    verify_csrf();
    $depositId = (int) ($_POST['deposit_id'] ?? 0);
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $method = (string) ($_POST['payment_method'] ?? '');
    try {
        if ($amount <= 0) throw new RuntimeException('Payment must be greater than zero.');
        if (!array_key_exists($method, $methods)) throw new RuntimeException('Select a valid payment method.');
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE id = ? FOR UPDATE');
        $stmt->execute([$depositId]);
        $deposit = $stmt->fetch();
        if (!$deposit) throw new RuntimeException('Deposit order not found.');
        require_deposit_store_access($deposit, $canViewAllStores, $assignedStoreId);
        if (!in_array($deposit['status'], ['pending', 'paid'], true)) throw new RuntimeException('This order can no longer receive payments.');
        if ($amount > (float) $deposit['balance_amount']) throw new RuntimeException('Payment cannot exceed the balance of ' . money($deposit['balance_amount']) . '.');
        $paid = round((float) $deposit['paid_amount'] + $amount, 2);
        $balance = round((float) $deposit['total_amount'] - $paid, 2);
        $status = $balance <= 0 ? 'paid' : 'pending';
        $pdo->prepare('INSERT INTO deposit_payments (deposit_id, received_by, amount, payment_method) VALUES (?, ?, ?, ?)')->execute([$depositId, (int) current_user()['id'], $amount, $method]);
        $pdo->prepare('UPDATE deposits SET paid_amount = ?, balance_amount = ?, status = ? WHERE id = ?')->execute([$paid, max(0, $balance), $status, $depositId]);
        $pdo->commit();
        log_audit('payment', 'deposit', $depositId, ['balance' => (float) $deposit['balance_amount']], ['amount' => $amount, 'balance' => max(0, $balance)]);
        flash_set('success', 'Payment applied to ' . $deposit['deposit_number'] . '.');
        redirect('deposit_receipt.php?id=' . $depositId . '&payment=1');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Deposit payment failed: ' . $e->getMessage());
        flash_set('danger', 'Could not apply the payment.');
    }
    redirect('deposits.php');
}

// Collection is the only point at which stock moves and a sale is created.
if (is_post() && isset($_POST['collect_deposit'])) {
    verify_csrf();
    $depositId = (int) ($_POST['deposit_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE id = ? FOR UPDATE');
        $stmt->execute([$depositId]);
        $deposit = $stmt->fetch();
        if (!$deposit) throw new RuntimeException('Deposit order not found.');
        require_deposit_store_access($deposit, $canViewAllStores, $assignedStoreId);
        if ($deposit['status'] !== 'paid' || (float) $deposit['balance_amount'] > 0) throw new RuntimeException('The order must be fully paid before collection.');

        $itemsStmt = $pdo->prepare('SELECT di.*, p.name FROM deposit_items di JOIN products p ON p.id = di.product_id WHERE di.deposit_id = ? ORDER BY di.product_id');
        $itemsStmt->execute([$depositId]);
        $items = $itemsStmt->fetchAll();
        if (!$items) throw new RuntimeException('This deposit order has no items.');
        $lockInventory = $pdo->prepare('SELECT id, quantity FROM inventory WHERE store_id = ? AND product_id = ? FOR UPDATE');
        $inventoryRows = [];
        foreach ($items as $item) {
            $lockInventory->execute([(int) $deposit['store_id'], (int) $item['product_id']]);
            $inventory = $lockInventory->fetch();
            if (!$inventory || (int) $inventory['quantity'] < (int) $item['quantity']) {
                throw new RuntimeException('Not enough stock to collect "' . $item['name'] . '". Required: ' . (int) $item['quantity'] . ', available: ' . (int) ($inventory['quantity'] ?? 0) . '.');
            }
            $inventoryRows[(int) $item['product_id']] = (int) $inventory['id'];
        }
        $methodStmt = $pdo->prepare('SELECT payment_method FROM deposit_payments WHERE deposit_id = ? ORDER BY paid_at DESC, id DESC LIMIT 1');
        $methodStmt->execute([$depositId]);
        $saleMethod = $methodStmt->fetchColumn() ?: 'cash';
        if (!in_array($saleMethod, ['cash', 'mobile_money', 'card'], true)) $saleMethod = 'cash';
        $transactionId = 'TXN-' . date('Ymd-His') . '-' . random_int(100, 999);
        $pdo->prepare("INSERT INTO sales (store_id, user_id, customer_name, customer_phone, subtotal_amount, discount_amount, vat_amount, total_amount, balance_amount, payment_method, transaction_id, payment_status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, 'approved', ?, NOW())")
            ->execute([(int) $deposit['store_id'], (int) current_user()['id'], $deposit['customer_name'], $deposit['customer_phone'], $deposit['total_amount'], $deposit['total_amount'], $saleMethod, $transactionId, (int) current_user()['id']]);
        $saleId = (int) $pdo->lastInsertId();
        $insertSaleItem = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)');
        $decrement = $pdo->prepare('UPDATE inventory SET quantity = quantity - ?, last_sold_date = CURDATE() WHERE id = ?');
        foreach ($items as $item) {
            $insertSaleItem->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
            $decrement->execute([(int) $item['quantity'], $inventoryRows[(int) $item['product_id']]]);
        }
        $pdo->prepare("UPDATE deposits SET status = 'collected', completed_sale_id = ?, collected_by = ?, collected_at = NOW() WHERE id = ?")
            ->execute([$saleId, (int) current_user()['id'], $depositId]);
        $pdo->commit();
        log_audit('collect', 'deposit', $depositId, ['status' => 'paid'], ['status' => 'collected', 'sale_id' => $saleId, 'transaction_id' => $transactionId]);
        flash_set('success', 'Items collected successfully. Sale ' . $transactionId . ' was created and inventory was updated.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Collect deposit failed: ' . $e->getMessage());
        flash_set('danger', 'Could not complete collection. Inventory was not changed.');
    }
    redirect('deposits.php');
}

if (is_post() && isset($_POST['delete_deposit'])) {
    require_role('admin');
    verify_csrf();
    $depositId = (int) ($_POST['deposit_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE id = ? FOR UPDATE');
        $stmt->execute([$depositId]);
        $deposit = $stmt->fetch();
        if (!$deposit) throw new RuntimeException('Deposit order not found.');
        if ($deposit['status'] === 'collected') throw new RuntimeException('A collected order cannot be deleted because its sale and stock movement already exist.');
        $pdo->prepare('DELETE FROM deposits WHERE id = ?')->execute([$depositId]);
        $pdo->commit();
        log_audit('delete', 'deposit', $depositId, ['deposit_number' => $deposit['deposit_number'], 'paid_amount' => (float) $deposit['paid_amount']], null);
        flash_set('success', 'Deposit order deleted.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('danger', 'Could not delete the deposit order.');
    }
    redirect('deposits.php');
}

// Data and filters.
$storeSql = "SELECT id, name FROM stores WHERE status = 'active'" . (!$canViewAllStores ? ' AND id = ' . $assignedStoreId : '') . ' ORDER BY name';
$stores = $pdo->query($storeSql)->fetchAll();
$products = $pdo->query("SELECT id, name, sku, unit_price FROM products WHERE status = 'active' ORDER BY name")->fetchAll();
$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$storeFilter = $canViewAllStores ? (int) ($_GET['store_id'] ?? 0) : $assignedStoreId;
$conditions = [];
$params = [];
if ($canViewAllStores && $storeFilter > 0) { $conditions[] = 'd.store_id = :store'; $params[':store'] = $storeFilter; }
elseif (!$canViewAllStores) { $conditions[] = $assignedStoreId > 0 ? 'd.store_id = :assigned' : '1 = 0'; if ($assignedStoreId > 0) $params[':assigned'] = $assignedStoreId; }
if (in_array($statusFilter, ['pending', 'paid', 'collected'], true)) { $conditions[] = 'd.status = :status'; $params[':status'] = $statusFilter; }
if ($search !== '') { $conditions[] = '(d.deposit_number LIKE :q1 OR d.customer_name LIKE :q2 OR d.customer_phone LIKE :q3)'; $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $search . '%'; }
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$stmt = $pdo->prepare("SELECT d.*, st.name AS store_name, u.full_name AS created_by_name,
    GROUP_CONCAT(CONCAT(di.quantity, '× ', p.name) ORDER BY p.name SEPARATOR ', ') AS items_summary,
    COALESCE(SUM(di.quantity), 0) AS total_units
    FROM deposits d JOIN stores st ON st.id = d.store_id JOIN users u ON u.id = d.created_by
    LEFT JOIN deposit_items di ON di.deposit_id = d.id LEFT JOIN products p ON p.id = di.product_id" . $where . '
    GROUP BY d.id ORDER BY d.created_at DESC LIMIT 100');
$stmt->execute($params);
$deposits = $stmt->fetchAll();
$scope = !$canViewAllStores ? ($assignedStoreId > 0 ? ' WHERE store_id = ' . $assignedStoreId : ' WHERE 1 = 0') : '';
$metrics = $pdo->query("SELECT COUNT(*) AS total_orders, COALESCE(SUM(paid_amount),0) AS payments, COALESCE(SUM(balance_amount),0) AS outstanding, SUM(status='paid') AS ready FROM deposits" . $scope)->fetch();

$pageTitle = 'Deposits';
require __DIR__ . '/includes/header.php';
?>

<section class="deposit-hero mb-4">
  <div><span class="deposit-overline"><i class="bi bi-box2-heart me-2"></i>Customer collection orders</span><h2>Take payments now. Move stock only on collection.</h2><p>Record the customer's items and payments, then complete the sale when the customer arrives to collect.</p></div>
  <div class="deposit-hero-metrics"><div><span>Payments received</span><strong><?= e(money($metrics['payments'])) ?></strong></div><div><span>Outstanding</span><strong><?= e(money($metrics['outstanding'])) ?></strong></div></div>
</section>

<div class="row g-4 align-items-start">
  <div class="col-xl-8">
    <section class="deposit-panel">
      <header class="deposit-panel-header"><div><span class="deposit-section-label">Deposit register</span><h3>Customer orders</h3></div><span class="deposit-result-count"><?= count($deposits) ?> shown · <?= (int) ($metrics['ready'] ?? 0) ?> ready</span></header>
      <form class="deposit-filters" method="GET"><div class="deposit-search"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Order, customer, or phone"></div><select class="form-select" name="status"><option value="all">All statuses</option><?php foreach (['pending' => 'Partly paid', 'paid' => 'Fully paid', 'collected' => 'Collected'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><select class="form-select" name="store_id" <?= !$canViewAllStores ? 'disabled' : '' ?>><?php if ($canViewAllStores): ?><option value="0">All stores</option><?php endif; ?><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select><button class="btn deposit-filter-button"><i class="bi bi-search"></i>Search</button><a class="btn deposit-clear-button" href="deposits.php"><i class="bi bi-x-lg"></i></a></form>
      <div class="table-responsive deposit-table-wrap"><table class="table deposit-table align-middle mb-0"><thead><tr><th>Order</th><th>Customer & items</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
        <?php if (!$deposits): ?><tr><td colspan="7"><div class="deposit-empty"><i class="bi bi-box2-heart"></i><strong>No deposit orders found</strong><span>Create an order or adjust the filters.</span></div></td></tr>
        <?php else: foreach ($deposits as $deposit): ?>
        <tr><td><div class="deposit-reference"><strong><?= e($deposit['deposit_number']) ?></strong><small><?= e(date('M j, Y · g:i A', strtotime($deposit['created_at']))) ?></small><small><?= e($deposit['store_name']) ?></small></div></td>
          <td><div class="deposit-destination"><strong><?= e($deposit['customer_name']) ?><?= $deposit['customer_phone'] ? ' · ' . e($deposit['customer_phone']) : '' ?></strong><small title="<?= e($deposit['items_summary']) ?>"><?= e($deposit['items_summary'] ?: 'No items') ?></small></div></td>
          <td><strong><?= e(money($deposit['total_amount'])) ?></strong></td><td><strong class="deposit-amount"><?= e(money($deposit['paid_amount'])) ?></strong></td><td><strong><?= e(money($deposit['balance_amount'])) ?></strong></td>
          <td><span class="sales-payment-status <?= $deposit['status'] === 'collected' ? 'approved' : 'pending' ?>"><i class="bi bi-<?= $deposit['status'] === 'collected' ? 'bag-check' : ($deposit['status'] === 'paid' ? 'check-circle' : 'clock') ?>"></i><?= e($deposit['status'] === 'paid' ? 'Ready to collect' : ucfirst($deposit['status'])) ?></span></td>
          <td class="text-end"><div class="sales-row-actions">
            <a class="btn sales-receipt-button" href="deposit_receipt.php?id=<?= (int) $deposit['id'] ?>" target="_blank" title="View deposit receipt"><i class="bi bi-receipt"></i></a>
            <?php if ($deposit['status'] === 'pending'): ?><button class="btn sales-approve-button deposit-payment-open" type="button" data-bs-toggle="modal" data-bs-target="#depositPaymentModal" data-id="<?= (int) $deposit['id'] ?>" data-number="<?= e($deposit['deposit_number']) ?>" data-customer="<?= e($deposit['customer_name']) ?>" data-balance="<?= e(number_format((float) $deposit['balance_amount'], 2, '.', '')) ?>"><i class="bi bi-cash-coin"></i>Pay</button><?php endif; ?>
            <?php if ($deposit['status'] === 'paid'): ?><form method="POST" data-confirm="Confirm that <?= e($deposit['customer_name']) ?> is collecting these items now? Inventory will be deducted."><?= csrf_field() ?><input type="hidden" name="collect_deposit" value="1"><input type="hidden" name="deposit_id" value="<?= (int) $deposit['id'] ?>"><button class="btn sales-approve-button"><i class="bi bi-bag-check"></i>Collect</button></form><?php endif; ?>
            <?php if ($deposit['status'] === 'collected' && $deposit['completed_sale_id']): ?><a class="btn sales-receipt-button" href="receipt.php?id=<?= (int) $deposit['completed_sale_id'] ?>" target="_blank"><i class="bi bi-receipt"></i></a><?php endif; ?>
            <?php if (is_role('admin') && $deposit['status'] !== 'collected'): ?><form method="POST" data-confirm="Delete <?= e($deposit['deposit_number']) ?>? Review any refund due before continuing."><?= csrf_field() ?><input type="hidden" name="delete_deposit" value="1"><input type="hidden" name="deposit_id" value="<?= (int) $deposit['id'] ?>"><button class="btn deposit-delete-button"><i class="bi bi-trash3"></i></button></form><?php endif; ?>
          </div></td></tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
    </section>
  </div>

  <div class="col-xl-4"><section class="deposit-form-panel"><header><span><i class="bi bi-plus-circle"></i></span><div><span class="deposit-section-label">New order</span><h3>Create deposit order</h3></div></header><form method="POST" id="depositOrderForm"><?= csrf_field() ?><input type="hidden" name="create_deposit" value="1"><div class="deposit-form-body">
    <div class="row g-2 mb-3"><div class="col-7"><label class="form-label">Customer name</label><input class="form-control" name="customer_name" maxlength="100" required></div><div class="col-5"><label class="form-label">Phone</label><input class="form-control" name="customer_phone" maxlength="20"></div></div>
    <div class="mb-3"><label class="form-label">Store</label><select class="form-select" name="store_id" required><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?></select></div>
    <div class="sales-product-picker mb-3"><label class="form-label">Search and select items</label><div class="sales-product-search"><i class="bi bi-search"></i><input type="search" class="form-control" id="depositProductSearch" placeholder="Type a product name or SKU" autocomplete="off"></div><div id="depositProductResults" class="list-group sales-search-results"></div></div>
    <div class="sales-cart mb-3"><div class="sales-cart-heading"><span>Selected items</span><small id="depositItemCount">0 items</small></div><div class="table-responsive"><table class="table sales-cart-table align-middle mb-0"><thead><tr><th>Product</th><th>Qty</th><th class="text-end">Total</th><th></th></tr></thead><tbody id="depositItemRows"></tbody></table></div><div id="depositItemsEmpty" class="sales-cart-empty"><i class="bi bi-cart-plus"></i><span>Search above to add items</span></div></div>
    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Initial payment</label><input class="form-control" id="depositInitialPayment" type="number" name="initial_payment" min="0" step="0.01" value="0.00" required></div><div class="col-6"><label class="form-label">Method</label><select class="form-select" name="payment_method"><?php foreach ($methods as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div></div>
    <div class="sales-payment-summary deposit-payment-summary mb-3"><span>Total amount <strong id="depositOrderTotal"><?= e(money(0)) ?></strong></span><span>Amount paid <strong id="depositAmountPaid"><?= e(money(0)) ?></strong></span><span>Balance to pay <strong class="due" id="depositBalanceToPay"><?= e(money(0)) ?></strong></span></div>
  </div><footer><div><span>Inventory</span><strong>Unchanged until collection</strong></div><button class="btn deposit-save-button" <?= !$stores || !$products ? 'disabled' : '' ?>><i class="bi bi-check2-circle"></i>Create order</button></footer></form></section></div>
</div>

<div class="modal fade" id="depositPaymentModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST"><?= csrf_field() ?><input type="hidden" name="add_payment" value="1"><input type="hidden" name="deposit_id" id="paymentDepositId"><div class="modal-header"><div><h5 class="modal-title">Add payment</h5><small id="paymentDepositDetails"></small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-6"><label class="form-label">Amount</label><input class="form-control" type="number" name="amount" id="paymentAmount" min="0.01" step="0.01" required></div><div class="col-6"><label class="form-label">Method</label><select class="form-select" name="payment_method"><?php foreach ($methods as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn deposit-save-button"><i class="bi bi-check2-circle"></i>Apply payment</button></div></form></div></div></div>

<?php
$extraScripts = '<script>
const depositProducts = ' . json_encode(array_map(static function ($product) {
    return ['id' => (int) $product['id'], 'name' => $product['name'], 'sku' => $product['sku'], 'price' => (float) $product['unit_price']];
}, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
const depositCurrency = ' . json_encode(CURRENCY_SYMBOL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
const depositRows = document.getElementById("depositItemRows");
const depositEmpty = document.getElementById("depositItemsEmpty");
const depositTotal = document.getElementById("depositOrderTotal");
const depositPaid = document.getElementById("depositAmountPaid");
const depositBalance = document.getElementById("depositBalanceToPay");
const depositItemCount = document.getElementById("depositItemCount");
const initialPayment = document.getElementById("depositInitialPayment");
const productSearch = document.getElementById("depositProductSearch");
const productResults = document.getElementById("depositProductResults");
function depositEscape(value) { const el = document.createElement("div"); el.textContent = String(value); return el.innerHTML; }
function recalcDepositOrder() {
  let total = 0;
  let units = 0;
  depositRows.querySelectorAll("tr").forEach(function(row) {
    const price = parseFloat(row.dataset.price) || 0;
    const qty = Math.max(parseInt(row.querySelector(".deposit-quantity").value, 10) || 0, 0);
    const subtotal = price * qty;
    total += subtotal;
    units += qty;
    row.querySelector(".deposit-line-total").textContent = depositCurrency + subtotal.toFixed(2);
  });
  const paid = Math.min(Math.max(parseFloat(initialPayment.value) || 0, 0), total);
  const balance = Math.max(total - paid, 0);
  depositTotal.textContent = depositCurrency + total.toFixed(2);
  depositPaid.textContent = depositCurrency + paid.toFixed(2);
  depositBalance.textContent = depositCurrency + balance.toFixed(2);
  depositBalance.classList.toggle("due", balance > 0);
  initialPayment.max = total.toFixed(2);
  depositEmpty.style.display = depositRows.children.length ? "none" : "flex";
  depositItemCount.textContent = units + (units === 1 ? " item" : " items");
}
function addProductToDeposit(product) {
  const existing = depositRows.querySelector("tr[data-product-id=\"" + product.id + "\"]");
  if (existing) {
    const input = existing.querySelector(".deposit-quantity");
    input.value = (parseInt(input.value, 10) || 0) + 1;
    recalcDepositOrder();
    return;
  }
  const row = document.createElement("tr");
  row.dataset.productId = product.id;
  row.dataset.price = product.price;
  row.innerHTML = "<td><div class=\"sales-cart-product\"><strong>" + depositEscape(product.name) + "</strong><small>" + depositEscape(product.sku) + " · " + depositCurrency + product.price.toFixed(2) + "</small></div><input type=\"hidden\" name=\"product_id[]\" value=\"" + product.id + "\"></td>" +
    "<td><input class=\"form-control sales-quantity-input deposit-quantity\" type=\"number\" name=\"quantity[]\" min=\"1\" value=\"1\" required></td>" +
    "<td class=\"deposit-line-total sale-line-total text-end\">" + depositCurrency + "0.00</td>" +
    "<td class=\"text-end\"><button class=\"btn remove-sale-item remove-deposit-item\" type=\"button\" title=\"Remove\"><i class=\"bi bi-x-lg\"></i></button></td>";
  depositRows.appendChild(row);
  row.querySelector(".deposit-quantity").addEventListener("input", recalcDepositOrder);
  row.querySelector(".remove-deposit-item").addEventListener("click", function() { row.remove(); recalcDepositOrder(); });
  recalcDepositOrder();
}
function renderDepositProductResults(query) {
  const normalized = query.trim().toLowerCase();
  if (!normalized) { productResults.style.display = "none"; productResults.innerHTML = ""; return; }
  const matches = depositProducts.filter(function(product) { return product.name.toLowerCase().includes(normalized) || product.sku.toLowerCase().includes(normalized); }).slice(0, 8);
  if (!matches.length) { productResults.innerHTML = "<div class=\"sales-search-empty\">No matching products</div>"; productResults.style.display = "block"; return; }
  productResults.innerHTML = matches.map(function(product) { return "<button type=\"button\" class=\"sale-search-result\" data-id=\"" + product.id + "\"><span><strong>" + depositEscape(product.name) + "</strong><small>" + depositEscape(product.sku) + "</small></span><b>" + depositCurrency + product.price.toFixed(2) + "</b></button>"; }).join("");
  productResults.style.display = "block";
  productResults.querySelectorAll(".sale-search-result").forEach(function(button) { button.addEventListener("click", function() { const product = depositProducts.find(function(item) { return String(item.id) === button.dataset.id; }); if (product) addProductToDeposit(product); productSearch.value = ""; productResults.style.display = "none"; productSearch.focus(); }); });
}
productSearch.addEventListener("input", function() { renderDepositProductResults(this.value); });
productSearch.addEventListener("keydown", function(event) { if (event.key === "Enter") { event.preventDefault(); const first = productResults.querySelector(".sale-search-result"); if (first) first.click(); } });
document.addEventListener("click", function(event) { if (!productSearch.contains(event.target) && !productResults.contains(event.target)) productResults.style.display = "none"; });
initialPayment.addEventListener("input", recalcDepositOrder);
document.getElementById("depositOrderForm").addEventListener("submit", function(event) { if (!depositRows.children.length) { event.preventDefault(); alert("Search for and select at least one item."); } });
recalcDepositOrder();
document.querySelectorAll(".deposit-payment-open").forEach(function(button) { button.addEventListener("click", function() { document.getElementById("paymentDepositId").value = button.dataset.id; document.getElementById("paymentAmount").max = button.dataset.balance; document.getElementById("paymentAmount").value = button.dataset.balance; document.getElementById("paymentDepositDetails").textContent = button.dataset.number + " · " + button.dataset.customer + " · Balance " + ' . json_encode(CURRENCY_SYMBOL) . ' + Number(button.dataset.balance).toFixed(2); }); });
</script>';
require __DIR__ . '/includes/footer.php';
?>
