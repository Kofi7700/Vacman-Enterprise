<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager', 'mini_manager', 'cashier', 'sales_clerk');

$pdo = db();
$depositId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT d.*, st.name AS store_name, st.address AS store_address, st.contact_phone AS store_phone,
            u.full_name AS created_by_name, cu.full_name AS collected_by_name
     FROM deposits d
     JOIN stores st ON st.id = d.store_id
     JOIN users u ON u.id = d.created_by
     LEFT JOIN users cu ON cu.id = d.collected_by
     WHERE d.id = ?'
);
$stmt->execute([$depositId]);
$deposit = $stmt->fetch();

if (!$deposit) {
    flash_set('danger', 'Deposit receipt not found.');
    redirect('deposits.php');
}

$canViewAllStores = is_role('admin', 'manager');
$assignedStoreId = (int) (current_user()['store_id'] ?? 0);
if (!$canViewAllStores && ($assignedStoreId <= 0 || $assignedStoreId !== (int) $deposit['store_id'])) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

$itemsStmt = $pdo->prepare(
    'SELECT di.quantity, di.unit_price, di.subtotal, p.name AS product_name, p.sku
     FROM deposit_items di JOIN products p ON p.id = di.product_id
     WHERE di.deposit_id = ? ORDER BY di.id'
);
$itemsStmt->execute([$depositId]);
$items = $itemsStmt->fetchAll();

$paymentsStmt = $pdo->prepare(
    'SELECT dp.amount, dp.payment_method, dp.paid_at, u.full_name AS received_by_name
     FROM deposit_payments dp JOIN users u ON u.id = dp.received_by
     WHERE dp.deposit_id = ? ORDER BY dp.paid_at, dp.id'
);
$paymentsStmt->execute([$depositId]);
$payments = $paymentsStmt->fetchAll();

$statusLabel = match ($deposit['status']) {
    'paid' => 'Fully paid — ready to collect',
    'collected' => 'Collected',
    'cancelled' => 'Cancelled',
    default => (float) $deposit['paid_amount'] > 0 ? 'Partially paid' : 'Unpaid',
};
$statusClass = in_array($deposit['status'], ['paid', 'collected'], true) ? 'paid' : 'partial';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($deposit['deposit_number']) ?> - Deposit Receipt</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { padding: 2rem 1rem; background: #eef0f3; color: #222; font-family: 'Segoe UI', sans-serif; }
    .receipt { max-width: 470px; margin: 0 auto; padding: 25px 22px; border-radius: 8px; background: #fff; box-shadow: 0 4px 18px rgba(0,0,0,.1); font-family: 'Courier New', monospace; font-size: .84rem; }
    .receipt-logo { display: block; width: 120px; max-width: 45%; height: auto; margin: 0 auto 8px; }
    .receipt h4 { margin: 0; font-family: 'Segoe UI', sans-serif; font-weight: 750; }
    .sub { color: #666; font-size: .72rem; }
    .title { margin: 14px 0 4px; color: #167003; font-family: 'Segoe UI', sans-serif; font-size: .8rem; font-weight: 800; letter-spacing: .08em; text-align: center; text-transform: uppercase; }
    .dashed { margin: 11px 0; border-top: 1px dashed #999; }
    .line { display: flex; justify-content: space-between; gap: 1rem; margin: 5px 0; }
    .line span:last-child { text-align: right; }
    .status { margin: 10px 0; padding: 8px; border-radius: 5px; font-family: 'Segoe UI', sans-serif; font-size: .72rem; font-weight: 750; text-align: center; }
    .status.paid { background: #e5f7ec; color: #116b36; }
    .status.partial { background: #fff3d5; color: #806100; }
    .items { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: .73rem; }
    .items th { padding: 3px 2px 6px; border-bottom: 1px dashed #999; }
    .items td { padding: 6px 2px; vertical-align: top; }
    .items .qty { width: 10%; text-align: center; }
    .items .description { width: 40%; overflow-wrap: anywhere; }
    .items .price, .items .amount { width: 25%; text-align: right; white-space: nowrap; }
    .total { display: flex; justify-content: space-between; margin-top: 4px; font-size: 1rem; font-weight: 800; }
    .balance { color: #a63d35; font-weight: 800; }
    .payments-title { margin-bottom: 6px; font-family: 'Segoe UI', sans-serif; font-size: .7rem; font-weight: 800; text-transform: uppercase; }
    .payment { padding: 6px 0; border-bottom: 1px dotted #ccc; }
    .payment:last-child { border-bottom: 0; }
    .note { margin-top: 10px; color: #666; font-size: .69rem; line-height: 1.45; text-align: center; }
    .actions { display: flex; gap: 8px; max-width: 470px; margin: 16px auto 0; }
    .actions .btn { flex: 1; }
    @media print { body { padding: 0; background: #fff; } .receipt { max-width: 100%; box-shadow: none; } .actions { display: none; } }
  </style>
</head>
<body>
  <main class="receipt">
    <div class="text-center"><img src="assets/images/yollow_logo.png" alt="<?= e(BUSINESS_NAME) ?> logo" class="receipt-logo"><h4><?= e(BUSINESS_NAME) ?></h4><div class="sub"><?= e($deposit['store_name']) ?></div><?php if ($deposit['store_address']): ?><div class="sub"><?= e($deposit['store_address']) ?></div><?php endif; ?><?php if ($deposit['store_phone']): ?><div class="sub">Tel: <?= e($deposit['store_phone']) ?></div><?php endif; ?></div>
    <div class="title">Deposit Order Receipt</div><div class="dashed"></div>
    <div class="line"><span>Order</span><span><?= e($deposit['deposit_number']) ?></span></div>
    <div class="line"><span>Date</span><span><?= e(date('M j, Y · g:i A', strtotime($deposit['created_at']))) ?></span></div>
    <div class="line"><span>Customer</span><span><?= e($deposit['customer_name']) ?></span></div>
    <?php if ($deposit['customer_phone']): ?><div class="line"><span>Phone</span><span><?= e($deposit['customer_phone']) ?></span></div><?php endif; ?>
    <div class="line"><span>Created by</span><span><?= e($deposit['created_by_name']) ?></span></div>
    <div class="status <?= e($statusClass) ?>"><?= e($statusLabel) ?></div>
    <div class="dashed"></div>
    <table class="items"><thead><tr><th class="qty">QTY</th><th class="description">ITEM</th><th class="price">PRICE</th><th class="amount">AMOUNT</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td class="qty"><?= (int) $item['quantity'] ?></td><td class="description"><?= e($item['product_name']) ?><br><span class="sub"><?= e($item['sku']) ?></span></td><td class="price"><?= e(money($item['unit_price'])) ?></td><td class="amount"><?= e(money($item['subtotal'])) ?></td></tr><?php endforeach; ?></tbody></table>
    <div class="dashed"></div>
    <div class="total"><span>TOTAL AMOUNT</span><span><?= e(money($deposit['total_amount'])) ?></span></div>
    <div class="line"><span>Amount paid</span><strong><?= e(money($deposit['paid_amount'])) ?></strong></div>
    <div class="line balance"><span>Balance to pay</span><span><?= e(money($deposit['balance_amount'])) ?></span></div>
    <?php if ($payments): ?><div class="dashed"></div><div class="payments-title">Payment history</div><?php foreach ($payments as $payment): ?><div class="payment"><div class="line"><span><?= e(date('M j, Y · g:i A', strtotime($payment['paid_at']))) ?></span><strong><?= e(money($payment['amount'])) ?></strong></div><div class="line sub"><span><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></span><span><?= e($payment['received_by_name']) ?></span></div></div><?php endforeach; ?><?php endif; ?>
    <?php if ($deposit['status'] === 'collected'): ?><div class="dashed"></div><div class="line"><span>Collected</span><span><?= e(date('M j, Y · g:i A', strtotime($deposit['collected_at']))) ?></span></div><div class="line"><span>Released by</span><span><?= e($deposit['collected_by_name'] ?? '') ?></span></div><?php endif; ?>
    <div class="dashed"></div><div class="note"><?php if ($deposit['status'] === 'collected'): ?>This order has been collected and inventory has been updated.<?php else: ?>This receipt confirms the deposit order and payments received. Items remain in inventory until the customer collects the order.<?php endif; ?></div>
  </main>
  <div class="actions"><a class="btn btn-outline-secondary" href="deposits.php"><i class="bi bi-arrow-left me-1"></i>Back to deposits</a><button class="btn btn-success" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print receipt</button></div>
</body>
</html>
