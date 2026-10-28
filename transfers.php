<?php
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = db();
$canRequest = is_role('admin', 'manager', 'mini_manager', 'warehouse');
$canApprove = is_role('admin', 'manager', 'mini_manager');
$canFulfill = is_role('admin', 'warehouse');
$miniManagerStoreId = is_role('mini_manager') && !is_role('admin', 'manager', 'warehouse') ? (int) (current_user()['store_id'] ?? 0) : 0;

// ---- Create transfer request -----------------------------------------
if (is_post() && isset($_POST['add_transfer'])) {
    require_role('admin', 'manager', 'mini_manager', 'warehouse');
    verify_csrf();

    $productId = (int) ($_POST['product_id'] ?? 0);
    $fromStore = (int) ($_POST['from_store_id'] ?? 0);
    $toStore = (int) ($_POST['to_store_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $errors = validate($_POST, [
        'product_id' => 'required|int',
        'from_store_id' => 'required|int',
        'to_store_id' => 'required|int',
        'quantity' => 'required|int|positive',
    ]);

    if ($quantity < 1) $errors[] = 'Quantity must be at least 1.';
    if ($fromStore === $toStore && $fromStore !== 0) $errors[] = 'Source and destination stores must be different.';
    if ($miniManagerStoreId > 0 && $fromStore !== $miniManagerStoreId) $errors[] = 'Mini managers can request transfers only from their assigned store.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO transfers (product_id, from_store_id, to_store_id, quantity, requested_by, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $productId,
                $fromStore,
                $toStore,
                $quantity,
                current_user()['id'],
                trim($_POST['notes'] ?? '') ?: null,
            ]);
            $id = (int) $pdo->lastInsertId();
            log_audit('create', 'transfer', $id, null, [
                'product_id' => $productId,
                'from_store_id' => $fromStore,
                'to_store_id' => $toStore,
                'quantity' => $quantity,
            ]);
            flash_set('success', 'Transfer request submitted.');
        } catch (PDOException $e) {
            error_log('Create transfer failed: ' . $e->getMessage());
            flash_set('danger', 'Could not create the transfer request.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('transfers.php');
}

// ---- Move a transfer through its workflow ----------------------------
if (is_post() && isset($_POST['change_status'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';

    $managerStatuses = ['approved', 'cancelled'];
    $warehouseStatuses = ['in_transit', 'completed'];
    if ((in_array($newStatus, $managerStatuses, true) && !$canApprove)
        || (in_array($newStatus, $warehouseStatuses, true) && !$canFulfill)
        || !in_array($newStatus, array_merge($managerStatuses, $warehouseStatuses), true)) {
        http_response_code(403);
        require __DIR__ . '/errors/403.php';
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT t.*, s.manager_id AS destination_manager_id FROM transfers t JOIN stores s ON s.id = t.to_store_id WHERE t.id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $transfer = $stmt->fetch();
        if (!$transfer) throw new RuntimeException('Transfer not found.');

        $canManageDestination = is_role('admin')
            || (is_role('manager') && (int) $transfer['destination_manager_id'] === (int) current_user()['id'])
            || (is_role('mini_manager') && (int) current_user()['store_id'] === (int) $transfer['to_store_id']);
        if (in_array($newStatus, $managerStatuses, true) && !$canManageDestination) {
            $pdo->rollBack();
            http_response_code(403);
            require __DIR__ . '/errors/403.php';
            exit;
        }

        $allowedTransitions = [
            'pending' => ['approved', 'cancelled'],
            'approved' => ['in_transit', 'cancelled'],
            'in_transit' => ['completed', 'cancelled'],
        ];
        $currentStatus = $transfer['status'];
        if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw new RuntimeException('That status change is not allowed from the current state.');
        }

        if ($newStatus === 'completed') {
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

        $fields = ['status = :status'];
        $params = [':status' => $newStatus, ':id' => $id];
        if ($newStatus === 'approved') { $fields[] = 'approved_by = :uid'; $params[':uid'] = current_user()['id']; }
        if ($newStatus === 'in_transit') { $fields[] = 'delivered_by = :uid'; $params[':uid'] = current_user()['id']; }
        if ($newStatus === 'completed') { $fields[] = 'received_by = :uid'; $params[':uid'] = current_user()['id']; }

        $pdo->prepare('UPDATE transfers SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        $pdo->commit();

        log_audit('status_change', 'transfer', $id, ['status' => $currentStatus], ['status' => $newStatus, 'destination_store_id' => (int) $transfer['to_store_id']]);
        flash_set('success', 'Transfer updated to ' . str_replace('_', ' ', $newStatus) . '.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Transfer status change failed: ' . $e->getMessage());
        flash_set('danger', 'Could not update the transfer.');
    }
    redirect('transfers.php');
}

// ---- Data, filters and summary ---------------------------------------
$activeStores = $pdo->query('SELECT id, name FROM stores WHERE status = "active" ORDER BY name')->fetchAll();
$filterStores = $pdo->query('SELECT id, name FROM stores ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT id, name, sku FROM products WHERE status = "active" ORDER BY name')->fetchAll();

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$storeFilter = (int) ($_GET['store_id'] ?? 0);
$validStatuses = ['all', 'pending', 'approved', 'in_transit', 'completed', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'all';

$sql = "SELECT t.*, p.name AS product_name, p.sku, fs.name AS from_store, ts.name AS to_store,
               ts.manager_id AS destination_manager_id, dm.full_name AS destination_manager_name,
               u.full_name AS requested_by_name
        FROM transfers t
        JOIN products p ON p.id = t.product_id
        JOIN stores fs ON fs.id = t.from_store_id
        JOIN stores ts ON ts.id = t.to_store_id
        LEFT JOIN users dm ON dm.id = ts.manager_id
        JOIN users u ON u.id = t.requested_by";
$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = '(p.name LIKE :q_name OR p.sku LIKE :q_sku OR u.full_name LIKE :q_user)';
    $params[':q_name'] = '%' . $search . '%';
    $params[':q_sku'] = '%' . $search . '%';
    $params[':q_user'] = '%' . $search . '%';
}
if ($statusFilter !== 'all') {
    $conditions[] = 't.status = :status';
    $params[':status'] = $statusFilter;
}
if ($storeFilter > 0) {
    $conditions[] = '(t.from_store_id = :from_store OR t.to_store_id = :to_store)';
    $params[':from_store'] = $storeFilter;
    $params[':to_store'] = $storeFilter;
}
if ($miniManagerStoreId > 0) {
    $conditions[] = '(t.from_store_id = :mini_from_store OR t.to_store_id = :mini_to_store)';
    $params[':mini_from_store'] = $miniManagerStoreId;
    $params[':mini_to_store'] = $miniManagerStoreId;
}
if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
$sql .= ' ORDER BY t.created_at DESC LIMIT 100';

$transferStmt = $pdo->prepare($sql);
$transferStmt->execute($params);
$transfers = $transferStmt->fetchAll();

$transferStoreScope = $miniManagerStoreId > 0 ? ' WHERE from_store_id = ' . $miniManagerStoreId . ' OR to_store_id = ' . $miniManagerStoreId : '';
$transferMetrics = $pdo->query("SELECT
    COUNT(*) AS total_transfers,
    COALESCE(SUM(status = 'pending'), 0) AS pending_transfers,
    COALESCE(SUM(CASE WHEN status IN ('approved', 'in_transit') THEN quantity ELSE 0 END), 0) AS units_moving,
    COALESCE(SUM(status = 'completed' AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())), 0) AS completed_month
    FROM transfers" . $transferStoreScope)->fetch();

function transfer_status_class(string $status): string
{
    return match ($status) {
        'pending' => 'pending',
        'approved' => 'approved',
        'in_transit' => 'in-transit',
        'completed' => 'completed',
        default => 'cancelled',
    };
}

function transfer_status_icon(string $status): string
{
    return match ($status) {
        'pending' => 'clock',
        'approved' => 'check2-circle',
        'in_transit' => 'truck',
        'completed' => 'check-lg',
        default => 'x-lg',
    };
}

$pageTitle = 'Inventory Transfers';
require __DIR__ . '/includes/header.php';
?>

<section class="transfer-hero mb-4">
  <div>
    <span class="transfer-overline"><i class="bi bi-arrow-left-right me-2"></i>Stock movement</span>
    <h2>Move inventory without losing sight of it.</h2>
    <p>Request, approve, dispatch, and receive store-to-store stock transfers from one clear workspace.</p>
  </div>
  <?php if ($canRequest): ?>
  <button class="btn transfer-new-button" data-bs-toggle="modal" data-bs-target="#addTransferModal" <?= !$products || count($activeStores) < 2 ? 'disabled' : '' ?>><i class="bi bi-plus-lg me-2"></i>New transfer</button>
  <?php endif; ?>
</section>

<?php if ($canRequest && (!$products || count($activeStores) < 2)): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>An active product and at least two active stores are required to create a transfer.</div>
<?php endif; ?>

<section class="transfer-kpi-grid mb-4" aria-label="Transfer summary">
  <div class="transfer-kpi transfer-kpi-total"><span class="transfer-kpi-icon"><i class="bi bi-arrow-left-right"></i></span><div><span>Total transfers</span><strong><?= (int) $transferMetrics['total_transfers'] ?></strong><small>All movement records</small></div></div>
  <div class="transfer-kpi transfer-kpi-pending"><span class="transfer-kpi-icon"><i class="bi bi-clock-history"></i></span><div><span>Awaiting approval</span><strong><?= (int) $transferMetrics['pending_transfers'] ?></strong><small>Requests needing review</small></div></div>
  <div class="transfer-kpi transfer-kpi-moving"><span class="transfer-kpi-icon"><i class="bi bi-box-seam"></i></span><div><span>Pieces moving</span><strong><?= (int) $transferMetrics['units_moving'] ?></strong><small>Approved or in transit</small></div></div>
  <div class="transfer-kpi transfer-kpi-complete"><span class="transfer-kpi-icon"><i class="bi bi-check2-circle"></i></span><div><span>Completed this month</span><strong><?= (int) $transferMetrics['completed_month'] ?></strong><small><?= e(date('F Y')) ?></small></div></div>
</section>

<section class="transfer-panel">
  <header class="transfer-panel-header">
    <div><span class="transfer-section-label">Movement register</span><h3>Transfer activity</h3></div>
    <span class="transfer-result-count"><?= count($transfers) ?> result<?= count($transfers) === 1 ? '' : 's' ?></span>
  </header>

  <form class="transfer-filters" method="GET" action="transfers.php" role="search">
    <div class="transfer-search"><i class="bi bi-search"></i><input type="search" class="form-control" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Search product, SKU, or requester"></div>
    <select name="store_id" class="form-select" aria-label="Filter by store"><option value="0">All stores</option><?php foreach ($filterStores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select>
    <select name="status" class="form-select" aria-label="Filter by status"><option value="all">All statuses</option><option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option><option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option><option value="in_transit" <?= $statusFilter === 'in_transit' ? 'selected' : '' ?>>In transit</option><option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option><option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select>
    <button class="btn transfer-filter-button" type="submit"><i class="bi bi-funnel me-2"></i>Apply</button>
    <?php if ($search !== '' || $storeFilter > 0 || $statusFilter !== 'all'): ?><a href="transfers.php" class="btn transfer-clear-button" title="Clear filters" aria-label="Clear filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
  </form>

  <div class="table-responsive transfer-table-wrap">
    <table class="table align-middle transfer-table mb-0">
      <thead><tr><th>Transfer</th><th>Product</th><th>Route</th><th>Quantity</th><th>Requested by</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$transfers): ?>
        <tr><td colspan="7"><div class="transfer-empty"><i class="bi bi-arrow-left-right"></i><strong>No transfers found</strong><span>New transfer requests and matching filter results will appear here.</span></div></td></tr>
        <?php else: foreach ($transfers as $transfer):
          $status = $transfer['status'];
          $canApproveThis = is_role('admin')
            || (is_role('manager') && (int) $transfer['destination_manager_id'] === (int) current_user()['id'])
            || (is_role('mini_manager') && (int) current_user()['store_id'] === (int) $transfer['to_store_id']);
          $canCancelThis = $canApproveThis && in_array($status, ['pending', 'approved', 'in_transit'], true);
          $hasPrimaryAction = ($status === 'pending' && $canApproveThis) || ($status === 'approved' && $canFulfill) || ($status === 'in_transit' && $canFulfill);
        ?>
        <tr>
          <td><div class="transfer-reference"><span><i class="bi bi-arrow-left-right"></i></span><div><strong>TRF-<?= str_pad((string) $transfer['id'], 5, '0', STR_PAD_LEFT) ?></strong><small><?= e(date('M j, Y', strtotime($transfer['transfer_date']))) ?></small></div></div></td>
          <td><div class="transfer-product"><strong><?= e($transfer['product_name']) ?></strong><small><?= e($transfer['sku']) ?></small></div></td>
          <td><div class="transfer-route"><span title="Source store"><?= e($transfer['from_store']) ?></span><i class="bi bi-arrow-right"></i><span title="Destination store"><?= e($transfer['to_store']) ?></span></div><small class="transfer-destination-manager"><i class="bi bi-person-check"></i><?= $transfer['destination_manager_name'] ? e($transfer['destination_manager_name']) . ' approves' : 'No destination manager assigned' ?></small></td>
          <td><span class="transfer-quantity"><?= number_format((int) $transfer['quantity']) ?> <small>pieces</small></span></td>
          <td><div class="transfer-requester"><span><?= e(strtoupper(substr($transfer['requested_by_name'], 0, 1))) ?></span><div><strong><?= e($transfer['requested_by_name']) ?></strong><small><?= e(date('M j, g:i A', strtotime($transfer['created_at']))) ?></small></div></div></td>
          <td><span class="transfer-status <?= transfer_status_class($status) ?>"><i class="bi bi-<?= transfer_status_icon($status) ?>"></i><?= e(ucwords(str_replace('_', ' ', $status))) ?></span></td>
          <td class="text-end"><div class="transfer-actions">
            <?php if ($status === 'pending' && $canApproveThis): ?>
            <form method="POST" action="transfers.php"><?= csrf_field() ?><input type="hidden" name="change_status" value="1"><input type="hidden" name="id" value="<?= (int) $transfer['id'] ?>"><input type="hidden" name="new_status" value="approved"><button class="btn transfer-action-button approve" title="Approve transfer"><i class="bi bi-check2"></i><span>Approve</span></button></form>
            <?php elseif ($status === 'approved' && $canFulfill): ?>
            <form method="POST" action="transfers.php"><?= csrf_field() ?><input type="hidden" name="change_status" value="1"><input type="hidden" name="id" value="<?= (int) $transfer['id'] ?>"><input type="hidden" name="new_status" value="in_transit"><button class="btn transfer-action-button dispatch" title="Dispatch transfer"><i class="bi bi-truck"></i><span>Dispatch</span></button></form>
            <?php elseif ($status === 'in_transit' && $canFulfill): ?>
            <form method="POST" action="transfers.php" data-confirm="Receive this transfer and update both stores' stock?"><?= csrf_field() ?><input type="hidden" name="change_status" value="1"><input type="hidden" name="id" value="<?= (int) $transfer['id'] ?>"><input type="hidden" name="new_status" value="completed"><button class="btn transfer-action-button receive" title="Receive transfer"><i class="bi bi-box-arrow-in-down"></i><span>Receive</span></button></form>
            <?php endif; ?>
            <?php if ($canCancelThis): ?>
            <form method="POST" action="transfers.php" data-confirm="Cancel this transfer?"><?= csrf_field() ?><input type="hidden" name="change_status" value="1"><input type="hidden" name="id" value="<?= (int) $transfer['id'] ?>"><input type="hidden" name="new_status" value="cancelled"><button class="btn transfer-cancel-button" title="Cancel transfer" aria-label="Cancel transfer"><i class="bi bi-x-lg"></i></button></form>
            <?php elseif (!$hasPrimaryAction): ?><span class="transfer-no-action">&mdash;</span><?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($canRequest): ?>
<div class="modal fade" id="addTransferModal" tabindex="-1" aria-labelledby="addTransferTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content transfer-modal">
      <form method="POST" action="transfers.php" id="addTransferForm">
        <?= csrf_field() ?><input type="hidden" name="add_transfer" value="1">
        <div class="modal-header transfer-modal-header"><div><h5 class="modal-title" id="addTransferTitle"><i class="bi bi-arrow-left-right me-2 text-success"></i>Create transfer request</h5><small>Choose the stock, route, and quantity to move.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12 position-relative">
              <label class="form-label" for="transferProductSearch">Product</label>
              <div class="transfer-product-search"><i class="bi bi-search"></i><input type="text" class="form-control" id="transferProductSearch" placeholder="Search by product name or SKU" autocomplete="off" required><input type="hidden" name="product_id" id="transferProductId"></div>
              <div id="transferProductResults" class="transfer-search-results" role="listbox"></div>
            </div>
            <div class="col-md-5"><label class="form-label" for="transferFromStore">Source store</label><select class="form-select" name="from_store_id" id="transferFromStore" required><option value="">Select source</option><?php foreach ($activeStores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= $miniManagerStoreId > 0 && (int) $store['id'] === $miniManagerStoreId ? 'selected' : '' ?> <?= $miniManagerStoreId > 0 && (int) $store['id'] !== $miniManagerStoreId ? 'disabled' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-none d-md-flex transfer-route-arrow"><span><i class="bi bi-arrow-right"></i></span></div>
            <div class="col-md-5"><label class="form-label" for="transferToStore">Destination store</label><select class="form-select" name="to_store_id" id="transferToStore" required><option value="">Select destination</option><?php foreach ($activeStores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="transferQuantity">Quantity</label><div class="input-group"><input type="number" class="form-control" name="quantity" id="transferQuantity" min="1" step="1" placeholder="0" required><span class="input-group-text">pieces</span></div></div>
            <div class="col-md-8"><label class="form-label" for="transferNotes">Notes <span class="text-muted fw-normal">(optional)</span></label><input type="text" class="form-control" name="notes" id="transferNotes" maxlength="500" placeholder="Add handling or delivery instructions"></div>
          </div>
          <div class="transfer-workflow-note mt-4"><i class="bi bi-diagram-3"></i><div><strong>Approval workflow</strong><small>Requested → destination-store manager approval → In transit → Completed. Administrators can approve any destination; inventory updates when the destination receives the transfer.</small></div></div>
        </div>
        <div class="modal-footer border-0 px-4 pb-4 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn transfer-create-button"><i class="bi bi-send me-2"></i>Submit request</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$productJson = json_encode(array_map(static function ($product) {
        return ['id' => (int) $product['id'], 'name' => $product['name'], 'sku' => $product['sku']];
}, $products), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$extraScripts = '<script>
(function () {
  const products = ' . $productJson . ';
  const search = document.getElementById("transferProductSearch");
  const productId = document.getElementById("transferProductId");
  const results = document.getElementById("transferProductResults");
  const form = document.getElementById("addTransferForm");
  const fromStore = document.getElementById("transferFromStore");
  const toStore = document.getElementById("transferToStore");

  function escapeHtml(value) {
    const node = document.createElement("div");
    node.textContent = String(value == null ? "" : value);
    return node.innerHTML;
  }

  function renderResults(value) {
    const query = value.trim().toLowerCase();
    if (!query) { results.innerHTML = ""; results.style.display = "none"; return; }
    const matches = products.filter(function (product) {
      return product.name.toLowerCase().includes(query) || product.sku.toLowerCase().includes(query);
    }).slice(0, 8);
    if (!matches.length) {
      results.innerHTML = "<div class=\"transfer-search-empty\">No matching products found</div>";
    } else {
      results.innerHTML = matches.map(function (product) {
        return "<button type=\"button\" class=\"transfer-search-result\" data-id=\"" + product.id + "\"><span><strong>" + escapeHtml(product.name) + "</strong><small>" + escapeHtml(product.sku) + "</small></span><i class=\"bi bi-plus-circle\"></i></button>";
      }).join("");
      results.querySelectorAll(".transfer-search-result").forEach(function (button) {
        button.addEventListener("click", function () {
          const product = products.find(function (item) { return item.id === Number(button.dataset.id); });
          if (!product) return;
          search.value = product.name + " (" + product.sku + ")";
          productId.value = product.id;
          results.style.display = "none";
        });
      });
    }
    results.style.display = "block";
  }

  if (search) {
    search.addEventListener("input", function () { productId.value = ""; renderResults(search.value); });
    search.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        const first = results.querySelector(".transfer-search-result");
        if (first) first.click();
      }
    });
    document.addEventListener("click", function (event) {
      if (!search.contains(event.target) && !results.contains(event.target)) results.style.display = "none";
    });
  }

  if (form) {
    form.addEventListener("submit", function (event) {
      if (!productId.value) {
        event.preventDefault();
        alert("Please search for and select a product.");
        search.focus();
      } else if (fromStore.value === toStore.value) {
        event.preventDefault();
        alert("Source and destination stores must be different.");
        toStore.focus();
      }
    });
  }
})();
</script>';
require __DIR__ . '/includes/footer.php';
?>
