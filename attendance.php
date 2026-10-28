<?php
require_once __DIR__ . '/config/config.php';
require_role('admin', 'manager', 'mini_manager');

$pdo = db();
$user = current_user();
$assignedStoreId = (int) ($user['store_id'] ?? 0);
$canViewAll = is_role('admin', 'manager');
$canViewStore = !$canViewAll && is_role('mini_manager') && $assignedStoreId > 0;

function attendance_staff(PDO $pdo, int $staffId, bool $canViewAll, int $assignedStoreId): array
{
    $stmt = $pdo->prepare("SELECT id, full_name, store_id FROM users
        WHERE id = ? AND status = 'active'
          AND NOT FIND_IN_SET('admin', COALESCE(NULLIF(roles, ''), role))
          AND NOT FIND_IN_SET('manager', COALESCE(NULLIF(roles, ''), role))");
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();
    if (!$staff) throw new RuntimeException('Staff member not found.');
    if (!$canViewAll && ($assignedStoreId <= 0 || (int) $staff['store_id'] !== $assignedStoreId)) {
        throw new RuntimeException('You can mark attendance only for staff in your assigned store.');
    }
    return $staff;
}

if (is_post() && isset($_POST['add_staff'])) {
    require_role('admin');
    verify_csrf();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $storeId = (int) ($_POST['store_id'] ?? 0);
    $errors = [];
    if ($fullName === '') $errors[] = 'Staff name is required.';
    if (strlen($fullName) > 100) $errors[] = 'Staff name is too long.';
    if (strlen($phone) > 20) $errors[] = 'Phone number is too long.';
    $storeStmt = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND status = 'active'");
    $storeStmt->execute([$storeId]);
    if (!$storeStmt->fetchColumn()) $errors[] = 'Select an active store.';
    if (!$errors) {
        try {
            $username = 'staff-' . date('YmdHis') . '-' . random_int(100, 999);
            $password = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, phone, role, roles, store_id, status) VALUES (?, ?, ?, ?, 'staff', 'staff', ?, 'active')");
            $stmt->execute([$username, $password, $fullName, $phone ?: null, $storeId]);
            $staffId = (int) $pdo->lastInsertId();
            log_audit('create', 'staff', $staffId, null, ['full_name' => $fullName, 'phone' => $phone ?: null, 'store_id' => $storeId]);
            flash_set('success', $fullName . ' was added to the staff attendance list.');
        } catch (PDOException $e) {
            error_log('Add attendance staff failed: ' . $e->getMessage());
            flash_set('danger', 'Could not add the staff member.');
        }
    } else {
        flash_set('danger', implode(' ', array_unique($errors)));
    }
    redirect('attendance.php');
}

if (is_post() && isset($_POST['clock_in'])) {
    verify_csrf();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    try {
        $staff = attendance_staff($pdo, $staffId, $canViewAll, $assignedStoreId);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM attendance WHERE user_id = ? AND attendance_date = CURDATE() FOR UPDATE');
        $stmt->execute([$staffId]);
        if ($stmt->fetchColumn()) throw new RuntimeException($staff['full_name'] . ' is already marked present today.');
        $pdo->prepare('INSERT INTO attendance (user_id, store_id, attendance_date, clock_in) VALUES (?, ?, CURDATE(), NOW())')
            ->execute([$staffId, (int) $staff['store_id'] ?: null]);
        $attendanceId = (int) $pdo->lastInsertId();
        $pdo->commit();
        log_audit('clock_in', 'attendance', $attendanceId, null, ['staff_id' => $staffId, 'marked_by' => (int) $user['id']]);
        flash_set('success', $staff['full_name'] . ' was clocked in at ' . date('g:i A') . '.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Attendance clock-in failed: ' . $e->getMessage());
        flash_set('danger', 'Could not clock in.');
    }
    redirect('attendance.php');
}

if (is_post() && isset($_POST['clock_out'])) {
    verify_csrf();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    try {
        $staff = attendance_staff($pdo, $staffId, $canViewAll, $assignedStoreId);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, clock_in, clock_out FROM attendance WHERE user_id = ? AND attendance_date = CURDATE() FOR UPDATE');
        $stmt->execute([$staffId]);
        $record = $stmt->fetch();
        if (!$record) throw new RuntimeException($staff['full_name'] . ' has not been clocked in today.');
        if ($record['clock_out']) throw new RuntimeException($staff['full_name'] . ' has already been clocked out today.');
        $pdo->prepare('UPDATE attendance SET clock_out = NOW() WHERE id = ?')->execute([(int) $record['id']]);
        $pdo->commit();
        log_audit('clock_out', 'attendance', (int) $record['id'], ['clock_out' => null], ['clock_out' => date('Y-m-d H:i:s')]);
        flash_set('success', $staff['full_name'] . ' was clocked out at ' . date('g:i A') . '.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Attendance clock-out failed: ' . $e->getMessage());
        flash_set('danger', 'Could not clock out.');
    }
    redirect('attendance.php');
}

if (is_post() && isset($_POST['delete_attendance'])) {
    require_role('admin');
    verify_csrf();
    $attendanceId = (int) ($_POST['attendance_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ? FOR UPDATE');
        $stmt->execute([$attendanceId]);
        $record = $stmt->fetch();
        if (!$record) throw new RuntimeException('Attendance record not found.');
        $pdo->prepare('DELETE FROM attendance WHERE id = ?')->execute([$attendanceId]);
        $pdo->commit();
        log_audit('delete', 'attendance', $attendanceId, $record, null);
        flash_set('success', 'Attendance record deleted.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('warning', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('danger', 'Could not delete the attendance record.');
    }
    redirect('attendance.php');
}

$dateFilter = trim((string) ($_GET['attendance_date'] ?? date('Y-m-d')));
$parsedDate = DateTime::createFromFormat('!Y-m-d', $dateFilter);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateFilter) $dateFilter = date('Y-m-d');
$search = trim((string) ($_GET['q'] ?? ''));
$storeFilter = $canViewAll ? (int) ($_GET['store_id'] ?? 0) : ($canViewStore ? $assignedStoreId : 0);

$conditions = ['a.attendance_date = :attendance_date'];
$params = [':attendance_date' => $dateFilter];
if ($canViewAll && $storeFilter > 0) { $conditions[] = 'a.store_id = :store_id'; $params[':store_id'] = $storeFilter; }
elseif ($canViewStore) { $conditions[] = 'a.store_id = :assigned_store'; $params[':assigned_store'] = $assignedStoreId; }
elseif (!$canViewAll) { $conditions[] = '1 = 0'; }
$conditions[] = "NOT FIND_IN_SET('admin', COALESCE(NULLIF(u.roles, ''), u.role))";
$conditions[] = "NOT FIND_IN_SET('manager', COALESCE(NULLIF(u.roles, ''), u.role))";
if ($search !== '' && ($canViewAll || $canViewStore)) { $conditions[] = '(u.full_name LIKE :q OR u.username LIKE :q2)'; $params[':q'] = $params[':q2'] = '%' . $search . '%'; }

$stmt = $pdo->prepare('SELECT a.*, u.full_name, u.username, u.role, s.name AS store_name,
    CASE WHEN a.clock_out IS NULL THEN NULL ELSE TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out) END AS worked_minutes
    FROM attendance a JOIN users u ON u.id = a.user_id LEFT JOIN stores s ON s.id = a.store_id
    WHERE ' . implode(' AND ', $conditions) . ' ORDER BY a.clock_in DESC');
$stmt->execute($params);
$records = $stmt->fetchAll();

$stores = $canViewAll ? $pdo->query("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name")->fetchAll() : [];
$presentCount = count($records);
$clockedInCount = count(array_filter($records, static fn (array $row): bool => $row['clock_out'] === null));
$completedCount = $presentCount - $clockedInCount;
$workedMinutes = array_sum(array_map(static fn (array $row): int => (int) ($row['worked_minutes'] ?? 0), $records));

$staffConditions = [
    "u.status = 'active'",
    "NOT FIND_IN_SET('admin', COALESCE(NULLIF(u.roles, ''), u.role))",
    "NOT FIND_IN_SET('manager', COALESCE(NULLIF(u.roles, ''), u.role))",
];
$staffParams = [];
if ($canViewAll && $storeFilter > 0) { $staffConditions[] = 'u.store_id = :roster_store'; $staffParams[':roster_store'] = $storeFilter; }
elseif ($canViewStore) { $staffConditions[] = 'u.store_id = :roster_assigned'; $staffParams[':roster_assigned'] = $assignedStoreId; }
elseif (!$canViewAll) { $staffConditions[] = '1 = 0'; }
$staffStmt = $pdo->prepare("SELECT u.id, u.full_name, u.phone, u.store_id, s.name AS store_name,
    a.id AS attendance_id, a.clock_in, a.clock_out
    FROM users u LEFT JOIN stores s ON s.id = u.store_id
    LEFT JOIN attendance a ON a.user_id = u.id AND a.attendance_date = CURDATE()
    WHERE " . implode(' AND ', $staffConditions) . ' ORDER BY u.full_name');
$staffStmt->execute($staffParams);
$staffMembers = $staffStmt->fetchAll();

$monthFilter = trim((string) ($_GET['month'] ?? date('Y-m')));
$parsedMonth = DateTime::createFromFormat('!Y-m', $monthFilter);
if (!$parsedMonth || $parsedMonth->format('Y-m') !== $monthFilter) {
    $parsedMonth = new DateTime('first day of this month');
    $monthFilter = $parsedMonth->format('Y-m');
}
$monthStart = $parsedMonth->format('Y-m-01');
$monthEnd = (clone $parsedMonth)->modify('first day of next month')->format('Y-m-d');
$summaryConditions = [
    "u.status = 'active'",
    "NOT FIND_IN_SET('admin', COALESCE(NULLIF(u.roles, ''), u.role))",
    "NOT FIND_IN_SET('manager', COALESCE(NULLIF(u.roles, ''), u.role))",
];
$summaryParams = [':month_start' => $monthStart, ':month_end' => $monthEnd];
if ($canViewAll && $storeFilter > 0) { $summaryConditions[] = 'u.store_id = :summary_store'; $summaryParams[':summary_store'] = $storeFilter; }
elseif ($canViewStore) { $summaryConditions[] = 'u.store_id = :summary_assigned'; $summaryParams[':summary_assigned'] = $assignedStoreId; }
elseif (!$canViewAll) { $summaryConditions[] = '1 = 0'; }
$summaryStmt = $pdo->prepare("SELECT u.id, u.full_name, u.role, u.roles, s.name AS store_name,
    COUNT(DISTINCT a.attendance_date) AS days_present,
    COUNT(DISTINCT CASE WHEN a.clock_out IS NOT NULL THEN a.attendance_date END) AS completed_days,
    COALESCE(SUM(CASE WHEN a.clock_out IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out) ELSE 0 END), 0) AS worked_minutes
    FROM users u LEFT JOIN stores s ON s.id = u.store_id
    LEFT JOIN attendance a ON a.user_id = u.id AND a.attendance_date >= :month_start AND a.attendance_date < :month_end
    WHERE " . implode(' AND ', $summaryConditions) . '
    GROUP BY u.id, u.full_name, u.role, u.roles, s.name
    ORDER BY days_present DESC, u.full_name');
$summaryStmt->execute($summaryParams);
$monthlySummary = $summaryStmt->fetchAll();
$monthAttendanceDays = array_sum(array_map(static fn (array $row): int => (int) $row['days_present'], $monthlySummary));

$pageTitle = 'Attendance';
require __DIR__ . '/includes/header.php';
?>

<section class="attendance-hero mb-4">
  <div><span class="attendance-overline"><i class="bi bi-calendar2-check me-2"></i>Staff attendance</span><h2>Mark attendance for your team.</h2><p>All active team members except administrators and managers are included, whether or not they use the system.</p></div>
  <div class="d-flex align-items-center gap-2"><?php if (is_role('admin')): ?><button class="btn attendance-in-button px-3" type="button" data-bs-toggle="modal" data-bs-target="#addAttendanceStaffModal"><i class="bi bi-person-plus me-1"></i>Add staff</button><?php endif; ?><div class="attendance-clock"><span><?= e(date('l, F j, Y')) ?></span><strong id="attendanceLiveClock"><?= e(date('g:i:s A')) ?></strong></div></div>
</section>

<section class="attendance-panel mb-4">
  <header class="attendance-panel-header"><div><span class="attendance-section-label">Today's team</span><h3>Mark staff attendance</h3></div><span class="attendance-result-count"><?= count($staffMembers) ?> staff member<?= count($staffMembers) === 1 ? '' : 's' ?></span></header>
  <div class="table-responsive"><table class="table attendance-table align-middle mb-0"><thead><tr><th>Staff member</th><th>Store</th><th>Today</th><th class="text-end">Mark attendance</th></tr></thead><tbody><?php if (!$staffMembers): ?><tr><td colspan="4"><div class="attendance-empty"><i class="bi bi-person-plus"></i><strong>No staff members found</strong><span><?php if (is_role('admin')): ?>Use Add staff to create a non-login staff record.<?php else: ?>Ask an administrator to add staff to your store.<?php endif; ?></span></div></td></tr><?php else: foreach ($staffMembers as $staff): ?><tr><td><div class="attendance-person"><span><?= e(strtoupper(substr($staff['full_name'], 0, 1))) ?></span><div><strong><?= e($staff['full_name']) ?></strong><small><?= e($staff['phone'] ?: 'No phone') ?></small></div></div></td><td><?= e($staff['store_name'] ?: 'No store') ?></td><td><?php if (!$staff['attendance_id']): ?><span class="attendance-status">Not marked</span><?php elseif (!$staff['clock_out']): ?><span class="attendance-status active"><i class="bi bi-clock"></i>In since <?= e(date('g:i A', strtotime($staff['clock_in']))) ?></span><?php else: ?><span class="attendance-status complete"><i class="bi bi-check-circle"></i><?= e(date('g:i A', strtotime($staff['clock_in']))) ?> — <?= e(date('g:i A', strtotime($staff['clock_out']))) ?></span><?php endif; ?></td><td class="text-end"><?php if (!$staff['attendance_id']): ?><form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="staff_id" value="<?= (int) $staff['id'] ?>"><button class="btn attendance-in-button px-3" name="clock_in" value="1"><i class="bi bi-box-arrow-in-right"></i>Clock in</button></form><?php elseif (!$staff['clock_out']): ?><form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="staff_id" value="<?= (int) $staff['id'] ?>"><button class="btn attendance-out-button px-3" name="clock_out" value="1"><i class="bi bi-box-arrow-right"></i>Clock out</button></form><?php else: ?><span class="attendance-done d-inline-block"><i class="bi bi-check2-circle"></i>Completed</span><?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
</section>

<section class="attendance-metrics mb-4"><div><span class="attendance-metric-icon present"><i class="bi bi-people"></i></span><span>Present</span><strong><?= $presentCount ?></strong><small><?= e(date('M j, Y', strtotime($dateFilter))) ?></small></div><div><span class="attendance-metric-icon active"><i class="bi bi-clock-history"></i></span><span>Currently in</span><strong><?= $clockedInCount ?></strong><small>Active shifts</small></div><div><span class="attendance-metric-icon complete"><i class="bi bi-check2-circle"></i></span><span>Clocked out</span><strong><?= $completedCount ?></strong><small>Completed shifts</small></div><div><span class="attendance-metric-icon hours"><i class="bi bi-hourglass-split"></i></span><span>Worked time</span><strong><?= intdiv($workedMinutes, 60) ?>h <?= $workedMinutes % 60 ?>m</strong><small>Completed shifts</small></div></section>

<section class="attendance-panel">
  <header class="attendance-panel-header"><div><span class="attendance-section-label">Daily register</span><h3>Attendance records</h3></div><span class="attendance-result-count"><?= count($records) ?> record<?= count($records) === 1 ? '' : 's' ?></span></header>
  <form class="attendance-filters" method="GET"><div class="attendance-search"><i class="bi bi-search"></i><input class="form-control" type="search" name="q" value="<?= old($_GET, 'q') ?>" placeholder="Search staff" <?= !$canViewAll && !$canViewStore ? 'disabled' : '' ?>></div><?php if ($canViewAll): ?><select class="form-select" name="store_id"><option value="0">All stores</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= $storeFilter === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select><?php endif; ?><input class="form-control" type="date" name="attendance_date" value="<?= e($dateFilter) ?>"><button class="btn attendance-filter-button"><i class="bi bi-search"></i>View</button><a class="btn attendance-clear-button" href="attendance.php"><i class="bi bi-x-lg"></i></a></form>
  <div class="table-responsive"><table class="table attendance-table align-middle mb-0"><thead><tr><th>Staff member</th><th>Store</th><th>Clock in</th><th>Clock out</th><th>Worked</th><th>Status</th><?php if (is_role('admin')): ?><th class="text-end">Action</th><?php endif; ?></tr></thead><tbody><?php if (!$records): ?><tr><td colspan="<?= is_role('admin') ? 7 : 6 ?>"><div class="attendance-empty"><i class="bi bi-calendar2-x"></i><strong>No attendance recorded</strong><span>No matching staff clocked in on this date.</span></div></td></tr><?php else: foreach ($records as $record): ?><tr><td><div class="attendance-person"><span><?= e(strtoupper(substr($record['full_name'], 0, 1))) ?></span><div><strong><?= e($record['full_name']) ?></strong><small><?= e(ucwords(str_replace('_', ' ', $record['role']))) ?> · @<?= e($record['username']) ?></small></div></div></td><td><?= e($record['store_name'] ?: 'Head office') ?></td><td><strong><?= e(date('g:i A', strtotime($record['clock_in']))) ?></strong></td><td><?= $record['clock_out'] ? e(date('g:i A', strtotime($record['clock_out']))) : '—' ?></td><td><?= $record['worked_minutes'] !== null ? intdiv((int) $record['worked_minutes'], 60) . 'h ' . ((int) $record['worked_minutes'] % 60) . 'm' : 'In progress' ?></td><td><span class="attendance-status <?= $record['clock_out'] ? 'complete' : 'active' ?>"><i class="bi bi-<?= $record['clock_out'] ? 'check-circle' : 'clock' ?>"></i><?= $record['clock_out'] ? 'Completed' : 'Clocked in' ?></span></td><?php if (is_role('admin')): ?><td class="text-end"><form method="POST" data-confirm="Delete this attendance record for <?= e($record['full_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="delete_attendance" value="1"><input type="hidden" name="attendance_id" value="<?= (int) $record['id'] ?>"><button class="btn attendance-delete-button"><i class="bi bi-trash3"></i></button></form></td><?php endif; ?></tr><?php endforeach; endif; ?></tbody></table></div>
</section>

<section class="attendance-panel mt-4">
  <header class="attendance-panel-header"><div><span class="attendance-section-label">Monthly totals</span><h3>Days workers came to work</h3></div><span class="attendance-result-count"><?= $monthAttendanceDays ?> attendance day<?= $monthAttendanceDays === 1 ? '' : 's' ?></span></header>
  <form class="attendance-filters" method="GET"><div class="d-flex align-items-center gap-2 flex-grow-1"><label class="form-label mb-0 text-nowrap" for="attendanceMonth">Select month</label><input class="form-control" id="attendanceMonth" type="month" name="month" value="<?= e($monthFilter) ?>"></div><?php if ($canViewAll && $storeFilter > 0): ?><input type="hidden" name="store_id" value="<?= $storeFilter ?>"><?php endif; ?><button class="btn attendance-filter-button"><i class="bi bi-calendar3"></i>View month</button></form>
  <div class="table-responsive"><table class="table attendance-table align-middle mb-0"><thead><tr><th>Worker</th><th>Store</th><th>Days present</th><th>Completed days</th><th>Total hours</th></tr></thead><tbody><?php if (!$monthlySummary): ?><tr><td colspan="5"><div class="attendance-empty"><i class="bi bi-calendar2-x"></i><strong>No workers found</strong></div></td></tr><?php else: foreach ($monthlySummary as $summary): ?><tr><td><div class="attendance-person"><span><?= e(strtoupper(substr($summary['full_name'], 0, 1))) ?></span><div><strong><?= e($summary['full_name']) ?></strong><small><?= e(ucwords(str_replace('_', ' ', $summary['role']))) ?></small></div></div></td><td><?= e($summary['store_name'] ?: 'No store') ?></td><td><strong class="attendance-days-count"><?= (int) $summary['days_present'] ?> day<?= (int) $summary['days_present'] === 1 ? '' : 's' ?></strong></td><td><?= (int) $summary['completed_days'] ?> day<?= (int) $summary['completed_days'] === 1 ? '' : 's' ?></td><td><?= intdiv((int) $summary['worked_minutes'], 60) ?>h <?= (int) $summary['worked_minutes'] % 60 ?>m</td></tr><?php endforeach; endif; ?></tbody></table></div>
</section>

<?php if (is_role('admin')): ?><div class="modal fade" id="addAttendanceStaffModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST"><?= csrf_field() ?><input type="hidden" name="add_staff" value="1"><div class="modal-header"><div><h5 class="modal-title"><i class="bi bi-person-plus me-2 text-success"></i>Add non-login staff</h5><small>This staff member will appear only in attendance and cannot sign in.</small></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-12"><label class="form-label">Full name</label><input class="form-control" name="full_name" maxlength="100" required></div><div class="col-12"><label class="form-label">Phone <span class="text-muted">(optional)</span></label><input class="form-control" name="phone" maxlength="20"></div><div class="col-12"><label class="form-label">Store</label><select class="form-select" name="store_id" required><option value="">Select a store</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>"><?= e($store['name']) ?></option><?php endforeach; ?></select></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn attendance-in-button px-3"><i class="bi bi-person-plus"></i>Add staff</button></div></form></div></div></div><?php endif; ?>

<?php
$extraScripts = '<script>const attendanceClock = document.getElementById("attendanceLiveClock"); if (attendanceClock) setInterval(function () { attendanceClock.textContent = new Date().toLocaleTimeString([], {hour: "numeric", minute: "2-digit", second: "2-digit"}); }, 1000);</script>';
require __DIR__ . '/includes/footer.php';
?>
