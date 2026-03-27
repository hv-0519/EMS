<?php
// modules/payroll/index.php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'hr');

$pageTitle   = 'Payroll';
$currentPage = 'payroll';
$pdo         = getDB();
$role        = $_SESSION['role'];

ensureWorkTrackingSchema();

// ── Month being worked on ─────────────────────────────────────────────────
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
[$yr, $mo] = explode('-', $month);
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));

$flashMsg   = '';
$flashType  = '';

function payrollCountWeekendDays(string $monthStart, string $monthEnd): int
{
  $start = new DateTimeImmutable($monthStart);
  $end   = new DateTimeImmutable($monthEnd);
  $days  = 0;
  for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
    $dow = (int)$date->format('w');
    if ($dow === 0 || $dow === 6) $days++;
  }
  return $days;
}

function payrollLeaveUnitsWithinRange(string $leaveStart, string $leaveEnd, string $rangeStart, string $rangeEnd, string $durationType = 'full_day'): float
{
  $start = max(strtotime($leaveStart), strtotime($rangeStart));
  $end   = min(strtotime($leaveEnd), strtotime($rangeEnd));
  if ($start === false || $end === false || $end < $start) return 0.0;
  if ($durationType === 'half_day') return 0.5;
  return (float)(((int)(($end - $start) / 86400)) + 1);
}

function payrollApprovedPaidLeaveUnits(PDO $pdo, int $employeeId, string $monthStart, string $monthEnd): float
{
  $stmt = $pdo->prepare("
    SELECT start_date, end_date, leave_type, duration_type
    FROM leave_requests
    WHERE employee_id = ?
      AND status = 'approved'
      AND leave_type IN ('paid','sick')
      AND start_date <= ?
      AND end_date >= ?
  ");
  $stmt->execute([$employeeId, $monthEnd, $monthStart]);
  $units = 0.0;
  foreach ($stmt->fetchAll() as $row) {
    $units += payrollLeaveUnitsWithinRange(
      (string)$row['start_date'],
      (string)$row['end_date'],
      $monthStart,
      $monthEnd,
      (string)($row['duration_type'] ?? 'full_day')
    );
  }
  return $units;
}

// ══════════════════════════════════════════════════════════════════════════
// AJAX: calc_hours — returns worked hours for an employee in a given month.
//
// FIX: Uses SUM(worked_minutes) from the `attendance` summary table.
// worked_minutes is correctly aggregated by recalcAttendanceDay() in
// auth.php after every session close — it sums all session durations
// including multi-session / break days.  Using TIMESTAMPDIFF on the
// summary check_in/check_out was WRONG for break-inclusive days.
// ══════════════════════════════════════════════════════════════════════════
if (($_GET['action'] ?? '') === 'calc_hours' && isset($_GET['employee_id'], $_GET['month'])) {
  $empId    = (int)$_GET['employee_id'];
  $mth      = $_GET['month'];
  if (!preg_match('/^\d{4}-\d{2}$/', $mth)) jsonResponse(['error' => 'Invalid month'], 400);

  $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(worked_minutes), 0)                              AS total_minutes,
            COUNT(CASE WHEN status IN ('present','half_day') THEN 1 END)  AS days_worked,
            COUNT(CASE WHEN status = 'half_day' THEN 1 END)               AS half_days
        FROM attendance
        WHERE employee_id = ?
          AND DATE_FORMAT(date, '%Y-%m') = ?
          AND status IN ('present', 'half_day')
    ");
  $stmt->execute([$empId, $mth]);
  $row = $stmt->fetch();

  $totalMins  = (int)($row['total_minutes'] ?? 0);
  $hoursFloat = round($totalMins / 60, 2);
  $daysWorked = (int)($row['days_worked'] ?? 0);

  // Also pull employee salary info for pre-filling the form
  $empStmt = $pdo->prepare('SELECT name, salary, hourly_rate FROM employees WHERE id = ?');
  $empStmt->execute([$empId]);
  $emp = $empStmt->fetch();

  jsonResponse([
    'hours'       => $hoursFloat,
    'minutes'     => $totalMins,
    'days_worked' => $daysWorked,
    'salary'      => $emp['salary']      ?? 0,
    'hourly_rate' => $emp['hourly_rate'] ?? 0,
    'name'        => $emp['name']        ?? '',
  ]);
}

// ══════════════════════════════════════════════════════════════════════════
// POST: generate / update payroll entry
// Admin only for edits after generation; HR can generate.
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'generate') {
    $empId       = (int)($_POST['employee_id'] ?? 0);
    $basicSalary = (float)($_POST['basic_salary'] ?? 0);
    $bonus       = (float)($_POST['bonus'] ?? 0);
    $deductions  = (float)($_POST['deductions'] ?? 0);
    $payMonth    = $_POST['month_year'] ?? $month;
    $payDate     = $_POST['payment_date'] ?? date('Y-m-d');
    $notes       = trim($_POST['notes'] ?? '');

    if (!$empId || $basicSalary < 0) {
      setFlash('error', 'Invalid employee or salary value.');
      redirect(APP_URL . '/modules/payroll/index.php?month=' . $month);
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $payMonth)) {
      setFlash('error', 'Invalid month format.');
      redirect(APP_URL . '/modules/payroll/index.php?month=' . $month);
    }

    $netSalary = max(0, $basicSalary + $bonus - $deductions);

    // Check if record already exists for this employee+month
    $exists = $pdo->prepare('SELECT id FROM payroll WHERE employee_id = ? AND month_year = ?');
    $exists->execute([$empId, $payMonth]);
    $existId = (int)($exists->fetchColumn() ?: 0);

    if ($existId > 0) {
      if (!hasRole('admin')) {
        setFlash('error', 'Only administrators can edit existing payroll entries.');
        redirect(APP_URL . '/modules/payroll/index.php?month=' . $month);
      }
      $pdo->prepare('
                UPDATE payroll
                SET basic_salary=?, bonus=?, deductions=?, net_salary=?,
                    payment_date=?, notes=?, created_at=NOW()
                WHERE id=?
            ')->execute([$basicSalary, $bonus, $deductions, $netSalary, $payDate, $notes, $existId]);
      logActivity('Updated payroll', "Employee ID {$empId} for {$payMonth}", 'payroll');
      setFlash('success', 'Payroll entry updated successfully.');
    } else {
      $pdo->prepare('
                INSERT INTO payroll
                    (employee_id, basic_salary, bonus, deductions, net_salary,
                     payment_date, month_year, notes, created_at)
                VALUES (?,?,?,?,?,?,?,?,NOW())
            ')->execute([$empId, $basicSalary, $bonus, $deductions, $netSalary, $payDate, $payMonth, $notes]);
      logActivity('Generated payroll', "Employee ID {$empId} for {$payMonth}", 'payroll');
      setFlash('success', 'Payroll generated successfully.');
    }
    redirect(APP_URL . '/modules/payroll/index.php?month=' . $month);
  }

  if ($action === 'delete' && hasRole('admin')) {
    $pid = (int)($_POST['payroll_id'] ?? 0);
    if ($pid > 0) {
      $pdo->prepare('DELETE FROM payroll WHERE id = ?')->execute([$pid]);
      logActivity('Deleted payroll entry', "Payroll ID {$pid}", 'payroll');
      setFlash('success', 'Payroll entry deleted.');
    }
    redirect(APP_URL . '/modules/payroll/index.php?month=' . $month);
  }
}

// ══════════════════════════════════════════════════════════════════════════
// Data for the page
// ══════════════════════════════════════════════════════════════════════════

// Active employees with their current payroll entry for this month (if any)
$employees = $pdo->prepare("
    SELECT
        e.id, e.employee_id AS emp_code, e.name, e.designation,
        e.salary, e.hourly_rate,
        d.department_name,
        p.id            AS payroll_id,
        p.basic_salary,
        p.bonus,
        p.deductions,
        p.net_salary,
        p.payment_date,
        p.notes,
        p.month_year,
        -- Correct hours: SUM of worked_minutes from attendance summary rows
        -- worked_minutes is set by recalcAttendanceDay() and reflects ALL sessions
        COALESCE((
            SELECT SUM(a.worked_minutes)
            FROM attendance a
            WHERE a.employee_id = e.id
              AND DATE_FORMAT(a.date, '%Y-%m') = ?
              AND a.status IN ('present', 'half_day', 'late')
        ), 0) AS worked_minutes_this_month,
        COALESCE((
            SELECT SUM(
                CASE
                    WHEN a.status = 'half_day' THEN 0.5
                    WHEN a.status IN ('present', 'late') THEN 1
                    ELSE 0
                END
            )
            FROM attendance a
            WHERE a.employee_id = e.id
              AND DATE_FORMAT(a.date, '%Y-%m') = ?
              AND a.status IN ('present', 'half_day', 'late')
        ), 0) AS attendance_units_this_month,
        COALESCE((
            SELECT COUNT(*)
            FROM attendance a
            WHERE a.employee_id = e.id
              AND DATE_FORMAT(a.date, '%Y-%m') = ?
              AND a.status IN ('present', 'half_day', 'late')
        ), 0) AS days_worked_this_month
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN payroll p ON p.employee_id = e.id AND p.month_year = ?
    WHERE e.status = 'active'
    ORDER BY e.name
");
$employees->execute([$month, $month, $month, $month]);
$employees = $employees->fetchAll();

$weekendDaysInMonth = payrollCountWeekendDays($monthStart, $monthEnd);
foreach ($employees as &$emp) {
  $attendanceUnits = (float)($emp['attendance_units_this_month'] ?? 0);
  $paidLeaveUnits  = payrollApprovedPaidLeaveUnits($pdo, (int)$emp['id'], $monthStart, $monthEnd);
  $payableDays     = $attendanceUnits > 0
    ? min((float)$daysInMonth, $attendanceUnits + $weekendDaysInMonth + $paidLeaveUnits)
    : 0.0;
  $dailySalary     = $daysInMonth > 0 ? round(((float)$emp['salary']) / $daysInMonth, 2) : 0.0;
  $recommendedBasic = round($dailySalary * $payableDays, 2);

  $emp['attendance_units_this_month'] = $attendanceUnits;
  $emp['paid_leave_units_this_month'] = $paidLeaveUnits;
  $emp['payable_days_this_month']     = $payableDays;
  $emp['daily_salary_this_month']     = $dailySalary;
  $emp['recommended_basic_salary']    = $recommendedBasic;
}
unset($emp);

$totalPayroll   = array_sum(array_column(array_filter($employees, fn($e) => $e['payroll_id']), 'net_salary'));
$paidCount      = count(array_filter($employees, fn($e) => $e['payroll_id']));
$unpaidCount    = count($employees) - $paidCount;

// Month navigation
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
$monthLabel = date('F Y', strtotime($month . '-01'));

$extraStyles = <<<CSS
<style>
.payroll-page {
  display: grid;
  gap: 24px;
}

.payroll-page .page-header {
  margin-bottom: 0;
  padding: 8px 0 2px;
}

.payroll-page .page-sub {
  font-size: 14px;
  color: var(--text-light);
  margin-top: 6px;
}

.payroll-page .stats-grid {
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 18px;
  margin-bottom: 0;
}

.payroll-page .stat-card {
  min-height: 128px;
  border: 1px solid rgba(148, 163, 184, .14);
  box-shadow: 0 16px 34px rgba(15, 23, 42, .06);
}

.payroll-page .stat-body {
  min-width: 0;
}

.payroll-page .stat-value {
  line-height: 1;
}

.payroll-page .card {
  overflow: hidden;
}

.payroll-page .card-header {
  padding: 18px 22px;
}

.payroll-page .card-header h2 {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 15px;
  line-height: 1.4;
}

.payroll-page .table-responsive {
  overflow-x: auto;
  overflow-y: hidden;
}

.payroll-page .table {
  width: 100%;
  min-width: 1120px;
  border-collapse: separate;
  border-spacing: 0;
}

.payroll-page .table thead th {
  background: #F8FAFC;
  color: var(--text-mid);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .02em;
  text-transform: uppercase;
  white-space: nowrap;
  padding: 16px 18px;
  border-bottom: 1px solid var(--border);
}

.payroll-page .table tbody td {
  padding: 16px 18px;
  vertical-align: middle;
  border-bottom: 1px solid rgba(229, 231, 235, .8);
}

.payroll-page .table tbody tr:last-child td {
  border-bottom: 0;
}

.payroll-page .table tbody tr:hover td {
  background: rgba(16, 185, 129, .035);
}

.payroll-page .emp-meta {
  font-size: 12px;
  color: var(--text-light);
  margin-top: 2px;
}

.payroll-page .hours-cell {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.payroll-page .hours-primary {
  font-weight: 700;
  color: var(--text-dark);
}

.payroll-page .hours-meta {
  font-size: 11px;
  color: var(--text-light);
  margin-top: 3px;
}

.payroll-page .money-cell,
.payroll-page .days-cell {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.payroll-page .money-cell.net {
  font-weight: 700;
}

.payroll-page .status-cell {
  text-align: center;
}

.payroll-page .actions-cell {
  text-align: center;
}

.payroll-page .actions-row {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  flex-wrap: nowrap;
}

.payroll-page .actions-row form {
  margin: 0;
}

.payroll-page .actions-row .btn {
  white-space: nowrap;
}

.payroll-page .month-nav {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 6px;
  background: rgba(255, 255, 255, .78);
  border: 1px solid rgba(148, 163, 184, .16);
  border-radius: 14px;
  box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
}

.payroll-page .month-label {
  min-width: 118px;
  text-align: center;
  font-size: 14px;
  font-weight: 700;
  color: var(--text-dark);
}

#payrollModal .modal-dialog {
  width: min(720px, calc(100vw - 32px));
}

#payrollModal .modal-body {
  padding-top: 18px;
}

#payrollModal .modal-header {
  align-items: flex-start;
}

#payrollModal .modal-title {
  font-size: 22px;
  line-height: 1.2;
}

#payrollModal .modal-subtitle {
  margin-top: 4px;
  color: var(--text-light);
  font-size: 13px;
}

#payrollModal .payroll-modal-summary {
  background: linear-gradient(135deg, rgba(16, 185, 129, .10), rgba(59, 130, 246, .06));
  border: 1px solid rgba(148, 163, 184, .16);
  border-radius: 14px;
  padding: 16px 18px;
  margin-bottom: 18px;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

#payrollModal .summary-label {
  font-size: 11px;
  color: var(--text-light);
  text-transform: uppercase;
  letter-spacing: .6px;
}

#payrollModal .summary-value {
  margin-top: 4px;
  font-size: 22px;
  font-weight: 700;
  color: var(--text-dark);
}

#payrollModal .summary-sub {
  margin-top: 3px;
  font-size: 11px;
  color: var(--text-light);
}

#payrollModal .summary-source {
  text-align: right;
}

#payrollModal .payroll-net-preview {
  background: linear-gradient(135deg, var(--primary), var(--primary-dark));
  color: #fff;
  border-radius: 12px;
  padding: 14px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 4px;
}

#payrollModal .payroll-net-preview strong {
  font-size: 20px;
  font-weight: 700;
}

#payrollModal .modal-actions {
  display: flex;
  gap: 10px;
  margin-top: 18px;
  justify-content: flex-end;
}

@media (max-width: 1200px) {
  .payroll-page .stats-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .payroll-page {
    gap: 18px;
  }

  .payroll-page .page-header {
    gap: 14px;
  }

  .payroll-page .month-nav {
    width: 100%;
    justify-content: space-between;
  }

  .payroll-page .stats-grid {
    grid-template-columns: 1fr;
    gap: 14px;
  }

  .payroll-page .stat-card {
    min-height: auto;
  }

  .payroll-page .card-header,
  .payroll-page .table thead th,
  .payroll-page .table tbody td {
    padding-left: 14px;
    padding-right: 14px;
  }

  .payroll-page .table {
    min-width: 980px;
  }

  #payrollModal .payroll-modal-summary {
    grid-template-columns: 1fr;
    gap: 12px;
  }

  #payrollModal .summary-source {
    text-align: left;
  }
}

:root[data-theme='dark'] .payroll-page .table thead th {
  background: rgba(15, 23, 42, .72);
}

:root[data-theme='dark'] .payroll-page .month-nav {
  background: rgba(15, 23, 42, .72);
  border-color: rgba(255, 255, 255, .08);
  box-shadow: none;
}

:root[data-theme='dark'] .payroll-page .table tbody tr:hover td {
  background: rgba(16, 185, 129, .08);
}

:root[data-theme='dark'] #payrollModal .payroll-modal-summary {
  background: linear-gradient(135deg, rgba(16, 185, 129, .12), rgba(59, 130, 246, .10));
  border-color: rgba(255, 255, 255, .08);
}
</style>
CSS;

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="payroll-page">
  <?php
  $f = getFlash();
  if ($f): ?>
    <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : $f['type'] ?> alert-dismissible mb-20">
      <i class="fas fa-<?= $f['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
      <?= htmlspecialchars($f['message']) ?>
      <button class="alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">Payroll</h1>
      <p class="page-sub">Manage employee salaries for <?= htmlspecialchars($monthLabel) ?></p>
    </div>
    <div class="month-nav">
      <a href="?month=<?= $prevMonth ?>" class="btn btn-outline btn-sm"><i class="fas fa-chevron-left"></i></a>
      <span class="month-label"><?= htmlspecialchars($monthLabel) ?></span>
      <?php if ($nextMonth <= date('Y-m')): ?>
        <a href="?month=<?= $nextMonth ?>" class="btn btn-outline btn-sm"><i class="fas fa-chevron-right"></i></a>
      <?php else: ?>
        <button class="btn btn-outline btn-sm" disabled><i class="fas fa-chevron-right"></i></button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="stats-grid mb-24">
    <div class="stat-card">
      <div class="stat-icon" style="background:#EEF2FF"><i class="fas fa-users" style="color:#5B6EF5"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= count($employees) ?></div>
        <div class="stat-label">Active Employees</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#ECFDF5"><i class="fas fa-check-circle" style="color:#10B981"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $paidCount ?></div>
        <div class="stat-label">Payroll Generated</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#FFF7ED"><i class="fas fa-clock" style="color:#F59E0B"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $unpaidCount ?></div>
        <div class="stat-label">Pending</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#F0FDF4"><i class="fas fa-wallet" style="color:#22C55E"></i></div>
      <div class="stat-body">
        <div class="stat-value">₹<?= number_format($totalPayroll, 0) ?></div>
        <div class="stat-label">Total Payout</div>
      </div>
    </div>
  </div>

  <!-- Payroll table -->
  <div class="card">
    <div class="card-header">
      <h2><i class="fas fa-table" style="margin-right:8px;color:var(--primary)"></i>Employee Payroll — <?= htmlspecialchars($monthLabel) ?></h2>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th style="text-align:right">Hours worked</th>
              <th style="text-align:right">Days</th>
              <th style="text-align:right">Basic salary</th>
              <th style="text-align:right">Net salary</th>
              <th style="text-align:center">Status</th>
              <th style="text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($employees as $emp): ?>
              <?php
              $hoursWorked  = round(($emp['worked_minutes_this_month'] ?? 0) / 60, 1);
              $daysWorked   = (int)($emp['days_worked_this_month'] ?? 0);
              $payableDays  = (float)($emp['payable_days_this_month'] ?? 0);
              $displayBasic = $emp['payroll_id'] ? (float)$emp['basic_salary'] : (float)($emp['recommended_basic_salary'] ?? 0);
              $isPaid       = !empty($emp['payroll_id']);
              ?>
              <tr>
                <td>
                  <div style="font-weight:600"><?= htmlspecialchars($emp['name']) ?></div>
                  <div class="emp-meta"><?= htmlspecialchars($emp['emp_code']) ?> · <?= htmlspecialchars($emp['designation'] ?? '—') ?></div>
                </td>
                <td><?= htmlspecialchars($emp['department_name'] ?? '—') ?></td>
                <td class="hours-cell">
                  <div class="hours-primary"><?= $hoursWorked ?>h</div>
                  <div class="hours-meta"><?= ($emp['worked_minutes_this_month'] ?? 0) ?> min</div>
                </td>
                <td class="days-cell">
                  <?= rtrim(rtrim(number_format($payableDays, 1, '.', ''), '0'), '.') ?>
                  <div class="hours-meta"><?= $daysWorked ?> present day<?= $daysWorked === 1 ? '' : 's' ?></div>
                </td>
                <td class="money-cell">₹<?= number_format($displayBasic, 0) ?></td>
                <td class="money-cell net">
                  <?php if ($isPaid): ?>
                    ₹<?= number_format((float)$emp['net_salary'], 0) ?>
                  <?php else: ?>
                    <span style="color:var(--text-light)">—</span>
                  <?php endif; ?>
                </td>
                <td class="status-cell">
                  <?php if ($isPaid): ?>
                    <span class="badge badge-success">Generated</span>
                  <?php else: ?>
                    <span class="badge badge-warning">Pending</span>
                  <?php endif; ?>
                </td>
                <td class="actions-cell">
                  <div class="actions-row">
                    <button class="btn btn-primary btn-sm"
                      onclick="openPayrollModal(<?= htmlspecialchars(json_encode([
                                                  'id'          => (int)$emp['id'],
                                                  'name'        => $emp['name'],
                                                  'emp_code'    => $emp['emp_code'],
                                                  'salary'      => (float)$emp['salary'],
                                                  'hourly_rate' => (float)($emp['hourly_rate'] ?? 0),
                                                  'hours'       => $hoursWorked,
                                                  'days'        => $daysWorked,
                                                  'payable_days'=> $payableDays,
                                                  'daily_salary'=> (float)($emp['daily_salary_this_month'] ?? 0),
                                                  'paid_leave_days' => (float)($emp['paid_leave_units_this_month'] ?? 0),
                                                  'payroll_id'  => (int)($emp['payroll_id'] ?? 0),
                                                  'basic_salary' => (float)($emp['basic_salary'] ?? $emp['recommended_basic_salary']),
                                                  'bonus'       => (float)($emp['bonus'] ?? 0),
                                                  'deductions'  => (float)($emp['deductions'] ?? 0),
                                                  'notes'       => $emp['notes'] ?? '',
                                                  'payment_date' => $emp['payment_date'] ?? date('Y-m-d'),
                                                ]), ENT_QUOTES) ?>)">
                      <i class="fas fa-<?= $isPaid ? 'edit' : 'plus' ?>"></i>
                      <?= $isPaid ? 'Edit' : 'Generate' ?>
                    </button>
                    <?php if ($isPaid): ?>
                      <a href="<?= APP_URL ?>/modules/payroll/payslip.php?id=<?= (int)$emp['payroll_id'] ?>"
                        target="_blank" class="btn btn-outline btn-sm" title="View payslip">
                        <i class="fas fa-file-invoice"></i>
                      </a>
                      <?php if (hasRole('admin')): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this payroll entry? This cannot be undone.')">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="payroll_id" value="<?= (int)$emp['payroll_id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($employees)): ?>
              <tr>
                <td colspan="8" style="text-align:center;padding:40px;color:var(--text-light)">No active employees found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Generate / Edit Payroll Modal ═══ -->
<div id="payrollModal" class="modal-overlay">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div>
        <h3 class="modal-title" id="modalEmpName">Generate Payroll</h3>
        <div class="modal-subtitle" id="modalEmpCode"></div>
      </div>
      <button type="button" class="modal-close-btn" data-close-modal><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">

      <div class="payroll-modal-summary">
        <div>
          <div class="summary-label">Hours worked</div>
          <div class="summary-value" id="modalHours">—</div>
          <div class="summary-sub" id="modalMinutes"></div>
        </div>
        <div>
          <div class="summary-label">Days present</div>
          <div class="summary-value" id="modalDays">—</div>
          <div class="summary-sub" id="modalPayableDays">Attendance summary for this month</div>
        </div>
        <div class="summary-source">
          <div class="summary-label">Source</div>
          <div class="summary-sub">Session-accurate<br>worked_minutes</div>
        </div>
      </div>

      <form method="POST" id="payrollForm">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="employee_id" id="fEmpId">
        <input type="hidden" name="month_year" value="<?= htmlspecialchars($month) ?>">

        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label>Payment date *</label>
            <input type="date" name="payment_date" id="fPayDate" class="form-control"
              value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Basic salary (₹) *</label>
            <input type="number" name="basic_salary" id="fBasic" class="form-control"
              step="0.01" min="0" required oninput="recalcNet()">
          </div>
          <div class="form-group">
            <label>Bonus / allowance (₹)</label>
            <input type="number" name="bonus" id="fBonus" class="form-control"
              step="0.01" min="0" value="0" oninput="recalcNet()">
          </div>
          <div class="form-group">
            <label>Deductions (₹)</label>
            <input type="number" name="deductions" id="fDeductions" class="form-control"
              step="0.01" min="0" value="0" oninput="recalcNet()">
          </div>
        </div>

        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" id="fNotes" class="form-control" rows="2"
            placeholder="Optional remarks…"></textarea>
        </div>

        <div class="payroll-net-preview">
          <span style="font-weight:600">Net Salary</span>
          <strong id="netPreview">₹0</strong>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn btn-outline" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> <span id="submitLabel">Generate Payroll</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
  function openPayrollModal(emp) {
    document.getElementById('modalEmpName').textContent = emp.name;
    document.getElementById('modalEmpCode').textContent = emp.emp_code;
    document.getElementById('modalHours').textContent = emp.hours + 'h';
    document.getElementById('modalMinutes').textContent = Math.round(emp.hours * 60) + ' min recorded';
    document.getElementById('modalDays').textContent = emp.days;
    document.getElementById('modalPayableDays').textContent =
      'Payable days: ' + (Number(emp.payable_days || 0).toFixed(1).replace(/\.0$/, '')) +
      ' · Paid/Sick leave: ' + (Number(emp.paid_leave_days || 0).toFixed(1).replace(/\.0$/, ''));
    document.getElementById('fEmpId').value = emp.id;
    document.getElementById('fBasic').value = emp.basic_salary || emp.salary || 0;
    document.getElementById('fBonus').value = emp.bonus || 0;
    document.getElementById('fDeductions').value = emp.deductions || 0;
    document.getElementById('fNotes').value = emp.notes || '';
    document.getElementById('fPayDate').value = emp.payment_date || '<?= date('Y-m-d') ?>';
    document.getElementById('submitLabel').textContent = emp.payroll_id ? 'Update Payroll' : 'Generate Payroll';
    recalcNet();
    openModal('payrollModal');
  }

  function closePayrollModal() {
    closeModal('payrollModal');
  }

  function recalcNet() {
    const basic = parseFloat(document.getElementById('fBasic').value) || 0;
    const bonus = parseFloat(document.getElementById('fBonus').value) || 0;
    const deductions = parseFloat(document.getElementById('fDeductions').value) || 0;
    const net = Math.max(0, basic + bonus - deductions);
    document.getElementById('netPreview').textContent = '₹' + net.toLocaleString('en-IN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }
</script>
