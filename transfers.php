<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canRequest = is_role('admin', 'manager', 'warehouse');
$canApprove = is_role('admin', 'manager');
$canFulfill = is_role('admin', 'warehouse');

// ---- Handle New Transfer Request ---------------------------------------
if (is_post() && isset($_POST['add_transfer'])) {
    require_role('admin', 'manager', 'warehouse');
    verify_csrf();

    $productId = (int) ($_POST['product_id'] ?? 0);
    $fromStore = (int) ($_POST['from_store_id'] ?? 0);
    $toStore = (int) ($_POST['to_store_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);

    $errors = validate($_POST, ['product_id' => 'required|int', 'from_store_id' => 'required|int', 'to_store_id' => 'required|int', 'quantity' => 'required|int|positive']);
    if ($fromStore === $toStore && $fromStore !== 0) {
        $errors[] = 'Source and destination stores must be different.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO transfers (product_id, from_store_id, to_store_id, quantity, requested_by, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$productId, $fromStore, $toStore, $quantity, current_user()['id'], trim($_POST['notes'] ?? '') ?: null]);
            $id = (int) $pdo->lastInsertId();
            log_audit('create', 'transfer', $id, null, $_POST);
            flash_set('success', 'Transfer request submitted.');
        } catch (PDOException $e) {
            error_log('Create transfer failed: ' . $e->getMessage());
            flash_set('danger', 'Could not create the transfer request.');
        }
    } else {
        flash_set('danger', implode(' ', $errors));
    }
    redirect('transfers.php');
}

// ---- Handle Status Change (approve / complete / cancel) -----------------
if (is_post() && isset($_POST['change_status'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM transfers WHERE id = ?');
    $stmt->execute([$id]);
    $transfer = $stmt->fetch();

    if (!$transfer) {
        flash_set('danger', 'Transfer not found.');
        redirect('transfers.php');
    }

    $allowedTransitions = [
        'pending' => ['approved' => 'manager', 'cancelled' => 'manager'],
        'approved' => ['in_transit' => 'warehouse', 'cancelled' => 'manager'],
        'in_transit' => ['completed' => 'warehouse', 'cancelled' => 'manager'],
    ];

    $currentStatus = $transfer['status'];
    if (!isset($allowedTransitions[$currentStatus][$newStatus])) {
        flash_set('danger', 'That status change is not allowed from the current state.');
        redirect('transfers.php');
    }

    $requiredLevel = $allowedTransitions[$currentStatus][$newStatus];
    $allowed = $requiredLevel === 'manager' ? $canApprove : $canFulfill;
    if (!$allowed) {
        http_response_code(403);
        require __DIR__ . '/errors/403.php';
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($newStatus === 'completed') {
            // Move real stock: decrement source, increment destination.
            $src = $pdo->prepare('SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ? FOR UPDATE');
            $src->execute([$transfer['product_id'], $transfer['from_store_id']]);
            $srcRow = $src->fetch();

            if (!$srcRow || (int) $srcRow['quantity'] < (int) $transfer['quantity']) {
                throw new RuntimeException('Source store no longer has enough stock to complete this transfer.');
            }

            $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ?')
                ->execute([$transfer['quantity'], $srcRow['id']]);

            $dst = $pdo->prepare('SELECT id FROM inventory WHERE product_id = ? AND store_id = ? FOR UPDATE');
            $dst->execute([$transfer['product_id'], $transfer['to_store_id']]);
            $dstRow = $dst->fetch();

            if ($dstRow) {
                $pdo->prepare('UPDATE inventory SET quantity = quantity + ?, last_received_date = CURDATE() WHERE id = ?')
                    ->execute([$transfer['quantity'], $dstRow['id']]);
            } else {
                $pdo->prepare('INSERT INTO inventory (product_id, store_id, quantity, last_received_date) VALUES (?, ?, ?, CURDATE())')
                    ->execute([$transfer['product_id'], $transfer['to_store_id'], $transfer['quantity']]);
            }
        }

        $updateFields = ['status = :status'];
        $params = [':status' => $newStatus, ':id' => $id];
        if ($newStatus === 'approved') { $updateFields[] = 'approved_by = :uid'; $params[':uid'] = current_user()['id']; }
        if ($newStatus === 'in_transit') { $updateFields[] = 'delivered_by = :uid'; $params[':uid'] = current_user()['id']; }
        if ($newStatus === 'completed') { $updateFields[] = 'received_by = :uid'; $params[':uid'] = current_user()['id']; }

        $pdo->prepare('UPDATE transfers SET ' . implode(', ', $updateFields) . ' WHERE id = :id')->execute($params);

        $pdo->commit();
        log_audit('status_change', 'transfer', $id, ['status' => $currentStatus], ['status' => $newStatus]);
        flash_set('success', 'Transfer updated to "' . str_replace('_', ' ', $newStatus) . '".');
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        flash_set('danger', $e->getMessage());
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('Transfer status change failed: ' . $e->getMessage());
        flash_set('danger', 'Could not update the transfer.');
    }
    redirect('transfers.php');
}

// ---- Data -----------------------------------------------------------
$stores = $pdo->query('SELECT id, name FROM stores ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT id, name, sku FROM products WHERE status = "active" ORDER BY name')->fetchAll();

$transfers = $pdo->query(
    "SELECT t.*, p.name AS product_name, fs.name AS from_store, ts.name AS to_store, u.full_name AS requested_by_name
     FROM transfers t
     JOIN products p ON p.id = t.product_id
     JOIN stores fs ON fs.id = t.from_store_id
     JOIN stores ts ON ts.id = t.to_store_id
     JOIN users u ON u.id = t.requested_by
     ORDER BY t.created_at DESC
     LIMIT 100"
)->fetchAll();

function transfer_badge(string $status): string
{
    return match ($status) {
        'pending' => 'warning',
        'approved' => 'info',
        'in_transit' => 'primary',
        'completed' => 'success',
        'cancelled' => 'secondary',
        default => 'secondary',
    };
}

$pageTitle = 'Inventory Transfers';
require __DIR__ . '/includes/header.php';
?>

<?php if ($canRequest): ?>
<div class="d-flex justify-content-end mb-3">
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addTransferModal">
    <i class="bi bi-plus-lg me-1"></i> New Transfer Request
  </button>
</div>
<?php endif; ?>

<div class="card shadow mb-4">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr><th>Date</th><th>Product</th><th>From</th><th>To</th><th>Qty</th><th>Requested By</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($transfers)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No transfers yet.</td></tr>
          <?php else: foreach ($transfers as $t): ?>
          <tr>
            <td><?= e(date('M j, Y', strtotime($t['transfer_date']))) ?></td>
            <td><?= e($t['product_name']) ?></td>
            <td><?= e($t['from_store']) ?></td>
            <td><?= e($t['to_store']) ?></td>
            <td><?= (int) $t['quantity'] ?></td>
            <td><?= e($t['requested_by_name']) ?></td>
            <td><span class="badge bg-<?= transfer_badge($t['status']) ?>"><?= e(str_replace('_', ' ', ucfirst($t['status']))) ?></span></td>
            <td class="text-nowrap">
              <?php if ($t['status'] === 'pending' && $canApprove): ?>
                <form method="POST" action="transfers.php" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="change_status" value="1">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="new_status" value="approved">
                  <button class="btn btn-sm btn-outline-success">Approve</button>
                </form>
                <form method="POST" action="transfers.php" class="d-inline" data-confirm="Cancel this transfer request?">
                  <?= csrf_field() ?><input type="hidden" name="change_status" value="1">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="new_status" value="cancelled">
                  <button class="btn btn-sm btn-outline-danger">Cancel</button>
                </form>
              <?php elseif ($t['status'] === 'approved' && $canFulfill): ?>
                <form method="POST" action="transfers.php" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="change_status" value="1">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="new_status" value="in_transit">
                  <button class="btn btn-sm btn-outline-primary">Mark In Transit</button>
                </form>
              <?php elseif ($t['status'] === 'in_transit' && $canFulfill): ?>
                <form method="POST" action="transfers.php" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="change_status" value="1">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="new_status" value="completed">
                  <button class="btn btn-sm btn-outline-success">Mark Received</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($canRequest): ?>
<div class="modal fade" id="addTransferModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="transfers.php">
        <?= csrf_field() ?>
        <input type="hidden" name="add_transfer" value="1">
        <div class="modal-header">
          <h5 class="modal-title">New Transfer Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Product</label>
            <select class="form-select" name="product_id" required>
              <?php foreach ($products as $p): ?>
              <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">From Store</label>
            <select class="form-select" name="from_store_id" required>
              <?php foreach ($stores as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">To Store</label>
            <select class="form-select" name="to_store_id" required>
              <?php foreach ($stores as $s): ?>
              <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Quantity</label>
            <input type="number" class="form-control" name="quantity" min="1" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Notes (optional)</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
