<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$saleId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT s.*,
           st.name AS store_name, st.address AS store_address, st.contact_phone AS store_phone,
           u.full_name AS cashier_name, cu.full_name AS corrected_by_name
    FROM sales s
    JOIN stores st ON st.id = s.store_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN users cu ON cu.id = s.corrected_by
    WHERE s.id = ?
");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();

if (!$sale) {
    flash_set('danger', 'Receipt not found.');
    redirect('sales.php');
}

if ($sale['payment_status'] !== 'approved') {
    flash_set('warning', 'The receipt is available after payment approval.');
    redirect('sales.php');
}

// Only administrators and managers have cross-store sales visibility.
$canViewAllStores = is_role('admin', 'manager');
$assignedStoreId = (int) (current_user()['store_id'] ?? 0);
if (!$canViewAllStores && ($assignedStoreId <= 0 || $assignedStoreId !== (int) $sale['store_id'])) {
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

$subTotal = isset($sale['subtotal_amount']) ? (float) $sale['subtotal_amount'] : array_sum(array_map(static fn (array $item): float => (float) $item['subtotal'], $saleItems));
$discountAmount = (float) ($sale['discount_amount'] ?? 0);
$vatAmount = (float) ($sale['vat_amount'] ?? 0);
$receiptTotal = (float) $sale['total_amount'];
$balanceAmount = (float) ($sale['balance_amount'] ?? 0);
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
      max-width: 420px; margin: 0 auto; background: #fff; padding: 24px 20px;
      border-radius: 6px; box-shadow: 0 4px 18px rgba(0,0,0,0.1);
      font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; color: #222;
    }
    .receipt h4 { font-family: 'Segoe UI', sans-serif; font-weight: 700; margin-bottom: 0; }
    .receipt-logo { display: block; width: 120px; max-width: 45%; height: auto; margin: 0 auto 8px; }
    .receipt .sub { color: #666; font-size: 0.78rem; }
    .dashed { border-top: 1px dashed #999; margin: 10px 0; }
    .row-line { display: flex; justify-content: space-between; }
    .totals-line { display: flex; justify-content: space-between; font-weight: 700; font-size: 1rem; }
    .items-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 0.76rem; }
    .items-table th { padding: 3px 2px 6px; border-bottom: 1px dashed #999; font-weight: 700; }
    .items-table td { padding: 6px 2px; vertical-align: top; }
    .items-table .qty { width: 11%; text-align: center; }
    .items-table .description { width: 39%; overflow-wrap: anywhere; }
    .items-table .price, .items-table .amount { width: 25%; text-align: right; white-space: nowrap; }
    .developer-credit { line-height: 1.45; }
    .actions { max-width: 420px; margin: 16px auto 0; display: flex; gap: 8px; }
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
      <img src="assets/images/yollow_logo.png" alt="<?= e(BUSINESS_NAME) ?> logo" class="receipt-logo">
      <h4><?= e(BUSINESS_NAME) ?></h4>
      <div class="sub"><?= e($sale['store_name']) ?></div>
      <?php if (!empty($sale['store_address'])): ?><div class="sub"><?= e($sale['store_address']) ?></div><?php endif; ?>
      <?php if (!empty($sale['store_phone'])): ?><div class="sub">Tel: <?= e($sale['store_phone']) ?></div><?php endif; ?>
    </div>

    <div class="dashed"></div>

    <div class="row-line"><span>Receipt #</span><span><?= e($sale['transaction_id']) ?></span></div>
    <div class="row-line"><span>Date</span><span><?= e(date('M j, Y g:i A', strtotime($sale['sale_date']))) ?></span></div>
    <div class="row-line"><span>Cashier</span><span><?= e($sale['cashier_name']) ?></span></div>
    <?php if (!empty($sale['corrected_at'])): ?>
    <div class="row-line"><span>Corrected</span><span><?= e(date('M j, Y g:i A', strtotime($sale['corrected_at']))) ?></span></div>
    <div class="row-line"><span>Corrected By</span><span><?= e($sale['corrected_by_name'] ?? 'Administrator') ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['customer_name'])): ?>
    <div class="row-line"><span>Customer</span><span><?= e($sale['customer_name']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($sale['customer_phone'])): ?>
    <div class="row-line"><span>Phone</span><span><?= e($sale['customer_phone']) ?></span></div>
    <?php endif; ?>

    <div class="dashed"></div>

    <table class="items-table">
      <thead>
        <tr>
          <th class="qty">QTY</th>
          <th class="description">ITEM DESCRIPTION</th>
          <th class="price">UNIT PRICE</th>
          <th class="amount">AMOUNT</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($saleItems as $item): ?>
        <tr>
          <td class="qty"><?= (int) $item['quantity'] ?></td>
          <td class="description"><?= e($item['product_name']) ?><br><span class="sub"><?= e($item['sku']) ?></span></td>
          <td class="price"><?= e(money($item['unit_price'])) ?></td>
          <td class="amount"><?= e(money($item['subtotal'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="dashed"></div>

    <div class="row-line"><span>Sub Total</span><span><?= e(money($subTotal)) ?></span></div>
    <div class="row-line"><span>Discount</span><span><?= e(money($discountAmount)) ?></span></div>
    <div class="row-line"><span>VAT</span><span><?= e(money($vatAmount)) ?></span></div>
    <div class="totals-line mt-1"><span>RECEIPT TOTAL</span><span><?= e(money($receiptTotal)) ?></span></div>
    <div class="row-line"><span>Balance</span><span><?= e(money($balanceAmount)) ?></span></div>
    <div class="row-line sub mt-1"><span>Payment</span><span><?= e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></span></div>
    <?php if (!empty($sale['notes'])): ?><div class="row-line sub mt-1"><span>Correction Note</span><span><?= e($sale['notes']) ?></span></div><?php endif; ?>

    <div class="dashed"></div>

    <div class="text-center sub mt-3">
      Thank you for shopping with us!<br>
      Goods sold are not returnable without this receipt.
    </div>

    <div class="dashed"></div>

    <div class="text-center sub developer-credit">
      Software developed by: SharpTech.Net, Sogakope<br>
      (0549393685/0547002892)
    </div>
  </div>

  <div class="actions no-print">
    <button class="btn btn-danger" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
    <a href="sales.php" class="btn btn-outline-secondary">Back to Sales</a>
  </div>

</body>
</html>
