<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle   = 'Attendance Calendar';
$currentPage = 'attendance_calendar';
$pdo         = getDB();
$role        = $_SESSION['role'] ?? 'employee';
$me          = getCurrentEmployee();

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

function getPublicHolidays(int $year): array {
    $holidays = [
        sprintf('%d-01-01', $year) => "New Year's Day",
        sprintf('%d-01-26', $year) => 'Republic Day',
        sprintf('%d-05-01', $year) => 'Labour Day',
        sprintf('%d-08-15', $year) => 'Independence Day',
        sprintf('%d-10-02', $year) => 'Gandhi Jayanti',
        sprintf('%d-12-25', $year) => 'Christmas Day',
    ];

    if (function_exists('easter_date')) {
        $easterTs = easter_date($year);
        $goodFridayTs = strtotime('-2 days', $easterTs);
        $holidays[date('Y-m-d', $goodFridayTs)] = 'Good Friday';
    }

    ksort($holidays);
    return $holidays;
}

$allEmployees = [];
$selectedEmpId = 0;

if ($role === 'employee') {
    $selectedEmpId = (int)($me['id'] ?? 0);
} else {
    $allEmployees = $pdo->query('SELECT id,name,employee_id FROM employees ORDER BY name')->fetchAll();
    $selectedEmpId = (int)($_GET['emp'] ?? 0);
    if ($selectedEmpId <= 0 && !empty($allEmployees)) {
        $selectedEmpId = (int)$allEmployees[0]['id'];
    }
}

if ($selectedEmpId <= 0) {
    setFlash('danger', 'No employee profile found for calendar view.');
    redirect(APP_URL . '/modules/attendance/index.php');
}

$empStmt = $pdo->prepare('SELECT id,name,employee_id FROM employees WHERE id=? LIMIT 1');
$empStmt->execute([$selectedEmpId]);
$selectedEmp = $empStmt->fetch();

if (!$selectedEmp) {
    setFlash('danger', 'Selected employee not found.');
    redirect(APP_URL . '/modules/attendance/index.php');
}

$attStmt = $pdo->prepare('SELECT date, status, check_in, check_out FROM attendance WHERE employee_id=? AND YEAR(date)=?');
$attStmt->execute([$selectedEmpId, $year]);
$attendanceRows = $attStmt->fetchAll();

$attendanceData = [];
foreach ($attendanceRows as $row) {
    $attendanceData[$row['date']] = [
        'status'    => $row['status'],
        'check_in'  => $row['check_in'],
        'check_out' => $row['check_out']
    ];
}

$today = date('Y-m-d');

$publicHolidays = getPublicHolidays($year);
$weekdays = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Attendance Calendar</h1>
    <p><?= htmlspecialchars($selectedEmp['name']) ?> (<?= htmlspecialchars($selectedEmp['employee_id']) ?>) · Year <?= $year ?></p>
  </div>
  <div class="actions">
    <a href="<?= APP_URL ?>/modules/attendance/index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Records</a>
  </div>
</div>

<div class="card" style="margin-bottom:20px">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input type="number" name="year" min="2000" max="2100" value="<?= $year ?>" class="form-control" style="max-width:130px">
      <?php if ($role !== 'employee'): ?>
      <select name="emp" class="form-control" style="max-width:320px">
        <?php foreach ($allEmployees as $emp): ?>
        <option value="<?= (int)$emp['id'] ?>" <?= (int)$emp['id'] === (int)$selectedEmpId ? 'selected' : '' ?>>
          <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['employee_id']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Load Calendar</button>
    </form>
  </div>
</div>

<div class="calendar-legend">
  <span class="legend-item"><span class="legend-dot present"></span> Present</span>
  <span class="legend-item"><span class="legend-dot absent"></span> Absent / Not Marked</span>
  <span class="legend-item"><span class="legend-dot half_day"></span> Half Day</span>
  <span class="legend-item"><span class="legend-dot weekend"></span> Weekend (Sat/Sun)</span>
  <span class="legend-item"><span class="legend-dot public"></span> Public Holiday</span>
</div>

<div class="year-calendar-grid">
  <?php foreach ($months as $monthNo => $monthName): ?>
  <?php
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $monthNo, $year);
    $firstDayDow = (int)date('w', strtotime(sprintf('%04d-%02d-01', $year, $monthNo)));
  ?>
  <div class="card month-card">
    <div class="card-header"><h2><?= $monthName ?></h2></div>
    <div class="card-body">
      <div class="month-weekdays">
        <?php foreach ($weekdays as $w): ?><span><?= $w ?></span><?php endforeach; ?>
      </div>
      <div class="month-days-grid">
        <?php for ($i = 0; $i < $firstDayDow; $i++): ?>
        <div class="day-cell empty"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
        <?php
          $date = sprintf('%04d-%02d-%02d', $year, $monthNo, $day);
          $dow = (int)date('w', strtotime($date));
          $checkIn = $checkOut = '';
          $isToday = ($date === $today);
          $statusClass = 'absent';
          $statusText = 'Absent';

          if (isset($attendanceData[$date])) {
              $row = $attendanceData[$date];
              $rawStatus = strtolower((string)$row['status']);
              $checkIn  = !empty($row['check_in']) ? date('H:i', strtotime($row['check_in'])) : '';
              $checkOut = !empty($row['check_out']) ? date('H:i', strtotime($row['check_out'])) : '';

              if ($rawStatus === 'present') {
                  $statusClass = 'present';
                  $statusText = 'Present';
              } elseif ($rawStatus === 'half_day') {
                  $statusClass = 'half_day';
                  $statusText = 'Half Day';
              } else {
                  $statusClass = 'absent';
                  $statusText = 'Absent';
              }
          } elseif (isset($publicHolidays[$date])) {
              $statusClass = 'public';
              $statusText = $publicHolidays[$date];
          } elseif ($dow === 0 || $dow === 6) {
              $statusClass = 'weekend';
              $statusText = 'Weekend Holiday';
          }
        ?>
        <div class="day-cell <?= $statusClass ?><?= $isToday ? ' today' : '' ?>" title="<?= htmlspecialchars($date . ' • ' . $statusText) ?>">
          <span class="day-num"><?= $day ?></span>
          <?php if ($checkIn || $checkOut): ?>
          <div class="day-times">
            <?php if ($checkIn): ?><span><?= $checkIn ?></span><?php endif; ?>
            <?php if ($checkOut): ?><span><?= $checkOut ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
