<?php
/**
 * includes/header.php
 * Expects $pageTitle to be set by the including page.
 * Requires config.php to already be loaded (session, current_user(), etc).
 */
$pageTitle = $pageTitle ?? APP_NAME;
$user = current_user();
$currentFile = basename($_SERVER['SCRIPT_NAME']);

/** Helper: css class for the active sidebar link. */
function nav_active(string $file, string $current): string
{
    return $file === $current ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <link href="assets/css/style.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

  <!-- Top Navbar -->
  <nav class="navbar navbar-expand navbar-light bg-white topbar static-top fixed-top">
    <div class="container-fluid">
      <button class="btn btn-link btn-sm text-gray-600 order-1 order-sm-0 me-3" id="sidebarToggle">
        <i class="bi bi-list"></i>
      </button>
      <a class="navbar-brand mx-1 text-danger fw-bold" href="dashboard.php"><?= e(APP_NAME) ?></a>
      <span class="text-muted small d-none d-md-inline">
        <?= e(BUSINESS_NAME) ?> &bull; <?= e(CURRENCY_CODE) ?> <?= e(CURRENCY_SYMBOL) ?>
      </span>
      <ul class="navbar-nav ms-auto">
        <li class="nav-item dropdown no-arrow">
          <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
            <span class="me-2 d-none d-lg-inline text-gray-600 small"><?= e($user['full_name'] ?? '') ?></span>
            <?php if (!empty($user['photo'])): ?>
            <img class="rounded-circle" src="<?= e($user['photo']) ?>" height="30" width="30" style="object-fit: cover;" alt="Profile">
            <?php else: ?>
            <img class="rounded-circle" src="https://ui-avatars.com/api/?name=<?= urlencode($user['full_name'] ?? 'User') ?>&background=e74a3b&color=fff" height="30" width="30" alt="Profile">
            <?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li><span class="dropdown-item-text small text-muted text-uppercase"><?= e($user['role'] ?? '') ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </nav>

  <!-- Sidebar -->
  <div class="sidebar" id="sidebar">
    <div class="d-flex align-items-center justify-content-center py-3">
      <i class="bi bi-building fs-4 me-2"></i>
      <span class="fw-bold">Yellowman</span>
    </div>
    <hr class="my-0 text-white-50">
    <ul class="nav flex-column mb-4 p-2">
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('dashboard.php', $currentFile) ?>" href="dashboard.php">
          <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
        </a>
      </li>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('products.php', $currentFile) ?>" href="products.php">
          <i class="bi bi-box-seam"></i> <span>Products</span>
        </a>
      </li>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('inventory.php', $currentFile) ?>" href="inventory.php">
          <i class="bi bi-clipboard-check"></i> <span>Inventory</span>
        </a>
      </li>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('sales.php', $currentFile) ?>" href="sales.php">
          <i class="bi bi-cart-check"></i> <span>Sales</span>
        </a>
      </li>
      <?php if (is_role('admin', 'manager', 'warehouse')): ?>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('purchases.php', $currentFile) ?>" href="purchases.php">
          <i class="bi bi-truck"></i> <span>Purchases</span>
        </a>
      </li>
      <?php endif; ?>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('transfers.php', $currentFile) ?>" href="transfers.php">
          <i class="bi bi-arrow-left-right"></i> <span>Transfers</span>
        </a>
      </li>
      <?php if (is_role('admin', 'manager')): ?>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('stores.php', $currentFile) ?>" href="stores.php">
          <i class="bi bi-shop"></i> <span>Stores</span>
        </a>
      </li>
      <?php endif; ?>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('reports.php', $currentFile) ?>" href="reports.php">
          <i class="bi bi-clipboard-data"></i> <span>Reports</span>
        </a>
      </li>
      <?php if (is_role('admin')): ?>
      <li class="nav-item mb-1">
        <a class="nav-link <?= nav_active('admin.php', $currentFile) ?>" href="admin.php">
          <i class="bi bi-people"></i> <span>User Management</span>
        </a>
      </li>
      <?php endif; ?>
    </ul>
  </div>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
      <h1 class="h3 mb-0 text-gray-800"><?= e($pageTitle) ?></h1>
    </div>

    <?php flash_render(); ?>
