<?php
// modules/reports/index.php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'hr');

$pageTitle   = 'Reports';
$currentPage = 'reports';
$pdo         = getDB();

// ── Export handlers (run before any HTML output) ─────────────
$export = $_GET['export'] ?? '';

if ($export === 'attendance_csv') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $rows = $pdo->prepare("
        SELECT e.employee_id, e.name, d.department_name,
               a.date, a.check_in, a.check_out, a.status
        FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE a.date BETWEEN ? AND ?
        ORDER BY a.date, e.name
    ");
    $rows->execute([$from, $to]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_'.$from.'_to_'.$to.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee ID','Name','Department','Date','Check In','Check Out','Status']);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [
            $r['employee_id'], $r['name'], $r['department_name'] ?? '',
            $r['date'],
            $r['check_in']  ? date('h:i A', strtotime($r['check_in']))  : '',
            $r['check_out'] ? date('h:i A', strtotime($r['check_out'])) : '',
            $r['status'],
        ]);
    }
    fclose($out); exit;
}

if ($export === 'payroll_csv') {
    $month = $_GET['month'] ?? date('Y-m');
    $rows  = $pdo->prepare("
        SELECT e.employee_id, e.name, d.department_name,
               p.basic_salary, p.bonus, p.deductions, p.net_salary, p.payment_date, p.month_year
        FROM payroll p
        JOIN employees e ON e.id = p.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE p.month_year = ?
        ORDER BY e.name
    ");
    $rows->execute([$month]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payroll_report_'.$month.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee ID','Name','Department','Basic','Bonus','Deductions','Net Salary','Payment Date','Month']);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['employee_id'],$r['name'],$r['department_name']??'',
            $r['basic_salary'],$r['bonus'],$r['deductions'],$r['net_salary'],
            $r['payment_date'],$r['month_year']]);
    }
    fclose($out); exit;
}

if ($export === 'leave_csv') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $rows = $pdo->prepare("
        SELECT e.employee_id, e.name, d.department_name,
               lr.leave_type, lr.start_date, lr.end_date,
               DATEDIFF(lr.end_date, lr.start_date)+1 AS days,
               lr.status, lr.reason
        FROM leave_requests lr
        JOIN employees e ON e.id = lr.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE YEAR(lr.start_date) = ?
        ORDER BY lr.start_date
    ");
    $rows->execute([$year]);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="leave_report_'.$year.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee ID','Name','Department','Type','From','To','Days','Status','Reason']);
    foreach ($rows->fetchAll() as $r) {
        fputcsv($out, [$r['employee_id'],$r['name'],$r['department_name']??'',
            $r['leave_type'],$r['start_date'],$r['end_date'],$r['days'],
            $r['status'],$r['reason']]);
    }
    fclose($out); exit;
}

// ── Report Parameters ────────────────────────────────────────
$tab        = $_GET['tab']   ?? 'attendance';
$attFrom    = $_GET['from']  ?? date('Y-m-01');
$attTo      = $_GET['to']    ?? date('Y-m-d');
$payMonth   = $_GET['month'] ?? date('Y-m');
$leaveYear  = (int)($_GET['year'] ?? date('Y'));

// ── Attendance Report Data ───────────────────────────────────
$attSummary = $pdo->prepare("
    SELECT
        e.name, e.employee_id AS emp_id,
        d.department_name,
        COUNT(CASE WHEN a.status='present' THEN 1 END)  AS present_days,
        COUNT(CASE WHEN a.status='absent'  THEN 1 END)  AS absent_days,
        COUNT(CASE WHEN a.status='late'    THEN 1 END)  AS late_days,
        COUNT(CASE WHEN a.status='half_day'THEN 1 END)  AS half_days,
        COUNT(*) AS total_days,
        ROUND(AVG(CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL
                  THEN TIME_TO_SEC(TIMEDIFF(a.check_out, a.check_in))/3600 END), 1) AS avg_hours
    FROM employees e
    LEFT JOIN attendance a ON a.employee_id = e.id AND a.date BETWEEN ? AND ?
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.status = 'active'
    GROUP BY e.id ORDER BY e.name
");
$attSummary->execute([$attFrom, $attTo]);
$attRows = $attSummary->fetchAll();

// Daily attendance trend (for chart)
$attTrend = $pdo->prepare("
    SELECT a.date, COUNT(DISTINCT a.employee_id) AS cnt
    FROM attendance a
    WHERE a.date BETWEEN ? AND ? AND a.status = 'present'
    GROUP BY a.date ORDER BY a.date
    LIMIT 31
");
$attTrend->execute([$attFrom, $attTo]);
$attTrendData = $attTrend->fetchAll();

// Department attendance rate
$deptAtt = $pdo->prepare("
    SELECT d.department_name,
           COUNT(CASE WHEN a.status='present' THEN 1 END) AS present,
           COUNT(*) AS total
    FROM departments d
    LEFT JOIN employees e ON e.department_id = d.id
    LEFT JOIN attendance a ON a.employee_id = e.id AND a.date BETWEEN ? AND ?
    GROUP BY d.id HAVING total > 0 ORDER BY department_name
");
$deptAtt->execute([$attFrom, $attTo]);
$deptAttData = $deptAtt->fetchAll();

// ── Payroll Report Data ──────────────────────────────────────
$payRows = $pdo->prepare("
    SELECT e.employee_id AS emp_id, e.name, d.department_name,
           p.basic_salary, p.bonus, p.deductions, p.net_salary, p.payment_date
    FROM payroll p
    JOIN employees e ON e.id = p.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE p.month_year = ?
    ORDER BY p.net_salary DESC
");
$payRows->execute([$payMonth]);
$payRows = $payRows->fetchAll();

$payTotal = [
    'basic' => array_sum(array_column($payRows, 'basic_salary')),
    'bonus' => array_sum(array_column($payRows, 'bonus')),
    'deductions' => array_sum(array_column($payRows, 'deductions')),
    'net'   => array_sum(array_column($payRows, 'net_salary')),
];

// Monthly payroll trend (last 6 months)
$payTrend = [];
for ($i = 5; $i >= 0; $i--) {
    $m   = date('Y-m', strtotime("-$i months"));
    $s   = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM payroll WHERE month_year=?");
    $s->execute([$m]); $payTrend[] = ['month' => date('M Y', strtotime("-$i months")), 'total' => (float)$s->fetchColumn()];
}

// Dept payroll breakdown
$deptPay = $pdo->prepare("
    SELECT d.department_name, COALESCE(SUM(p.net_salary),0) AS total
    FROM departments d
    LEFT JOIN employees e ON e.department_id = d.id
    LEFT JOIN payroll p ON p.employee_id = e.id AND p.month_year = ?
    GROUP BY d.id HAVING total > 0 ORDER BY total DESC
");
$deptPay->execute([$payMonth]);
$deptPayData = $deptPay->fetchAll();

// ── Leave Report Data ────────────────────────────────────────
$leaveRows = $pdo->prepare("
    SELECT lr.leave_type, lr.status,
           DATEDIFF(lr.end_date, lr.start_date)+1 AS days,
           e.name, e.employee_id AS emp_id, d.department_name,
           lr.start_date, lr.end_date, lr.reason
    FROM leave_requests lr
    JOIN employees e ON e.id = lr.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE YEAR(lr.start_date) = ?
    ORDER BY lr.start_date DESC
");
$leaveRows->execute([$leaveYear]);
$leaveRows = $leaveRows->fetchAll();

// Leave by type
$leaveByType = ['sick'=>0,'casual'=>0,'paid'=>0];
$leaveByStatus = ['pending'=>0,'approved'=>0,'rejected'=>0];
foreach ($leaveRows as $r) {
    $leaveByType[$r['leave_type']] = ($leaveByType[$r['leave_type']] ?? 0) + $r['days'];
    $leaveByStatus[$r['status']]   = ($leaveByStatus[$r['status']] ?? 0) + 1;
}

// Leave by department
$deptLeave = $pdo->prepare("
    SELECT d.department_name,
           COUNT(lr.id) AS total_requests,
           SUM(DATEDIFF(lr.end_date,lr.start_date)+1) AS total_days
    FROM departments d
    LEFT JOIN employees e ON e.department_id = d.id
    LEFT JOIN leave_requests lr ON lr.employee_id = e.id AND YEAR(lr.start_date) = ?
        AND lr.status = 'approved'
    GROUP BY d.id ORDER BY total_days DESC
");
$deptLeave->execute([$leaveYear]);
$deptLeaveData = $deptLeave->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Reports</h1>
    <p>Analytics and downloadable reports</p>
  </div>
  <div class="actions">
    <button class="btn btn-secondary btn-sm" onclick="window.print()">
      <i class="fas fa-print"></i> Print
    </button>
  </div>
</div>

<!-- Tab Navigation -->
<div style="display:flex;gap:4px;margin-bottom:24px;background:#fff;padding:6px;border-radius:var(--radius);border:1px solid var(--border);width:fit-content;box-shadow:var(--shadow)">
  <?php foreach (['attendance'=>'<i class="fas fa-calendar-check"></i> Attendance','payroll'=>'<i class="fas fa-wallet"></i> Payroll','leave'=>'<i class="fas fa-calendar-minus"></i> Leave'] as $t => $label): ?>
  <a href="?tab=<?= $t ?>" class="btn btn-sm <?= $tab===$t ? 'btn-primary' : 'btn-secondary' ?>" style="border:none">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'attendance'): ?>
<!-- ════════════ ATTENDANCE REPORT ════════════ -->
<form method="GET" class="card" style="margin-bottom:20px">
  <input type="hidden" name="tab" value="attendance">
  <div class="card-body" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="flex:1;min-width:150px">
      <label>From Date</label>
      <input type="date" name="from" class="form-control" value="<?= $attFrom ?>">
    </div>
    <div class="form-group" style="flex:1;min-width:150px">
      <label>To Date</label>
      <input type="date" name="to" class="form-control" value="<?= $attTo ?>">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
      <a href="?tab=attendance&export=attendance_csv&from=<?= $attFrom ?>&to=<?= $attTo ?>" class="btn btn-secondary">
        <i class="fas fa-download"></i> CSV
      </a>
    </div>
  </div>
</form>

<!-- Charts Row -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px">
  <div class="card">
    <div class="card-header"><h2>Daily Attendance Trend</h2></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="attTrendChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h2>By Department</h2></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="deptAttChart"></canvas></div></div>
  </div>
</div>

<!-- Summary Stats -->
<?php
$totalPresent = array_sum(array_column($attRows,'present_days'));
$totalAbsent  = array_sum(array_column($attRows,'absent_days'));
$totalLate    = array_sum(array_column($attRows,'late_days'));
?>
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-check"></i></div><div class="stat-body"><div class="stat-value"><?= $totalPresent ?></div><div class="stat-label">Total Present Days</div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fas fa-times"></i></div><div class="stat-body"><div class="stat-value"><?= $totalAbsent ?></div><div class="stat-label">Total Absent Days</div></div></div>
  <div class="stat-card"><div class="stat-icon yellow"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?= $totalLate ?></div><div class="stat-label">Late Arrivals</div></div></div>
  <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?= count($attRows) ?></div><div class="stat-label">Employees Tracked</div></div></div>
</div>

<!-- Attendance Table -->
<div class="card">
  <div class="card-header"><h2>Employee Attendance Summary</h2></div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Employee</th><th>Department</th>
          <th>Present</th><th>Absent</th><th>Late</th><th>Half Day</th>
          <th>Avg Hours/Day</th><th>Attendance %</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attRows as $r):
          $pct = $r['total_days'] > 0 ? round($r['present_days']/$r['total_days']*100) : 0;
          $color = $pct >= 90 ? 'var(--success)' : ($pct >= 75 ? 'var(--warning)' : 'var(--danger)');
        ?>
        <tr>
          <td><div class="emp-cell"><div class="emp-avatar"><?= strtoupper(substr($r['name'],0,1)) ?></div><div><div class="emp-name"><?= htmlspecialchars($r['name']) ?></div><div class="emp-id"><?= $r['emp_id'] ?></div></div></div></td>
          <td><?= htmlspecialchars($r['department_name']??'—') ?></td>
          <td style="color:var(--success);font-weight:600"><?= $r['present_days'] ?></td>
          <td style="color:var(--danger)"><?= $r['absent_days'] ?></td>
          <td style="color:var(--warning)"><?= $r['late_days'] ?></td>
          <td><?= $r['half_days'] ?></td>
          <td><?= $r['avg_hours'] ?? '—' ?> hrs</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;background:#f3f4f6;border-radius:4px;height:6px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:4px"></div>
              </div>
              <span style="color:<?= $color ?>;font-weight:600;font-size:12px;min-width:36px"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; if (!$attRows): ?>
        <tr><td colspan="8" class="text-center text-muted" style="padding:36px">No records in date range</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'payroll'): ?>
<!-- ════════════ PAYROLL REPORT ════════════ -->
<form method="GET" class="card" style="margin-bottom:20px">
  <input type="hidden" name="tab" value="payroll">
  <div class="card-body" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="flex:1;min-width:180px">
      <label>Month</label>
      <input type="month" name="month" class="form-control" value="<?= $payMonth ?>">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
      <a href="?tab=payroll&export=payroll_csv&month=<?= $payMonth ?>" class="btn btn-secondary">
        <i class="fas fa-download"></i> CSV
      </a>
    </div>
  </div>
</form>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px">
  <div class="card">
    <div class="card-header"><h2>Monthly Payroll Trend (Last 6 Months)</h2></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="payTrendChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h2>By Department</h2></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="deptPayChart"></canvas></div></div>
  </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
  <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-money-bill-wave"></i></div><div class="stat-body"><div class="stat-value">₹<?= number_format($payTotal['basic']/1000,1) ?>K</div><div class="stat-label">Total Basic</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-plus-circle"></i></div><div class="stat-body"><div class="stat-value">₹<?= number_format($payTotal['bonus']/1000,1) ?>K</div><div class="stat-label">Total Bonus</div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fas fa-minus-circle"></i></div><div class="stat-body"><div class="stat-value">₹<?= number_format($payTotal['deductions']/1000,1) ?>K</div><div class="stat-label">Total Deductions</div></div></div>
  <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-wallet"></i></div><div class="stat-body"><div class="stat-value">₹<?= number_format($payTotal['net']/1000,1) ?>K</div><div class="stat-label">Net Payout</div></div></div>
</div>

<div class="card">
  <div class="card-header"><h2>Payroll Details — <?= date('F Y', strtotime($payMonth.'-01')) ?></h2></div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th>Employee</th><th>Department</th><th>Basic</th><th>Bonus</th><th>Deductions</th><th>Net Salary</th><th>Payment Date</th></tr></thead>
      <tbody>
        <?php foreach ($payRows as $r): ?>
        <tr>
          <td><div class="emp-cell"><div class="emp-avatar"><?= strtoupper(substr($r['name'],0,1)) ?></div><div><div class="emp-name"><?= htmlspecialchars($r['name']) ?></div><div class="emp-id"><?= $r['emp_id'] ?></div></div></div></td>
          <td><?= htmlspecialchars($r['department_name']??'—') ?></td>
          <td>₹<?= number_format($r['basic_salary'],0) ?></td>
          <td style="color:var(--success)">+₹<?= number_format($r['bonus'],0) ?></td>
          <td style="color:var(--danger)">-₹<?= number_format($r['deductions'],0) ?></td>
          <td><strong>₹<?= number_format($r['net_salary'],0) ?></strong></td>
          <td><?= date('M d, Y', strtotime($r['payment_date'])) ?></td>
        </tr>
        <?php endforeach; if (!$payRows): ?>
        <tr><td colspan="7" class="text-center text-muted" style="padding:36px">No payroll records for this month</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($payRows): ?>
      <tfoot>
        <tr style="background:var(--secondary);font-weight:700">
          <td colspan="2">Totals</td>
          <td>₹<?= number_format($payTotal['basic'],0) ?></td>
          <td style="color:var(--success)">+₹<?= number_format($payTotal['bonus'],0) ?></td>
          <td style="color:var(--danger)">-₹<?= number_format($payTotal['deductions'],0) ?></td>
          <td>₹<?= number_format($payTotal['net'],0) ?></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php else: ?>
<!-- ════════════ LEAVE REPORT ════════════ -->
<form method="GET" class="card" style="margin-bottom:20px">
  <input type="hidden" name="tab" value="leave">
  <div class="card-body" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="flex:1;min-width:150px">
      <label>Year</label>
      <select name="year" class="form-control" style="max-width:160px">
        <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
        <option value="<?= $y ?>" <?= $leaveYear==$y?'selected':'' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
      <a href="?tab=leave&export=leave_csv&year=<?= $leaveYear ?>" class="btn btn-secondary">
        <i class="fas fa-download"></i> CSV
      </a>
    </div>
  </div>
</form>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
  <div class="card">
    <div class="card-header"><h2>Leave Days by Type</h2></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="leaveTypeChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h2>Request Status</h2></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="leaveStatusChart"></canvas></div></div>
  </div>
</div>

<!-- Dept Leave Table -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><h2>Leave by Department</h2></div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th>Department</th><th>Total Requests</th><th>Total Days Approved</th></tr></thead>
      <tbody>
        <?php foreach ($deptLeaveData as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['department_name']) ?></td>
          <td><?= $r['total_requests'] ?></td>
          <td><?= $r['total_days'] ?? 0 ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- All Leave Records -->
<div class="card">
  <div class="card-header"><h2>All Leave Requests — <?= $leaveYear ?></h2></div>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead><tr><th>Employee</th><th>Dept</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($leaveRows as $r): ?>
        <tr>
          <td><div class="emp-cell"><div class="emp-avatar"><?= strtoupper(substr($r['name'],0,1)) ?></div><div><div class="emp-name"><?= htmlspecialchars($r['name']) ?></div><div class="emp-id"><?= $r['emp_id'] ?></div></div></div></td>
          <td><?= htmlspecialchars($r['department_name']??'—') ?></td>
          <td><span class="badge" style="background:<?= $r['leave_type']==='sick'?'#FEE2E2':($r['leave_type']==='paid'?'#D1FAE5':'#EEF2FF') ?>;color:<?= $r['leave_type']==='sick'?'#991B1B':($r['leave_type']==='paid'?'#065F46':'#3730A3') ?>"><?= ucfirst($r['leave_type']) ?></span></td>
          <td><?= date('M d, Y', strtotime($r['start_date'])) ?></td>
          <td><?= date('M d, Y', strtotime($r['end_date'])) ?></td>
          <td><?= $r['days'] ?></td>
          <td><span class="badge badge-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
        </tr>
        <?php endforeach; if (!$leaveRows): ?>
        <tr><td colspan="7" class="text-center text-muted" style="padding:36px">No leave records for <?= $leaveYear ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
// Build chart JSON
$attDates  = json_encode(array_column($attTrendData,'date'));
$attCounts = json_encode(array_column($attTrendData,'cnt'));
$deptAttLabels = json_encode(array_column($deptAttData,'department_name'));
$deptAttPcts   = json_encode(array_map(fn($r)=>$r['total']>0?round($r['present']/$r['total']*100):0, $deptAttData));
$payTrendLabels= json_encode(array_column($payTrend,'month'));
$payTrendVals  = json_encode(array_column($payTrend,'total'));
$deptPayLabels = json_encode(array_column($deptPayData,'department_name'));
$deptPayVals   = json_encode(array_column($deptPayData,'total'));
$leaveTypeVals  = json_encode(array_values($leaveByType));
$leaveStatusVals= json_encode(array_values($leaveByStatus));

$extraScripts = <<<JS
<script>
// ── Attendance Charts ──────────────────────────────────────
if (document.getElementById('attTrendChart')) {
  new Chart(document.getElementById('attTrendChart'), {
    type:'line',
    data:{ labels:$attDates,
           datasets:[{label:'Present',data:$attCounts,borderColor:'#5B6EF5',
             backgroundColor:'rgba(91,110,245,.1)',fill:true,tension:.4,pointRadius:4}]},
    options:{responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{y:{beginAtZero:true,grid:{color:'#f3f4f6'}},x:{grid:{display:false}}}}
  });
}
if (document.getElementById('deptAttChart')) {
  new Chart(document.getElementById('deptAttChart'), {
    type:'bar',
    data:{ labels:$deptAttLabels,
           datasets:[{label:'Attendance %',data:$deptAttPcts,
             backgroundColor:['#5B6EF5','#10B981','#F59E0B','#EF4444','#9333EA','#3B82F6'],
             borderRadius:6,borderSkipped:false}]},
    options:{responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{y:{beginAtZero:true,max:100,grid:{color:'#f3f4f6'},ticks:{callback:v=>v+'%'}},x:{grid:{display:false}}}}
  });
}

// ── Payroll Charts ─────────────────────────────────────────
if (document.getElementById('payTrendChart')) {
  new Chart(document.getElementById('payTrendChart'), {
    type:'bar',
    data:{ labels:$payTrendLabels,
           datasets:[{label:'Net Payroll ₹',data:$payTrendVals,
             backgroundColor:'rgba(91,110,245,.75)',borderRadius:6,borderSkipped:false}]},
    options:{responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{y:{beginAtZero:true,grid:{color:'#f3f4f6'}},x:{grid:{display:false}}}}
  });
}
if (document.getElementById('deptPayChart')) {
  new Chart(document.getElementById('deptPayChart'), {
    type:'doughnut',
    data:{ labels:$deptPayLabels,
           datasets:[{data:$deptPayVals,
             backgroundColor:['#5B6EF5','#10B981','#F59E0B','#EF4444','#9333EA'],
             borderWidth:2,borderColor:'#fff'}]},
    options:{responsive:true,maintainAspectRatio:false,
      plugins:{legend:{position:'right'}}}
  });
}

// ── Leave Charts ───────────────────────────────────────────
if (document.getElementById('leaveTypeChart')) {
  new Chart(document.getElementById('leaveTypeChart'), {
    type:'pie',
    data:{ labels:['Sick','Casual','Paid'],
           datasets:[{data:$leaveTypeVals,
             backgroundColor:['#EF4444','#5B6EF5','#10B981'],
             borderWidth:2,borderColor:'#fff'}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'}}}
  });
}
if (document.getElementById('leaveStatusChart')) {
  new Chart(document.getElementById('leaveStatusChart'), {
    type:'doughnut',
    data:{ labels:['Pending','Approved','Rejected'],
           datasets:[{data:$leaveStatusVals,
             backgroundColor:['#F59E0B','#10B981','#EF4444'],
             borderWidth:2,borderColor:'#fff'}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'}}}
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
