<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('
    SELECT p.*, e.name, e.employee_id AS emp_id, e.designation, e.phone, e.address,
           e.id AS employee_pk, d.department_name
    FROM payroll p
    JOIN employees e ON e.id = p.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE p.id = ?
');
$stmt->execute([$id]);
$pay = $stmt->fetch();
if (!$pay) die('Record not found');

// Employees can only view their own payslip
if (hasRole('employee')) {
  $myEmp = getCurrentEmployee();
  if (!$myEmp || (int)$myEmp['id'] !== (int)$pay['employee_pk']) {
    http_response_code(403);
    die('Access denied.');
  }
}

function payslipLeaveUnitsWithinRange(string $leaveStart, string $leaveEnd, string $rangeStart, string $rangeEnd, string $durationType = 'full_day'): float
{
  $start = max(strtotime($leaveStart), strtotime($rangeStart));
  $end   = min(strtotime($leaveEnd), strtotime($rangeEnd));
  if ($start === false || $end === false || $end < $start) return 0.0;
  if ($durationType === 'half_day') return 0.5;
  return (float)(((int)(($end - $start) / 86400)) + 1);
}

function payslipCountWeekendDays(string $monthStart, string $monthEnd): int
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

// Fetch worked hours for this payroll month (using correct worked_minutes)
$hoursStmt = $pdo->prepare("
    SELECT COALESCE(SUM(worked_minutes), 0) AS total_minutes,
           COUNT(CASE WHEN status IN ('present','half_day','late') THEN 1 END) AS days_worked,
           COALESCE(SUM(
             CASE
               WHEN status = 'half_day' THEN 0.5
               WHEN status IN ('present','late') THEN 1
               ELSE 0
             END
           ), 0) AS attendance_units
    FROM attendance
    WHERE employee_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
      AND status IN ('present','half_day','late')
");
$hoursStmt->execute([$pay['employee_pk'], $pay['month_year']]);
$attendance = $hoursStmt->fetch();
$hoursWorked      = round(($attendance['total_minutes'] ?? 0) / 60, 1);
$daysWorked       = (int)($attendance['days_worked'] ?? 0);
$attendanceUnits  = (float)($attendance['attendance_units'] ?? 0);
$monthStart       = $pay['month_year'] . '-01';
$monthEnd         = date('Y-m-t', strtotime($monthStart));
$daysInMonth      = (int)date('t', strtotime($monthStart));
$weekendDays      = payslipCountWeekendDays($monthStart, $monthEnd);
$leaveStmt = $pdo->prepare("
    SELECT start_date, end_date, duration_type
    FROM leave_requests
    WHERE employee_id = ?
      AND status = 'approved'
      AND leave_type IN ('paid','sick')
      AND start_date <= ?
      AND end_date >= ?
");
$leaveStmt->execute([$pay['employee_pk'], $monthEnd, $monthStart]);
$paidLeaveDays = 0.0;
foreach ($leaveStmt->fetchAll() as $row) {
  $paidLeaveDays += payslipLeaveUnitsWithinRange(
    (string)$row['start_date'],
    (string)$row['end_date'],
    $monthStart,
    $monthEnd,
    (string)($row['duration_type'] ?? 'full_day')
  );
}
$payableDays = $attendanceUnits > 0 ? min((float)$daysInMonth, $attendanceUnits + $weekendDays + $paidLeaveDays) : 0.0;
$dailySalary = $daysInMonth > 0 ? round(((float)$pay['basic_salary']) / max($payableDays, 1), 2) : 0.0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Payslip - <?= $pay['emp_id'] ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Poppins', Arial, sans-serif;
      background: #f4f6fc;
      padding: 32px 16px;
      color: #111827;
    }

    .payslip {
      max-width: 720px;
      margin: auto;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 32px rgba(0, 0, 0, .1);
      overflow: hidden;
    }

    .slip-header {
      background: linear-gradient(135deg, #5B6EF5, #764BA2);
      padding: 32px;
      color: #fff;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
    }

    .company-name {
      font-size: 22px;
      font-weight: 700;
    }

    .slip-title {
      font-size: 13px;
      opacity: .8;
      margin-top: 4px;
    }

    .month-badge {
      background: rgba(255, 255, 255, .2);
      padding: 8px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 15px;
    }

    .emp-section {
      padding: 24px 32px;
      border-bottom: 1px solid #E5E7EB;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .info-row {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .info-label {
      font-size: 11px;
      color: #9CA3AF;
      font-weight: 600;
      letter-spacing: .5px;
      text-transform: uppercase;
    }

    .info-val {
      font-size: 14px;
      font-weight: 500;
    }

    .salary-section {
      padding: 24px 32px;
    }

    .salary-section h3 {
      font-size: 15px;
      font-weight: 600;
      margin-bottom: 14px;
    }

    .sal-row {
      display: flex;
      justify-content: space-between;
      padding: 8px 0;
      border-bottom: 1px solid #F3F4F6;
      font-size: 13.5px;
    }

    .sal-row:last-child {
      border: none;
    }

    .green {
      color: #10B981;
    }

    .red {
      color: #EF4444;
    }

    .net-row {
      background: #F4F6FC;
      border-radius: 8px;
      padding: 14px 16px;
      display: flex;
      justify-content: space-between;
      margin-top: 14px;
      font-weight: 700;
      font-size: 16px;
    }

    .net-row span:last-child {
      color: #5B6EF5;
    }

    .slip-footer {
      padding: 20px 32px;
      background: #F9FAFB;
      border-top: 1px solid #E5E7EB;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .print-btn {
      background: #5B6EF5;
      color: #fff;
      border: none;
      padding: 10px 24px;
      border-radius: 8px;
      cursor: pointer;
      font-family: inherit;
      font-size: 14px;
      font-weight: 500;
    }

    @media print {
      .print-btn {
        display: none;
      }

      body {
        background: white;
        padding: 0;
      }
    }
  </style>
</head>

<body>
  <div class="payslip">
    <div class="slip-header">
      <div>
        <div class="company-name">EmpAxis Corporation</div>
        <div class="slip-title">Salary Slip / Payslip</div>
      </div>
      <div class="month-badge"><?= date('F Y', strtotime($pay['payment_date'])) ?></div>
    </div>

    <div class="emp-section">
      <div class="info-row"><span class="info-label">Employee Name</span><span class="info-val"><?= htmlspecialchars($pay['name']) ?></span></div>
      <div class="info-row"><span class="info-label">Employee ID</span><span class="info-val"><?= $pay['emp_id'] ?></span></div>
      <div class="info-row"><span class="info-label">Department</span><span class="info-val"><?= htmlspecialchars($pay['department_name'] ?? '—') ?></span></div>
      <div class="info-row"><span class="info-label">Designation</span><span class="info-val"><?= htmlspecialchars($pay['designation'] ?? '—') ?></span></div>
      <div class="info-row"><span class="info-label">Payment Date</span><span class="info-val"><?= date('F d, Y', strtotime($pay['payment_date'])) ?></span></div>
      <div class="info-row"><span class="info-label">Month / Year</span><span class="info-val"><?= $pay['month_year'] ?></span></div>
    </div>

    <div class="salary-section">
      <h3>Earnings & Deductions</h3>
      <div class="sal-row"><span>Basic Salary</span><span class="green">₹<?= number_format($pay['basic_salary'], 2) ?></span></div>
      <div class="sal-row"><span>Bonus / Allowance</span><span class="green">₹<?= number_format($pay['bonus'], 2) ?></span></div>
      <div class="sal-row"><span>Deductions</span><span class="red">- ₹<?= number_format($pay['deductions'], 2) ?></span></div>
      <div class="sal-row" style="color:#6B7280;font-size:13px">
        <span>Attendance</span>
        <span><?= $daysWorked ?> days &nbsp;·&nbsp; <?= $hoursWorked ?>h worked</span>
      </div>
      <div class="sal-row" style="color:#6B7280;font-size:13px">
        <span>Payable Days</span>
        <span><?= rtrim(rtrim(number_format($payableDays, 1, '.', ''), '0'), '.') ?> days &nbsp;·&nbsp; ₹<?= number_format($dailySalary, 2) ?>/day</span>
      </div>
      <?php if ($pay['notes']): ?>
        <div class="sal-row"><span>Notes</span><span style="color:#6B7280;font-size:12px"><?= htmlspecialchars($pay['notes']) ?></span></div>
      <?php endif; ?>
      <div class="net-row">
        <span>Net Salary</span>
        <span>₹<?= number_format($pay['net_salary'], 2) ?></span>
      </div>
    </div>

    <div class="slip-footer">
      <span style="font-size:12px;color:#9CA3AF">Generated by EmpAxis HR System</span>
      <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    </div>
  </div>
</body>

</html>
