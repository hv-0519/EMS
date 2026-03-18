<?php
// dashboard/dashboard.php  –  Role-aware dashboard (FULLY FIXED)
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';
$pdo         = getDB();
$role        = $_SESSION['role'];
$me          = getCurrentEmployee();
$today       = date('Y-m-d');
$month       = date('Y-m');
$year        = (int)date('Y');

// Must run BEFORE any query that touches attendance_sessions or worked_minutes
ensureWorkTrackingSchema();

// ══════════════════════════════════════════════
// SHARED STATS (all roles)
// ══════════════════════════════════════════════
$totalEmp  = (int)$pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn();
$activeEmp = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE date=? AND check_in IS NOT NULL');
$stmt->execute([$today]);
$presentToday = (int)$stmt->fetchColumn();

$pendingLeaves = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
$deptCount     = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM payroll WHERE month_year=?");
$stmt->execute([$month]);
$monthlyPayroll = (float)$stmt->fetchColumn();

// ══════════════════════════════════════════════
// EMPLOYEE-SPECIFIC DATA
// ══════════════════════════════════════════════
$myAttMonth               = 0;
$myBalance                = [];
$myLatestPay              = null;
$myTodayRec               = null;
$myRecentAtt              = [];
$myRecentLeaves           = [];
$todayClosedWorkedSeconds = 0;   // seconds from fully closed sessions
$activeSessionStartUnix   = 0;   // unix ts of the currently OPEN session's check_in (0 = none)
$todaySessionCount        = 0;
$weekWorkedMinutes        = 0;
$targetWorkMinutes        = 8 * 60;   // 480

if ($me) {
  $empId = (int)$me['id'];

  // Days present this month
  $s = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND DATE_FORMAT(date,'%Y-%m')=? AND status='present'");
  $s->execute([$empId, $month]);
  $myAttMonth = (int)$s->fetchColumn();

  // Leave balance
  ensureLeaveBalance($empId, $year);
  $myBalance = getLeaveBalance($empId, $year);

  // Latest payroll
  $s = $pdo->prepare('SELECT * FROM payroll WHERE employee_id=? ORDER BY payment_date DESC LIMIT 1');
  $s->execute([$empId]);
  $myLatestPay = $s->fetch(PDO::FETCH_ASSOC) ?: null;

  // Today's main attendance row
  $s = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND date=? LIMIT 1');
  $s->execute([$empId, $today]);
  $myTodayRec = $s->fetch(PDO::FETCH_ASSOC) ?: null;

  // Recent attendance (last 5)
  $s = $pdo->prepare('SELECT date,check_in,check_out,status,worked_minutes FROM attendance WHERE employee_id=? ORDER BY date DESC LIMIT 5');
  $s->execute([$empId]);
  $myRecentAtt = $s->fetchAll(PDO::FETCH_ASSOC);

  // Recent leave requests
  $s = $pdo->prepare('SELECT leave_type,start_date,end_date,status FROM leave_requests WHERE employee_id=? ORDER BY created_at DESC LIMIT 4');
  $s->execute([$empId]);
  $myRecentLeaves = $s->fetchAll(PDO::FETCH_ASSOC);

  // ── Build timer data from attendance_sessions ─────────────────────────────
  // closed sessions  → accumulate into todayClosedWorkedSeconds
  // open session     → store its check_in unix ts in activeSessionStartUnix
  //                    JS computes elapsed = Date.now() - activeSessionStartMs  each tick
  $s = $pdo->prepare('
        SELECT check_in, check_out, duration_minutes
        FROM attendance_sessions
        WHERE employee_id = ? AND session_date = ?
        ORDER BY check_in ASC
    ');
  $s->execute([$empId, $today]);

  foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $sess) {
    $todaySessionCount++;
    $isClosed = !empty($sess['check_out']) && $sess['check_out'] !== '0000-00-00 00:00:00';

    if ($isClosed) {
      // Use the stored duration_minutes (now correctly written by fixed endWorkSession)
      $todayClosedWorkedSeconds += (int)$sess['duration_minutes'] * 60;
    } else {
      // Open session — anchor JS timer to this check_in
      if (!empty($sess['check_in']) && $sess['check_in'] !== '0000-00-00 00:00:00') {
        $ts = storedUtcToUnix((string)$sess['check_in']);
        if ($ts > 0) $activeSessionStartUnix = $ts;
      }
    }
  }

  // Fallback: attendance_sessions empty but attendance row has unclosed check_in
  if ($activeSessionStartUnix === 0 && $myTodayRec) {
    $ci = $myTodayRec['check_in']  ?? '';
    $co = $myTodayRec['check_out'] ?? '';
    $hasCI = !empty($ci) && $ci !== '0000-00-00 00:00:00';
    $hasCO = !empty($co) && $co !== '0000-00-00 00:00:00';
    if ($hasCI && !$hasCO) {
      $ts = storedUtcToUnix((string)$ci);
      if ($ts > 0) {
        $activeSessionStartUnix = $ts;
        $todaySessionCount = max(1, $todaySessionCount);
      }
    }
    if ($hasCI && $hasCO && $todayClosedWorkedSeconds === 0) {
      $wm = (int)($myTodayRec['worked_minutes'] ?? 0);
      if ($wm > 0) $todayClosedWorkedSeconds = $wm * 60;
    }
  }

  // Week worked
  $weekStart = date('Y-m-d', strtotime('monday this week'));
  $s = $pdo->prepare('SELECT COALESCE(SUM(worked_minutes),0) FROM attendance WHERE employee_id=? AND date BETWEEN ? AND ?');
  $s->execute([$empId, $weekStart, $today]);
  $weekWorkedMinutes = (int)$s->fetchColumn();
}

// ── Derived display values (server-side initial render) ───────────────────────
$nowUnix              = time();
$activeElapsedSec     = ($activeSessionStartUnix > 0) ? max(0, $nowUnix - $activeSessionStartUnix) : 0;
$todayTotalSeconds    = $todayClosedWorkedSeconds + $activeElapsedSec;
$todayWorkedPct       = $targetWorkMinutes > 0
  ? min(100, (int)round($todayTotalSeconds / ($targetWorkMinutes * 60) * 100)) : 0;

$todayFirstIn = ($myTodayRec && !empty($myTodayRec['check_in']) && $myTodayRec['check_in'] !== '0000-00-00 00:00:00')
  ? formatStoredUtcToApp((string)$myTodayRec['check_in']) : '—';
$todayLastOut = ($myTodayRec && !empty($myTodayRec['check_out']) && $myTodayRec['check_out'] !== '0000-00-00 00:00:00')
  ? formatStoredUtcToApp((string)$myTodayRec['check_out']) : '—';

if (!$myTodayRec) {
  $todayStatusText = 'Not marked yet';
} elseif ($activeSessionStartUnix > 0) {
  $todayStatusText = 'Checked in';
} else {
  $todayStatusText = 'Session ended';   // NOT "Completed" — multi-session allowed
}

$weekWorkedHours = round($weekWorkedMinutes / 60, 1);

// ══════════════════════════════════════════════
// CHARTS DATA
// ══════════════════════════════════════════════
$growthData = [];
for ($i = 5; $i >= 0; $i--) {
  $m   = date('Y-m', strtotime("-$i months"));
  $cnt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE DATE_FORMAT(created_at,'%Y-%m') <= ?");
  $cnt->execute([$m]);
  $growthData[] = ['month' => date('M Y', strtotime("-$i months")), 'count' => (int)$cnt->fetchColumn()];
}

$attData = [];
for ($i = 6; $i >= 0; $i--) {
  $d   = date('Y-m-d', strtotime("-$i days"));
  $cnt = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE date=? AND check_in IS NOT NULL");
  $cnt->execute([$d]);
  $attData[] = ['date' => date('D', strtotime("-$i days")), 'count' => (int)$cnt->fetchColumn()];
}

$deptCounts = $pdo->query("
    SELECT d.department_name, COUNT(e.id) AS cnt
    FROM departments d LEFT JOIN employees e ON e.department_id=d.id AND e.status='active'
    GROUP BY d.id ORDER BY cnt DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$recentActivity = [];
if ($role === 'admin') {
  $recentActivity = $pdo->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
}

$recentEmps = [];
if ($role !== 'employee') {
  $recentEmps = $pdo->query("
        SELECT e.*, d.department_name FROM employees e
        LEFT JOIN departments d ON d.id=e.department_id
        ORDER BY e.created_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ══════════════════════════════════════════════
// STYLES
// ══════════════════════════════════════════════
$extraStyles = <<<CSS
<style>
.employee-dashboard-view { display:grid; gap:18px; }
.employee-dashboard-view .emp-hero {
  position:relative; overflow:hidden;
  background:
    repeating-linear-gradient(0deg, rgba(255,255,255,.07) 0 1px, transparent 1px 24px),
    repeating-linear-gradient(90deg, rgba(255,255,255,.07) 0 1px, transparent 1px 24px),
    radial-gradient(circle at 12% 12%, rgba(255,255,255,.25), transparent 36%),
    radial-gradient(circle at 84% 82%, rgba(255,255,255,.16), transparent 40%),
    linear-gradient(120deg, #0ea5a3 0%, #2563eb 55%, #6d28d9 100%);
  border-radius:16px; padding:20px 22px; color:#fff;
  border:1px solid rgba(255,255,255,.22); box-shadow:0 14px 28px rgba(2,6,23,.16);
}
.employee-dashboard-view .emp-hero::after {
  content:""; position:absolute; inset:0; pointer-events:none;
  background:linear-gradient(115deg, rgba(255,255,255,.09), transparent 62%);
}
.employee-dashboard-view .emp-hero > * { position:relative; z-index:1; }
.employee-dashboard-view .emp-hero h1 { margin:0 0 6px; font-size:30px; line-height:1.15; color:#fff; }
.employee-dashboard-view .emp-sub { color:rgba(255,255,255,.92); font-size:13px; }
.employee-dashboard-view .emp-chip-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
.employee-dashboard-view .emp-chip {
  display:inline-flex; align-items:center; gap:6px; padding:6px 10px;
  border-radius:999px; border:1px solid rgba(255,255,255,.28);
  background:rgba(255,255,255,.13); color:#fff; font-size:12px; font-weight:600;
}
.employee-dashboard-view .emp-actions { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
.employee-dashboard-view .work-widget {
  text-align:left; width:100%; border:1px solid var(--border); border-radius:14px; padding:14px;
  background:linear-gradient(180deg,rgba(16,185,129,.06),rgba(59,130,246,.04));
}
.employee-dashboard-view .work-widget-label { font-size:11px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-light); }
.employee-dashboard-view .work-widget-time { font-size:36px; font-weight:800; letter-spacing:1px; margin:6px 0 4px; color:var(--text-dark); font-variant-numeric:tabular-nums; }
.employee-dashboard-view .work-widget-meta { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:12px; margin-bottom:8px; }
.employee-dashboard-view .work-widget-meta strong { color:var(--text-dark); }
.employee-dashboard-view .work-progress { height:10px; border-radius:999px; overflow:hidden; background:#e5e7eb; }
.employee-dashboard-view .work-progress-fill { height:100%; width:0; background:linear-gradient(90deg,#10b981,#3b82f6); transition:width .4s ease; }
.employee-dashboard-view .status-pill { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 10px; font-size:11px; font-weight:700; border:1px solid transparent; }
.employee-dashboard-view .status-pill.in  { color:#166534; background:rgba(34,197,94,.15);  border-color:rgba(34,197,94,.35); }
.employee-dashboard-view .status-pill.out { color:#1e3a8a; background:rgba(59,130,246,.14); border-color:rgba(59,130,246,.32); }
.employee-dashboard-view .clock-mini { margin-top:10px; display:flex; justify-content:space-between; align-items:center; font-size:12px; color:var(--text-light); }
.employee-dashboard-view .clock-mini strong { color:var(--text-dark); font-size:18px; letter-spacing:.4px; }
.employee-dashboard-view .emp-action-card {
  text-decoration:none; display:flex; align-items:center; gap:12px; padding:14px 16px;
  border-radius:14px; border:1px solid var(--border); background:var(--card-bg);
  transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;
}
.employee-dashboard-view .emp-action-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); border-color:rgba(16,185,129,.45); }
.employee-dashboard-view .emp-action-icon { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:16px; }
.employee-dashboard-view .emp-action-title { font-weight:700; color:var(--text-dark); font-size:14px; }
.employee-dashboard-view .emp-action-sub  { color:var(--text-light); font-size:12px; margin-top:2px; }
.employee-dashboard-view .emp-insight-grid { display:grid; grid-template-columns:1.3fr 1fr; gap:16px; }
.employee-dashboard-view .timeline-list { padding:8px 18px 14px; }
.employee-dashboard-view .timeline-item { display:flex; align-items:flex-start; gap:10px; padding:10px 0; border-bottom:1px solid var(--border); }
.employee-dashboard-view .timeline-item:last-child { border-bottom:0; }
.employee-dashboard-view .timeline-dot { width:9px; height:9px; border-radius:50%; margin-top:5px; background:var(--primary); flex-shrink:0; }
.employee-dashboard-view .timeline-main { font-size:13px; font-weight:600; color:var(--text-dark); }
.employee-dashboard-view .timeline-sub  { font-size:12px; color:var(--text-light); margin-top:2px; }
.btn.loading { opacity:.65; pointer-events:none; }
.btn.loading::after { content:' …'; }
@media (max-width:900px) {
  .employee-dashboard-view .emp-insight-grid { grid-template-columns:1fr; }
  .employee-dashboard-view .emp-hero h1 { font-size:24px; }
}
@media (max-width:640px) {
  .employee-dashboard-view .emp-hero { padding:16px 14px; border-radius:14px; }
  .employee-dashboard-view .emp-hero h1 { font-size:22px; }
  .employee-dashboard-view .emp-chip { font-size:11.5px; padding:5px 9px; }
  .employee-dashboard-view .emp-actions { grid-template-columns:1fr; }
}
</style>
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-fluid">
  <?php if ($role === 'employee' && $me): ?>

    <!-- ══════════════════════════════════════════════
       EMPLOYEE DASHBOARD
  ══════════════════════════════════════════════ -->
    <?php
    $totalUsedLeaves = ($myBalance['casual_used'] ?? 0) + ($myBalance['sick_used'] ?? 0) + ($myBalance['paid_used'] ?? 0);
    $totalQuota      = ($myBalance['casual_quota'] ?? 10) + ($myBalance['sick_quota'] ?? 10) + ($myBalance['paid_quota'] ?? 15);
    $totalLeftLeaves = max(0, $totalQuota - $totalUsedLeaves);
    ?>
    <div class="employee-dashboard-view">

      <!-- Hero banner -->
      <div class="emp-hero">
        <h1>Hello, <?= htmlspecialchars($me['name']) ?></h1>
        <div class="emp-sub"><?= date('l, F d, Y') ?> · <?= ucfirst($me['designation'] ?? 'Employee') ?></div>
        <div class="emp-chip-row">
          <span class="emp-chip"><i class="fas fa-circle-check"></i><?= ucfirst($me['status'] ?? 'active') ?></span>
          <span class="emp-chip"><i class="fas fa-calendar-check"></i><?= $myAttMonth ?> present this month</span>
          <span class="emp-chip"><i class="fas fa-balance-scale"></i><?= $totalLeftLeaves ?> leaves left</span>
          <?php if ($myLatestPay): ?>
            <span class="emp-chip"><i class="fas fa-wallet"></i>Last pay ₹<?= number_format($myLatestPay['net_salary'], 0) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Clock + stats -->
      <div class="employee-top-layout">
        <div class="card employee-clock-card">
          <div class="card-body clock-card">

            <!-- Working hours timer widget -->
            <div class="work-widget">
              <div class="work-widget-label">Today's Working Hours</div>
              <div class="work-widget-time" id="work-duration">00:00:00</div>
              <div class="work-widget-meta">
                <span id="work-progress-text"><strong><?= $todayWorkedPct ?>%</strong> of 8h target</span>
                <span class="status-pill <?= $activeSessionStartUnix > 0 ? 'in' : 'out' ?>" id="work-status-pill">
                  <i class="fas fa-circle" style="font-size:8px"></i>
                  <?= $activeSessionStartUnix > 0 ? 'Checked In' : 'Checked Out' ?>
                </span>
              </div>
              <div class="work-progress">
                <div class="work-progress-fill" id="work-progress-fill" style="width:<?= $todayWorkedPct ?>%"></div>
              </div>
              <div class="clock-mini">

              </div>
            </div>

            <div class="clock-date" style="margin:10px 0 8px"><?= date('l, F d, Y') ?></div>
            <div style="font-size:12px;color:var(--text-light);margin-bottom:8px">
              Status: <strong style="color:var(--text-dark)" id="today-status-text"><?= htmlspecialchars($todayStatusText) ?></strong>
            </div>

            <!-- ──────────────────────────────────────────────────────────────
               Clock action buttons
               FIX: After checkout, show Check In again (multi-session).
               PHP renders 3 states:
                 1. Never checked in today          → Check In button
                 2. Active open session             → Check Out button
                 3. Had session(s) but all closed   → "Session ended" label
                                                      + Check In button (NEW)
          ────────────────────────────────────────────────────────────────── -->
            <div class="clock-actions" id="clock-actions-wrap">
              <?php if (!$myTodayRec): ?>
                <!-- State 1: No record at all for today -->
                <button class="btn btn-success" id="btn-checkin" onclick="doAttendance('checkin')">
                  <i class="fas fa-sign-in-alt"></i> Check In
                </button>

              <?php elseif ($activeSessionStartUnix > 0): ?>
                <!-- State 2: Currently checked in (open session exists) -->
                <button class="btn btn-danger" id="btn-checkout" onclick="doAttendance('checkout')">
                  <i class="fas fa-sign-out-alt"></i> Check Out
                </button>
                <div style="font-size:12px;color:var(--success);margin-top:8px;font-weight:600">
                  <i class="fas fa-check-circle"></i> In: <?= date('h:i A', $activeSessionStartUnix) ?>
                </div>

              <?php else: ?>
                <!-- State 3: Had at least one session today, all are closed.
                   Show a summary + allow checking in again (lunch break etc.) -->
                <div style="margin-bottom:10px;color:var(--success);font-weight:600;font-size:13px">
                  <i class="fas fa-check-double"></i> Session Ended &nbsp;·&nbsp; <?= $todayFirstIn ?> – <?= $todayLastOut ?>
                </div>
                <button class="btn btn-success" id="btn-checkin" onclick="doAttendance('checkin')">
                  <i class="fas fa-sign-in-alt"></i> Check In Again
                </button>
              <?php endif; ?>
            </div>

            <!-- First in / Last out / Sessions info bar -->
            <div style="display:flex;justify-content:center;gap:12px;flex-wrap:wrap;margin-top:12px;font-size:12px;color:var(--text-light)">
              <span><i class="fas fa-arrow-right-to-bracket" style="color:var(--success)"></i> First In: <strong style="color:var(--text-dark)"><?= $todayFirstIn ?></strong></span>
              <span><i class="fas fa-arrow-right-from-bracket" style="color:var(--danger)"></i> Last Out: <strong style="color:var(--text-dark)"><?= $todayLastOut ?></strong></span>
              <span><i class="fas fa-layer-group" style="color:var(--primary)"></i> Sessions: <strong style="color:var(--text-dark)"><?= $todaySessionCount ?></strong></span>
            </div>
          </div>
        </div>

        <!-- Stats grid -->
        <div class="employee-stats-grid">
          <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-body">
              <div class="stat-value"><?= $myAttMonth ?></div>
              <div class="stat-label">Days Present (This Month)</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-business-time"></i></div>
            <div class="stat-body">
              <div class="stat-value" id="today-hours-stat"><?= number_format($todayTotalSeconds / 3600, 1) ?>h</div>
              <div class="stat-label">Worked Today</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon yellow"><i class="fas fa-calendar-minus"></i></div>
            <div class="stat-body">
              <div class="stat-value"><?= $totalUsedLeaves ?></div>
              <div class="stat-label">Leaves Used (<?= $totalQuota ?> total)</div>
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-chart-line"></i></div>
            <div class="stat-body">
              <div class="stat-value"><?= number_format($weekWorkedHours, 1) ?>h</div>
              <div class="stat-label">Worked This Week</div>
            </div>
          </div>
          <?php if ($myLatestPay): ?>
            <div class="stat-card">
              <div class="stat-icon blue"><i class="fas fa-rupee-sign"></i></div>
              <div class="stat-body">
                <div class="stat-value">₹<?= number_format($myLatestPay['net_salary'] / 1000, 1) ?>K</div>
                <div class="stat-label">Last Salary (<?= $myLatestPay['month_year'] ?>)</div>
              </div>
            </div>
          <?php endif; ?>
          <div class="stat-card" style="cursor:pointer" onclick="location.href='<?= APP_URL ?>/modules/leave/index.php'">
            <div class="stat-icon red"><i class="fas fa-paper-plane"></i></div>
            <div class="stat-body">
              <div class="stat-value" style="font-size:16px">Apply</div>
              <div class="stat-label">Request Leave</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick links -->
      <div class="emp-actions">
        <?php
        $links = [
          ['Attendance',    'Track check-in history',   'modules/attendance/index.php',                       'fa-calendar-check', '#10b981'],
          ['My Leaves',     'Apply or review requests',  'modules/leave/index.php',                            'fa-calendar-minus', '#f59e0b'],
          ['My Profile',    'Update contact and photo',  'modules/employees/profile.php?id=' . (int)$me['id'], 'fa-user',           '#3b82f6'],
          ['Notifications', 'See recent alerts',         'modules/notifications/index.php',                    'fa-bell',           '#8b5cf6'],
        ];
        foreach ($links as [$title, $sub, $path, $icon, $color]):
        ?>
          <a href="<?= APP_URL . '/' . $path ?>" class="emp-action-card">
            <div class="emp-action-icon" style="background:<?= $color ?>22;color:<?= $color ?>"><i class="fas <?= $icon ?>"></i></div>
            <div>
              <div class="emp-action-title"><?= $title ?></div>
              <div class="emp-action-sub"><?= $sub ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Recent attendance + leave balance -->
      <div class="emp-insight-grid">
        <div class="card">
          <div class="card-header">
            <h2><i class="fas fa-history" style="color:var(--primary);margin-right:8px"></i>Recent Attendance</h2>
            <a href="<?= APP_URL ?>/modules/attendance/index.php" class="btn btn-outline btn-sm">View All</a>
          </div>
          <div class="timeline-list">
            <?php if (!$myRecentAtt): ?>
              <div style="color:var(--text-light);font-size:13px;padding:12px 0">No attendance records yet.</div>
              <?php else: foreach ($myRecentAtt as $a): ?>
                <div class="timeline-item">
                  <span class="timeline-dot"></span>
                  <div>
                    <div class="timeline-main"><?= date('D, M d', strtotime($a['date'])) ?> · <?= ucfirst($a['status']) ?></div>
                    <div class="timeline-sub">
                      In: <?= (!empty($a['check_in'])  && $a['check_in']  !== '0000-00-00 00:00:00') ? date('h:i A', strtotime($a['check_in']))  : '—' ?>
                      · Out: <?= (!empty($a['check_out']) && $a['check_out'] !== '0000-00-00 00:00:00') ? date('h:i A', strtotime($a['check_out'])) : '—' ?>
                      · Worked: <?= number_format(((int)($a['worked_minutes'] ?? 0)) / 60, 1) ?>h
                    </div>
                  </div>
                </div>
            <?php endforeach;
            endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2><i class="fas fa-balance-scale" style="color:var(--success);margin-right:8px"></i>Leave Balance — <?= $year ?></h2>
            <a href="<?= APP_URL ?>/modules/leave/index.php" class="btn btn-outline btn-sm">Apply Leave</a>
          </div>
          <div class="card-body">
            <div class="employee-leave-grid" style="grid-template-columns:1fr;gap:12px">
              <?php foreach ([['Casual', 'casual', '#5B6EF5'], ['Sick', 'sick', '#EF4444'], ['Paid', 'paid', '#10B981']] as [$ln, $lk, $lc]):
                $used  = $myBalance[$lk . '_used']  ?? 0;
                $quota = $myBalance[$lk . '_quota'] ?? ($lk === 'paid' ? 15 : 10);
                $rem   = max(0, $quota - $used);
                $pct   = $quota > 0 ? min(100, round($used / $quota * 100)) : 0;
              ?>
                <div style="background:var(--secondary);border-radius:var(--radius-sm);padding:12px;border:1px solid var(--border)">
                  <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                    <span style="font-weight:600"><?= $ln ?> Leave</span>
                    <span style="font-size:12px;color:<?= $rem > 0 ? 'var(--success)' : 'var(--danger)' ?>;font-weight:600"><?= $rem ?> left</span>
                  </div>
                  <div style="background:#E5E7EB;border-radius:6px;height:7px;margin-bottom:8px;overflow:hidden">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 100 ? 'var(--danger)' : $lc ?>;border-radius:6px"></div>
                  </div>
                  <div style="font-size:12px;color:var(--text-light)"><?= $used ?> used / <?= $quota ?> days</div>
                </div>
              <?php endforeach; ?>
            </div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
              <div style="font-size:12px;color:var(--text-light);margin-bottom:8px">Recent leave requests</div>
              <?php if (!$myRecentLeaves): ?>
                <div style="font-size:12.5px;color:var(--text-light)">No leave requests yet.</div>
                <?php else: foreach ($myRecentLeaves as $lv): ?>
                  <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px">
                    <span><?= ucfirst($lv['leave_type']) ?> · <?= date('M d', strtotime($lv['start_date'])) ?></span>
                    <span class="badge badge-<?= htmlspecialchars($lv['status']) ?>"><?= ucfirst($lv['status']) ?></span>
                  </div>
              <?php endforeach;
              endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($role === 'hr'): ?>

    <!-- ══════════════════════════════════════════════
       HR DASHBOARD
  ══════════════════════════════════════════════ -->
    <div class="page-header">
      <div>
        <h1>HR Dashboard</h1>
        <p>Welcome, <?= htmlspecialchars($_SESSION['username']) ?> &nbsp;·&nbsp; <?= date('l, F d, Y') ?></p>
      </div>
    </div>
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $totalEmp ?></div>
          <div class="stat-label">Total Employees</div>
          <div class="stat-change up"><i class="fas fa-user-check"></i> <?= $activeEmp ?> active</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $presentToday ?></div>
          <div class="stat-label">Present Today</div>
          <div class="stat-change up"><?= $totalEmp > 0 ? round($presentToday / $totalEmp * 100) : 0 ?>% rate</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-calendar-minus"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $pendingLeaves ?></div>
          <div class="stat-label">Pending Leaves</div>
          <div class="stat-change <?= $pendingLeaves > 0 ? 'down' : 'up' ?>"><?= $pendingLeaves > 0 ? 'Needs review' : 'All cleared' ?></div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-sitemap"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $deptCount ?></div>
          <div class="stat-label">Departments</div>
        </div>
      </div>
    </div>
    <div class="hr-dashboard-grid">
      <div class="card">
        <div class="card-header">
          <h2><i class="fas fa-clock" style="color:var(--warning);margin-right:8px"></i>Pending Leave Requests</h2>
          <a href="<?= APP_URL ?>/modules/leave/index.php?status=pending" class="btn btn-outline btn-sm">View All</a>
        </div>
        <?php
        $pendLeaves = $pdo->query("
          SELECT lr.*, e.name, e.employee_id AS emp_id
          FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id
          WHERE lr.status='pending' ORDER BY lr.created_at DESC LIMIT 6
      ")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!$pendLeaves): ?>
          <div style="padding:32px;text-align:center;color:var(--success)"><i class="fas fa-check-circle" style="font-size:28px;margin-bottom:8px;display:block"></i>All caught up!</div>
          <?php else: foreach ($pendLeaves as $pl):
            $days = (strtotime($pl['end_date']) - strtotime($pl['start_date'])) / 86400 + 1; ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border)">
              <div class="emp-avatar" style="flex-shrink:0"><?= strtoupper(substr($pl['name'], 0, 1)) ?></div>
              <div style="flex:1;min-width:0">
                <div style="font-weight:500;font-size:13px"><?= htmlspecialchars($pl['name']) ?></div>
                <div style="font-size:12px;color:var(--text-light)"><?= ucfirst($pl['leave_type']) ?> · <?= $days ?> day<?= $days > 1 ? 's' : '' ?> · <?= date('M d', strtotime($pl['start_date'])) ?></div>
              </div>
              <div style="display:flex;gap:6px">
                <button onclick="quickReview(<?= $pl['id'] ?>,'approve')" class="btn btn-sm btn-success" style="padding:5px 10px"><i class="fas fa-check"></i></button>
                <button onclick="quickReview(<?= $pl['id'] ?>,'reject')" class="btn btn-sm btn-danger" style="padding:5px 10px"><i class="fas fa-times"></i></button>
              </div>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
      <div class="card">
        <div class="card-header">
          <h2><i class="fas fa-chart-bar" style="color:var(--primary);margin-right:8px"></i>Headcount by Department</h2>
        </div>
        <div class="card-body">
          <div class="chart-container" style="height:240px"><canvas id="deptHCChart"></canvas></div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-header">
        <h2>Recent Employees</h2><a href="<?= APP_URL ?>/modules/employees/index.php" class="btn btn-outline btn-sm">View All</a>
      </div>
      <?= renderRecentEmpsTable($recentEmps) ?>
    </div>

  <?php else: ?>

    <!-- ══════════════════════════════════════════════
       ADMIN DASHBOARD
  ══════════════════════════════════════════════ -->
    <div class="page-header">
      <div>
        <h1>Dashboard</h1>
        <p>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>! &nbsp;·&nbsp; <?= date('l, F d, Y') ?></p>
      </div>
      <span style="font-size:13px;color:var(--text-light);display:flex;align-items:center;gap:6px"><i class="fas fa-circle" style="color:var(--success);font-size:8px"></i> System Online</span>
    </div>
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $totalEmp ?></div>
          <div class="stat-label">Total Employees</div>
          <div class="stat-change up"><i class="fas fa-arrow-up"></i> <?= $activeEmp ?> active</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $presentToday ?></div>
          <div class="stat-label">Present Today</div>
          <div class="stat-change <?= $presentToday > 0 ? 'up' : '' ?>"><?= $totalEmp > 0 ? round($presentToday / $totalEmp * 100) : 0 ?>% attendance rate</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-calendar-minus"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $pendingLeaves ?></div>
          <div class="stat-label">Pending Leaves</div>
          <div class="stat-change <?= $pendingLeaves > 0 ? 'down' : 'up' ?>"><i class="fas fa-clock"></i> Awaiting approval</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-sitemap"></i></div>
        <div class="stat-body">
          <div class="stat-value"><?= $deptCount ?></div>
          <div class="stat-label">Departments</div>
          <div class="stat-change up"><i class="fas fa-check"></i> All active</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-wallet"></i></div>
        <div class="stat-body">
          <div class="stat-value">₹<?= number_format($monthlyPayroll / 1000, 1) ?>K</div>
          <div class="stat-label">Monthly Payroll</div>
          <div class="stat-change up"><i class="fas fa-calendar"></i> <?= date('M Y') ?></div>
        </div>
      </div>
    </div>
    <div class="admin-charts-grid">
      <div class="card">
        <div class="card-header">
          <h2><i class="fas fa-chart-line" style="color:var(--primary);margin-right:8px"></i>Employee Growth</h2>
        </div>
        <div class="card-body">
          <div class="chart-container"><canvas id="growthChart"></canvas></div>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <h2><i class="fas fa-chart-bar" style="color:var(--success);margin-right:8px"></i>Attendance (Last 7 Days)</h2>
        </div>
        <div class="card-body">
          <div class="chart-container"><canvas id="attChart"></canvas></div>
        </div>
      </div>
    </div>
    <div class="admin-lower-grid">
      <div class="card">
        <div class="card-header">
          <h2>Recent Employees</h2><a href="<?= APP_URL ?>/modules/employees/index.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <?= renderRecentEmpsTable($recentEmps) ?>
      </div>
      <div class="card">
        <div class="card-header">
          <h2><i class="fas fa-history" style="color:var(--info);margin-right:8px"></i>Activity Feed</h2><a href="<?= APP_URL ?>/modules/activity/index.php" class="btn btn-outline btn-sm">All Logs</a>
        </div>
        <?php foreach ($recentActivity as $act):
          $modIcons = ['employees' => 'fa-users', 'leave' => 'fa-calendar-minus', 'payroll' => 'fa-wallet', 'attendance' => 'fa-calendar-check', 'departments' => 'fa-sitemap', 'auth' => 'fa-lock', 'settings' => 'fa-cog', 'search' => 'fa-search'];
          $icon = $modIcons[$act['module']] ?? 'fa-circle';
        ?>
          <div style="display:flex;gap:10px;padding:10px 18px;border-bottom:1px solid var(--border)">
            <div style="width:30px;height:30px;background:var(--primary-light);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:12px;flex-shrink:0"><i class="fas <?= $icon ?>"></i></div>
            <div>
              <div style="font-size:12.5px;font-weight:500"><?= htmlspecialchars($act['action']) ?></div>
              <div style="font-size:11.5px;color:var(--text-light)"><?= htmlspecialchars($act['username']) ?> · <?= date('M d, h:i A', strtotime($act['created_at'])) ?></div>
            </div>
          </div>
        <?php endforeach;
        if (!$recentActivity): ?>
          <div style="padding:32px;text-align:center;color:var(--text-light);font-size:13px">No recent activity</div>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>
</div>

<?php
// ── Helper ───────────────────────────────────────────────────────────────────
function renderRecentEmpsTable(array $emps): string
{
  if (!$emps) return '<div style="padding:32px;text-align:center;color:var(--text-light)">No employees found</div>';
  $html = '<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Employee</th><th>Department</th><th>Designation</th><th>Status</th></tr></thead><tbody>';
  foreach ($emps as $emp) {
    $initials = strtoupper(substr($emp['name'], 0, 1));
    $badge    = 'badge-' . $emp['status'];
    $stTxt    = ucfirst(str_replace('_', ' ', $emp['status']));
    $html .= "<tr>
            <td><div class='emp-cell'><div class='emp-avatar'>$initials</div><div>
              <div class='emp-name'>" . htmlspecialchars($emp['name']) . "</div>
              <div class='emp-id'>{$emp['employee_id']}</div>
            </div></div></td>
            <td>" . htmlspecialchars($emp['department_name'] ?? '—') . "</td>
            <td>" . htmlspecialchars($emp['designation'] ?? '—') . "</td>
            <td><span class='badge $badge'>$stTxt</span></td>
          </tr>";
  }
  return $html . '</tbody></table></div>';
}

// ── URL constants for JS (heredoc cannot use PHP short-echo tags) ──────────
// Points to modules/attendance/index.php — the real POST handler.
$attendanceUrl = APP_URL . '/modules/attendance/index.php';
$leaveUrl = APP_URL . '/modules/leave/index.php';

// ── Chart data ───────────────────────────────────────────────────────────────
$growthLabels = json_encode(array_column($growthData,'month'));
$growthCounts = json_encode(array_column($growthData,'count'));
$attLabels = json_encode(array_column($attData,'date'));
$attCounts = json_encode(array_column($attData,'count'));
$deptLabels = json_encode(array_column($deptCounts,'department_name'));
$deptCnts = json_encode(array_column($deptCounts,'cnt'));

// ── Timer payload ─────────────────────────────────────────────────────────────
// activeSessionStartMs: millisecond unix timestamp of the currently OPEN session's
// check_in, as computed by PHP. JS uses this fixed anchor every tick:
// elapsed = Date.now() - activeSessionStartMs
// This means the counter is accurate even after tab switches — no drift.
// 0 = no open session; timer stays frozen at closedWorkedSeconds.
$activeSessionStartMs = $activeSessionStartUnix > 0 ? $activeSessionStartUnix * 1000 : 0;
$timerJson = json_encode([
'targetSeconds' => $targetWorkMinutes * 60, // 28800
'closedWorkedSeconds' => $todayClosedWorkedSeconds, // sum of finished sessions
'activeSessionStartMs' => $activeSessionStartMs, // 0 = checked out
], JSON_UNESCAPED_SLASHES);

$extraScripts = <<<JS
  <script>
  // ════════════════════════════════════════════════════════════════════
  // Server-supplied constants
  // ════════════════════════════════════════════════════════════════════
  const ATTENDANCE_URL = '$attendanceUrl';
  const LEAVE_URL = '$leaveUrl';

  // TIMER object injected by PHP:
  // targetSeconds – 8 h target in seconds (28800)
  // closedWorkedSeconds – seconds already worked in closed sessions today
  // activeSessionStartMs – Date.now()-compatible timestamp when the current
  // open session started; 0 = no open session
  const TIMER = $timerJson;

  // ════════════════════════════════════════════════════════════════════
  // Utilities
  // ════════════════════════════════════════════════════════════════════
  function pad2(n) { return String(n).padStart(2,'0'); }
  function toHMS(s) {
  s = Math.max(0, Math.floor(s));
  return pad2(Math.floor(s/3600)) + ':' + pad2(Math.floor((s%3600)/60)) + ':' + pad2(s%60);
  }

  // ════════════════════════════════════════════════════════════════════
  // Live wall clock — independent of session state
  // ════════════════════════════════════════════════════════════════════
  (function tickClock() {
  const el = document.getElementById('live-clock');
  if (el) el.textContent = new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  setTimeout(tickClock, 1000);
  })();

  // ════════════════════════════════════════════════════════════════════
  // Working-hours timer
  //
  // FIX — Tab switch caused timer reset to 0:
  // Root cause: the old code used a running `elapsed` counter that
  // re-initialised to 0 every time the tab became visible (some
  // browsers throttle setInterval when hidden, or re-run initialisers).
  //
  // Fix: anchor to a FIXED server-supplied timestamp (activeSessionStartMs).
  // Every tick we compute:
  // elapsed = Date.now() - TIMER.activeSessionStartMs
  // Because Date.now() and the anchor are both absolute, the result is
  // always correct regardless of how long the tab was hidden, throttled,
  // or backgrounded. No state is stored in a variable that could reset.
  // ════════════════════════════════════════════════════════════════════
  (function initWorkTimer() {
  const durationEl = document.getElementById('work-duration');
  const progressFill = document.getElementById('work-progress-fill');
  const progressText = document.getElementById('work-progress-text');
  const statusPill = document.getElementById('work-status-pill');
  const todayStat = document.getElementById('today-hours-stat');
  if (!durationEl) return;

  const TARGET = TIMER.targetSeconds; // 28800
  const CLOSED = TIMER.closedWorkedSeconds; // seconds from past closed sessions (now correct after auth.php fix)
  const START_MS = TIMER.activeSessionStartMs; // 0 = no active session
  const ACTIVE = START_MS > 0;

  function paint() {
  // elapsed: always recomputed from the absolute anchor — never drifts
  const elapsed = ACTIVE ? Math.max(0, Math.floor((Date.now() - START_MS) / 1000)) : 0;
  const total = CLOSED + elapsed;

  durationEl.textContent = toHMS(total);
  if (todayStat) todayStat.textContent = (total / 3600).toFixed(1) + 'h';

  const pct = TARGET > 0 ? Math.min(100, Math.round(total / TARGET * 100)) : 0;
  if (progressFill) progressFill.style.width = pct + '%';
  if (progressText) progressText.innerHTML = '<strong>' + pct + '%</strong> of 8h target';

  if (statusPill) {
  statusPill.className = 'status-pill ' + (ACTIVE ? 'in' : 'out');
  statusPill.innerHTML = '<i class="fas fa-circle" style="font-size:8px"></i> ' + (ACTIVE ? 'Checked In' : 'Checked Out');
  }
  }

  paint(); // immediate render — no blank flash on load
  setInterval(paint, 1000); // re-evaluate every second

  // Also repaint instantly when the tab becomes visible again —
  // catches cases where the browser paused the interval while hidden
  document.addEventListener('visibilitychange', function() {
  if (!document.hidden) paint();
  });
  })();

  // ════════════════════════════════════════════════════════════════════
  // Check-in / Check-out AJAX
  // ════════════════════════════════════════════════════════════════════
  async function doAttendance(action) {
  const btns = document.querySelectorAll('#clock-actions-wrap .btn, #btn-checkin, #btn-checkout');
  btns.forEach(b => { b.disabled = true; b.classList.add('loading'); });

  try {
  const fd = new FormData();
  fd.append('action', action);

  const response = await fetch(ATTENDANCE_URL, {
  method: 'POST', body: fd, credentials: 'same-origin',
  });
  if (!response.ok) throw new Error('HTTP ' + response.status);

  let data;
  try { data = await response.json(); }
  catch (e) { throw new Error('Server returned non-JSON — check PHP error logs.'); }

  if (typeof showToast === 'function') {
  showToast(data.message || 'Done!', data.success ? 'success' : 'error');
  } else {
  alert((data.success ? '✅ ' : '❌ ') + (data.message || 'Done!'));
  }

  if (data.success) {
  // Reload so PHP recomputes all timer values with the new session state
  setTimeout(() => location.reload(), 900);
  } else {
  btns.forEach(b => { b.disabled = false; b.classList.remove('loading'); });
  }
  } catch (err) {
  console.error('[doAttendance]', err);
  if (typeof showToast === 'function') {
  showToast('Error: ' + err.message, 'error');
  } else {
  alert('❌ ' + err.message);
  }
  btns.forEach(b => { b.disabled = false; b.classList.remove('loading'); });
  }
  }

  // ════════════════════════════════════════════════════════════════════
  // HR: quick leave approve / reject
  // ════════════════════════════════════════════════════════════════════
  async function quickReview(id, action) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('id', id);
  try {
  const r = await fetch(LEAVE_URL, { method:'POST', body:fd, credentials:'same-origin' });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  const d = await r.json();
  if (typeof showToast === 'function') showToast(d.message||'Done!', d.success?'success':'error');
  if (d.success) setTimeout(() => location.reload(), 900);
  } catch (e) {
  if (typeof showToast === 'function') showToast('Error: '+e.message,'error');
  }
  }

  // ════════════════════════════════════════════════════════════════════
  // Charts
  // ════════════════════════════════════════════════════════════════════
  if (document.getElementById('growthChart')) {
  new Chart(document.getElementById('growthChart'), {
  type:'line',
  data:{ labels:$growthLabels, datasets:[{ label:'Employees', data:$growthCounts,
  borderColor:'#5B6EF5', backgroundColor:'rgba(91,110,245,.1)',
  fill:true, tension:.4, pointBackgroundColor:'#5B6EF5', pointRadius:5 }] },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
  scales:{ y:{beginAtZero:true,grid:{color:'#f3f4f6'}}, x:{grid:{display:false}} } }
  });
  }
  if (document.getElementById('attChart')) {
  new Chart(document.getElementById('attChart'), {
  type:'bar',
  data:{ labels:$attLabels, datasets:[{ label:'Present', data:$attCounts,
  backgroundColor:'rgba(16,185,129,.75)', borderRadius:6, borderSkipped:false }] },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
  scales:{ y:{beginAtZero:true,grid:{color:'#f3f4f6'}}, x:{grid:{display:false}} } }
  });
  }
  if (document.getElementById('deptHCChart')) {
  new Chart(document.getElementById('deptHCChart'), {
  type:'bar',
  data:{ labels:$deptLabels, datasets:[{ label:'Employees', data:$deptCnts,
  backgroundColor:['#5B6EF5','#10B981','#F59E0B','#EF4444','#9333EA','#3B82F6'],
  borderRadius:6, borderSkipped:false }] },
  options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}},
  scales:{ y:{beginAtZero:true,grid:{color:'#f3f4f6'},ticks:{stepSize:1}}, x:{grid:{display:false}} } }
  });
  }
  </script>
  JS;

  require_once __DIR__ . '/../includes/footer.php';
  ?>
