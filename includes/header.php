<?php
/**
 * includes/header.php
 * Expects $pageTitle to be set by the including page.
 * Requires config.php to already be loaded (session, current_user(), etc).
 */
$pageTitle = $pageTitle ?? APP_NAME;
$user = current_user();
$userRoleLabels = array_map(
    static fn (string $role): string => ucwords(str_replace('_', ' ', $role)),
    $user['roles'] ?? [$user['role'] ?? '']
);
$currentFile = basename($_SERVER['SCRIPT_NAME']);
$adminSection = $_GET['section'] ?? '';

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
  <link href="assets/css/style.css?v=<?= (int) filemtime(ROOT_PATH . '/assets/css/style.css') ?>" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

  <nav class="navbar topbar fixed-top" aria-label="Top navigation">
    <div class="container-fluid px-3 px-lg-4">
      <div class="d-flex align-items-center min-w-0">
        <button class="navbar-menu-button me-3" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
          <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand app-brand" href="dashboard.php" aria-label="<?= e(APP_NAME) ?> dashboard">
          <span class="app-brand-mark"><i class="bi bi-boxes"></i></span>
          <span class="app-brand-copy">
            <strong><?= e(APP_NAME) ?></strong>
            <small><?= e(BUSINESS_NAME) ?></small>
          </span>
        </a>
      </div>

      <div class="topbar-page-context d-none d-lg-flex">
        <span>Workspace</span>
        <i class="bi bi-chevron-right"></i>
        <strong><?= e($pageTitle) ?></strong>
      </div>

      <div class="dropdown ms-auto">
        <button class="topbar-user" id="userDropdown" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?php if (!empty($user['photo'])): ?>
          <img src="<?= e($user['photo']) ?>" alt="" class="topbar-avatar">
          <?php else: ?>
          <span class="topbar-avatar topbar-avatar-fallback"><?= e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?></span>
          <?php endif; ?>
          <span class="topbar-user-copy d-none d-sm-block">
            <strong><?= e($user['full_name'] ?? 'User') ?></strong>
            <small><?= e(implode(' · ', $userRoleLabels)) ?></small>
          </span>
          <i class="bi bi-chevron-down topbar-user-chevron"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end topbar-user-menu" aria-labelledby="userDropdown">
          <li class="topbar-menu-heading">
            <span>Signed in as</span>
            <strong><?= e($user['username'] ?? '') ?></strong>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="profile.php"><span><i class="bi bi-person"></i></span><div><strong>My profile</strong><small>Account and password</small></div></a></li>
          <li><a class="dropdown-item text-danger" href="logout.php"><span><i class="bi bi-box-arrow-right"></i></span><div><strong>Sign out</strong><small>End this session</small></div></a></li>
        </ul>
      </div>
    </div>
  </nav>

  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-inner">
      <div class="sidebar-workspace">
        <span class="sidebar-workspace-icon"><i class="bi bi-building"></i></span>
        <div><strong>Yellowman</strong><small>Inventory workspace</small></div>
        <button class="sidebar-close d-lg-none" id="sidebarClose" type="button" aria-label="Close navigation"><i class="bi bi-x-lg"></i></button>
      </div>

      <nav class="sidebar-nav">
        <span class="sidebar-section-label">Overview</span>
        <a class="nav-link <?= nav_active('dashboard.php', $currentFile) ?>" href="dashboard.php"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></a>

        <span class="sidebar-section-label">Inventory</span>
        <a class="nav-link <?= nav_active('products.php', $currentFile) ?>" href="products.php"><i class="bi bi-box-seam"></i><span>Products</span></a>
        <a class="nav-link <?= nav_active('inventory.php', $currentFile) ?>" href="inventory.php"><i class="bi bi-clipboard-check"></i><span>Stock levels</span></a>
        <a class="nav-link <?= nav_active('sales.php', $currentFile) ?>" href="sales.php"><i class="bi bi-bag-check"></i><span>Sales</span></a>
        <?php if (is_role('admin', 'manager', 'cashier')): ?>
        <a class="nav-link <?= nav_active('machine_fees.php', $currentFile) ?>" href="machine_fees.php"><i class="bi bi-gear-wide-connected"></i><span>Machine fees</span></a>
        <?php endif; ?>
        <?php if (is_role('admin', 'manager', 'mini_manager', 'cashier', 'sales_clerk')): ?>
        <a class="nav-link <?= nav_active('deposits.php', $currentFile) ?>" href="deposits.php"><i class="bi bi-bank"></i><span>Deposits</span></a>
        <?php endif; ?>

        <span class="sidebar-section-label">Operations</span>
        <?php if (is_role('admin', 'manager', 'mini_manager')): ?>
        <a class="nav-link <?= nav_active('attendance.php', $currentFile) ?>" href="attendance.php"><i class="bi bi-calendar2-check"></i><span>Attendance</span></a>
        <?php endif; ?>
        <?php if (is_role('admin', 'manager', 'mini_manager', 'warehouse')): ?>
        <a class="nav-link <?= nav_active('purchases.php', $currentFile) ?>" href="purchases.php"><i class="bi bi-truck"></i><span>Purchases</span></a>
        <?php endif; ?>
        <a class="nav-link <?= nav_active('transfers.php', $currentFile) ?>" href="transfers.php"><i class="bi bi-arrow-left-right"></i><span>Transfers</span></a>
        <?php if (is_role('admin', 'manager')): ?>
        <a class="nav-link <?= nav_active('stores.php', $currentFile) ?>" href="stores.php"><i class="bi bi-shop"></i><span>Stores</span></a>
        <?php endif; ?>

        <span class="sidebar-section-label">Insights</span>
        <a class="nav-link <?= nav_active('reports.php', $currentFile) ?>" href="reports.php"><i class="bi bi-bar-chart-line"></i><span>Reports</span></a>

        <?php if (is_role('admin')): ?>
        <span class="sidebar-section-label">Administration</span>
        <a class="nav-link <?= $currentFile === 'admin.php' && $adminSection !== 'audit' ? 'active' : '' ?>" href="admin.php"><i class="bi bi-people"></i><span>User management</span></a>
        <a class="nav-link <?= $currentFile === 'admin.php' && $adminSection === 'audit' ? 'active' : '' ?>" href="admin.php?section=audit#auditTrail"><i class="bi bi-clipboard2-pulse"></i><span>Audit trail</span></a>
        <?php endif; ?>
      </nav>

      <div class="sidebar-footer">
        <div class="sidebar-footer-icon"><i class="bi bi-shield-check"></i></div>
        <div><strong>Secure workspace</strong><small><?= e(CURRENCY_CODE) ?> <?= e(CURRENCY_SYMBOL) ?> · Role protected</small></div>
      </div>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
    <div class="page-heading mb-4">
      <div>
        <span class="page-heading-label">Workspace</span>
        <h1><?= e($pageTitle) ?></h1>
      </div>
    </div>

    <?php flash_render(); ?>
