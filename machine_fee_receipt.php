<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager', 'cashier');

$pdo = db();
$paymentId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT m.*, s.name AS store_name, s.address AS store_address, s.contact_phone AS store_phone,
            u.full_name AS collected_by_name
     FROM machine_fee_payments m
     JOIN stores s ON s.id = m.store_id
     JOIN users u ON u.id = m.collected_by
     WHERE m.id = ?'
);
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    flash_set('danger', 'Machine fee receipt not found.');
    redirect('machine_fees.php');
}

$canViewAllStores = is_role('admin', 'manager');
$assignedStoreId = (int) (current_user()['store_id'] ?? 0);
if (!$canViewAllStores && ($assignedStoreId <= 0 || $assignedStoreId !== (int) $payment['store_id'])) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($payment['transaction_id']) ?> - Machine Fee Receipt</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { padding: 2rem 1rem; background: #eef0f3; color: #222; font-family: 'Segoe UI', sans-serif; }
    .receipt { max-width: 430px; margin: 0 auto; padding: 25px 22px; border-radius: 8px; background: #fff; box-shadow: 0 4px 18px rgba(0,0,0,.1); font-family: 'Courier New', monospace; }
    .receipt-logo { display: block; width: 120px; max-width: 45%; height: auto; margin: 0 auto 8px; }
    .receipt h4 { margin: 0; font-family: 'Segoe UI', sans-serif; font-weight: 750; }
    .sub { color: #666; font-size: .78rem; }
    .title { margin: 14px 0 4px; color: #167003; font-family: 'Segoe UI', sans-serif; font-size: .8rem; font-weight: 800; letter-spacing: .08em; text-align: center; text-transform: uppercase; }
    .dashed { margin: 11px 0; border-top: 1px dashed #999; }
    .line { display: flex; justify-content: space-between; gap: 1rem; margin: 6px 0; font-size: .82rem; }
    .line span:last-child { text-align: right; }
    .services { padding: 12px; border-radius: 6px; background: #f2f7f1; text-align: center; }
    .services span, .services strong { display: block; }
    .services span { color: #758075; font-size: .68rem; }
    .services strong { margin-top: 3px; color: #234a2b; font-family: 'Segoe UI', sans-serif; }
    .total { display: flex; justify-content: space-between; align-items: baseline; font-size: 1.05rem; font-weight: 800; }
    .note { margin-top: 10px; color: #666; font-size: .72rem; text-align: center; }
    .actions { display: flex; gap: 8px; max-width: 430px; margin: 16px auto 0; }
    .actions .btn { flex: 1; }
    @media print { body { padding: 0; background: #fff; } .receipt { max-width: 100%; box-shadow: none; } .actions { display: none; } }
  </style>
</head>
<body>
  <main class="receipt">
    <div class="text-center"><img src="assets/images/yollow_logo.png" alt="<?= e(BUSINESS_NAME) ?> logo" class="receipt-logo"><h4><?= e(BUSINESS_NAME) ?></h4><div class="sub"><?= e($payment['store_name']) ?></div><?php if ($payment['store_address']): ?><div class="sub"><?= e($payment['store_address']) ?></div><?php endif; ?><?php if ($payment['store_phone']): ?><div class="sub">Tel: <?= e($payment['store_phone']) ?></div><?php endif; ?></div>
    <div class="title">Machine Fee Receipt</div><div class="dashed"></div>
    <div class="line"><span>Receipt</span><span><?= e($payment['transaction_id']) ?></span></div>
    <div class="line"><span>Date</span><span><?= e(date('M j, Y · g:i A', strtotime($payment['paid_at']))) ?></span></div>
    <div class="line"><span>Payer</span><span><?= e($payment['payer_name']) ?></span></div>
    <?php if ($payment['payer_phone']): ?><div class="line"><span>Phone</span><span><?= e($payment['payer_phone']) ?></span></div><?php endif; ?>
    <div class="dashed"></div><div class="services"><span>Services</span><strong><?= e($payment['services']) ?></strong></div><div class="dashed"></div>
    <div class="line"><span>Payment method</span><span><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></span></div>
    <div class="total"><span>Amount paid</span><span><?= e(money($payment['amount'])) ?></span></div>
    <div class="dashed"></div><div class="line"><span>Received by</span><span><?= e($payment['collected_by_name']) ?></span></div><div class="note">Thank you. This receipt confirms payment of the machine fee.</div>
  </main>
  <div class="actions"><a class="btn btn-outline-secondary" href="machine_fees.php"><i class="bi bi-arrow-left me-1"></i>Back</a><button class="btn btn-success" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print receipt</button></div>
</body>
</html>
