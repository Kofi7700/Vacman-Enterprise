<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$saleId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT s.*,
           st.name AS store_name, st.address AS store_address, st.contact_phone AS store_phone,
           u.full_name AS cashier_name
    FROM sales s
    JOIN stores st ON st.id = s.store_id
    JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    flash_set('danger', 'Receipt not found.');
    redirect('sales.php');
}

// A cashier can reprint their own sales; managers/admins can pull up any.
if (!is_role('admin', 'manager') && (int) $sale['user_id'] !== (int) current_user()['id']) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

$itemsStmt = $pdo->prepare("
    SELECT si.quantity, si.unit_price, si.subtotal, p.name AS product_name, p.sku
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
    ORDER BY si.id
");
$itemsStmt->execute([$saleId]);
$saleItems = $itemsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Receipt <?= e($sale['transaction_id']) ?> - <?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background: #eef0f3; font-family: 'Segoe UI', sans-serif; padding: 2rem 1rem; }
    .receipt {
      max-width: 340px; margin: 0 auto; background: #fff; padding: 24px 20px;
      border-radius: 6px; box-shadow: 0 4px 18px rgba(0,0,0,0.1);
      font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; color: #222;
    }
    .receipt h4 { font-family: 'Segoe UI', sans-serif; font-weight: 700; margin-bottom: 0; }
    .receipt .sub { color: #666; font-size: 0.78rem; }
    .dashed { border-top: 1px dashed #999; margin: 10px 0; }
    .row-line { display: flex; justify-content: space-between; }
    .totals-line { display: flex; justify-content: space-between; font-weight: 700; font-size: 1rem; }
    .actions { max-width: 340px; margin: 16px auto 0; display: flex; gap: 8px; }
    .actions .btn { flex: 1; }
    @media print {
      body { background: #fff; padding: 0; }
      .actions, .no-print { display: none !important; }
      .receipt { box-shadow: none; max-width: 100%; }
    }
  </style>
</head>
<body>

  <div class="receipt">
    <div class="text-center mb-2">
      <h4><?= e(BUSINESS_NAME) ?></h4>
      <div class="sub"><?= e($sale['store_name']) ?></div>
      <?php if (!empty($sale['store_address'])): ?><div class="sub"><?= e($sale['store_address']) ?></div><?php endif; ?>
      <?php if (!empty($sale['store_phone'])): ?><div class="sub">Tel: <?= e($sale['store_phone']) ?></div><?php endif; ?>
    </div>

    <div class="dashed"></div>

    <div class="row-line"><span>Receipt #</span><span><?= e($sale['transaction_id']) ?></span></div>
    <div class="row-line"><span>Date</span><span><?= e(date('M j, Y g:i A', strtotime($sale['sale_date']))) ?></span></div>
    <div class="row-line"><span>Cashier</span><span><?= e($sale['cashier_name']) ?></span></div>
    <?php if (!empty($sale['customer_name'])): ?>
    <div class="row-line"><span>Customer</span><span><?= e($sale['customer_name']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['customer_phone'])): ?>
    <div class="row-line"><span>Phone</span><span><?= e($sale['customer_phone']) ?></span></div>
    <?php endif; ?>

    <div class="dashed"></div>

    <?php foreach ($saleItems as $item): ?>
    <div class="row-line fw-bold"><span><?= e($item['product_name']) ?></span><span></span></div>
    <div class="sub mb-1">SKU: <?= e($item['sku']) ?></div>
    <div class="row-line">
      <span><?= (int) $item['quantity'] ?> x <?= e(money($item['unit_price'])) ?></span>
      <span><?= e(money($item['subtotal'])) ?></span>
    </div>
    <?php endforeach; ?>

    <div class="dashed"></div>

    <div class="totals-line"><span>TOTAL</span><span><?= e(money($sale['total_amount'])) ?></span></div>
    <div class="row-line sub mt-1"><span>Payment</span><span><?= e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></span></div>

    <div class="dashed"></div>

    <div class="text-center sub mt-3">
      Thank you for shopping with us!<br>
      Goods sold are not returnable without this receipt.
    </div>
  </div>

  <div class="actions no-print">
    <button class="btn btn-danger" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
    <a href="sales.php" class="btn btn-outline-secondary">Back to Sales</a>
  </div>

</body>
</html>
