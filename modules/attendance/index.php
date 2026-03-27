<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle   = 'Attendance';
$currentPage = 'attendance';
$pdo         = getDB();

$me    = getCurrentEmployee();
$today = appToday();
ensureWorkTrackingSchema();

// ── Check-In / Check-Out ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['role'] === 'employee') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if (!$me) {
        echo json_encode(['success' => false, 'message' => 'Employee profile not found.']);
        exit;
    }

    try {
        if ($action === 'checkin') {
            $ok = startWorkSession((int)$_SESSION['user_id'], 'manual');
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Work session started.' : 'Already in an active work session.']);
            exit;
        }

        if ($action === 'checkout') {
            $ok = endWorkSession((int)$_SESSION['user_id'], 'manual');
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Work session ended.' : 'No active work session to end.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid attendance action.']);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update attendance.']);
    }
    exit;
}

// ── Filters ────────────────────────────────────────────────
$filterDate = $_GET['date'] ?? date('Y-m');
$filterEmp  = (int)($_GET['emp'] ?? 0);
$page       = max(1,(int)($_GET['page']??1));
$perPage    = 15; $offset = ($page-1)*$perPage;

$where  = 'WHERE 1=1';
$params = [];

if ($_SESSION['role'] === 'employee' && $me) {
    $where .= ' AND a.employee_id = ?'; $params[] = $me['id'];
}
if ($filterDate) {
    $where .= ' AND DATE_FORMAT(a.date,"%Y-%m") = ?'; $params[] = $filterDate;
}
if ($filterEmp && hasRole('admin','hr')) {
    $where .= ' AND a.employee_id = ?'; $params[] = $filterEmp;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$pages = ceil($total / $perPage);

$stmt = $pdo->prepare("
    SELECT a.*, e.name, e.employee_id AS emp_id, e.photo
    FROM attendance a
    JOIN employees e ON e.id = a.employee_id
    $where ORDER BY a.date DESC, a.check_in DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// ── Today's state for employee widget ─────────────────────
$todayRecord              = null;
$todayClosedWorkedSeconds = 0;
$activeSessionStartUnix   = 0;
$todaySessionCount        = 0;
$todayWorkedHours         = 0.0;
$targetWorkSeconds        = 8 * 3600;
$todayStatusText          = 'Not marked yet';
$todayFirstIn             = '—';
$todayLastOut             = '—';
$todaySessions            = [];

// ── IST timezone helper ────────────────────────────────────
function toIST(string $datetime): string {
    return formatStoredUtcToApp($datetime);
}

// ── Duration formatter: Xh Ym ─────────────────────────────
function formatDuration(int $minutes): string {
    if ($minutes <= 0) return '—';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0 && $m > 0) return "{$h}h {$m}m";
    if ($h > 0)            return "{$h}h";
    return "{$m}m";
}

if ($me) {
    $tr = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND date = ?');
    $tr->execute([$me['id'], $today]);
    $todayRecord = $tr->fetch();

    $ss = $pdo->prepare('
        SELECT id, check_in, check_out, duration_minutes, source
        FROM attendance_sessions
        WHERE employee_id = ? AND session_date = ?
        ORDER BY check_in ASC
    ');
    $ss->execute([(int)$me['id'], $today]);
    $todaySessions = $ss->fetchAll();

    $lastClosedCheckout = null;

    foreach ($todaySessions as $sess) {
        $todaySessionCount++;
        $isClosed = !empty($sess['check_out']) && $sess['check_out'] !== '0000-00-00 00:00:00';

        if ($isClosed) {
            $durationSec = (int)($sess['duration_minutes'] ?? 0) * 60;
            if ($durationSec === 0 && !empty($sess['check_in']) && !empty($sess['check_out'])) {
                $durationSec = max(0, storedUtcToUnix((string)$sess['check_out']) - storedUtcToUnix((string)$sess['check_in']));
            }
            $todayClosedWorkedSeconds += $durationSec;

            $coTs = storedUtcToUnix((string)$sess['check_out']);
            if ($coTs && ($lastClosedCheckout === null || $coTs > $lastClosedCheckout)) {
                $lastClosedCheckout = $coTs;
            }
        } else {
            $ts = !empty($sess['check_in']) ? storedUtcToUnix((string)$sess['check_in']) : 0;
            if ($ts > 0) $activeSessionStartUnix = $ts;
        }
    }

    $mx = $pdo->prepare('
        SELECT MAX(check_out) AS last_out
        FROM attendance_sessions
        WHERE employee_id = ? AND session_date = ? AND check_out IS NOT NULL
    ');
    $mx->execute([(int)$me['id'], $today]);
    $maxClosedOut = (string)($mx->fetchColumn() ?: '');
    if ($maxClosedOut !== '' && $maxClosedOut !== '0000-00-00 00:00:00') {
        $mxTs = storedUtcToUnix($maxClosedOut);
        if ($mxTs > 0) $lastClosedCheckout = $mxTs;
    }

    if ($todaySessionCount === 0 && $todayRecord && !empty($todayRecord['worked_minutes'])) {
        $todayClosedWorkedSeconds = (int)$todayRecord['worked_minutes'] * 60;
    }

    // ── First In (IST) ────────────────────────────────────
    if ($todayRecord && !empty($todayRecord['check_in']) && $todayRecord['check_in'] !== '0000-00-00 00:00:00') {
        $todayFirstIn = toIST((string)$todayRecord['check_in']);
    } elseif (!empty($todaySessions[0]['check_in'])) {
        $todayFirstIn = toIST((string)$todaySessions[0]['check_in']);
    }

    // ── Last Out (IST) ────────────────────────────────────
    if ($activeSessionStartUnix > 0) {
        $todayLastOut = $lastClosedCheckout ? date('h:i A', $lastClosedCheckout) : '—';
    } else {
        if ($lastClosedCheckout) {
            $todayLastOut = date('h:i A', $lastClosedCheckout);
        } elseif ($todayRecord && !empty($todayRecord['check_out']) && $todayRecord['check_out'] !== '0000-00-00 00:00:00') {
            $todayLastOut = toIST((string)$todayRecord['check_out']);
        }
    }

    $activeElapsed    = $activeSessionStartUnix > 0 ? max(0, time() - $activeSessionStartUnix) : 0;
    $todayWorkedHours = round(($todayClosedWorkedSeconds + $activeElapsed) / 3600, 1);

    if (!$todayRecord && $todaySessionCount === 0) {
        $todayStatusText = 'Not marked yet';
    } elseif ($activeSessionStartUnix > 0) {
        $todayStatusText = 'Checked in';
    } else {
        $todayStatusText = 'Session ended';
    }
}

$allEmps = hasRole('admin','hr') ? $pdo->query('SELECT id,name,employee_id FROM employees ORDER BY name')->fetchAll() : [];

function attendancePublicHolidays(int $year): array {
    $holidays = [
        sprintf('%d-01-01', $year) => "New Year's Day",
        sprintf('%d-01-26', $year) => 'Republic Day',
        sprintf('%d-05-01', $year) => 'Labour Day',
        sprintf('%d-08-15', $year) => 'Independence Day',
        sprintf('%d-10-02', $year) => 'Gandhi Jayanti',
        sprintf('%d-12-25', $year) => 'Christmas Day',
    ];
    if (function_exists('easter_date')) {
        $easterTs     = easter_date($year);
        $goodFridayTs = strtotime('-2 days', $easterTs);
        $holidays[date('Y-m-d', $goodFridayTs)] = 'Good Friday';
    }
    ksort($holidays);
    return $holidays;
}

$calendarDate        = preg_match('/^\d{4}-\d{2}$/', (string)$filterDate) ? $filterDate : date('Y-m');
$calendarStart       = $calendarDate . '-01';
$calendarTs          = strtotime($calendarStart);
$calendarYear        = (int)date('Y', $calendarTs);
$calendarMonth       = (int)date('m', $calendarTs);
$calendarMonthLabel  = date('F Y', $calendarTs);
$calendarPrev        = date('Y-m', strtotime('-1 month', $calendarTs));
$calendarNext        = date('Y-m', strtotime('+1 month', $calendarTs));
$calendarDaysInMonth = (int)date('t', $calendarTs);
$calendarFirstDow    = (int)date('w', $calendarTs);
$calendarWeekdays    = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$calendarHolidays    = attendancePublicHolidays($calendarYear);

$calendarEmployeeId = 0;
if ($_SESSION['role'] === 'employee' && $me) {
    $calendarEmployeeId = (int)$me['id'];
} elseif (hasRole('admin','hr') && $filterEmp > 0) {
    $calendarEmployeeId = $filterEmp;
}

$calendarEmployeeName = '';
if ($calendarEmployeeId > 0) {
    if ($me && (int)$me['id'] === $calendarEmployeeId) {
        $calendarEmployeeName = (string)$me['name'];
    } else {
        foreach ($allEmps as $e) {
            if ((int)$e['id'] === $calendarEmployeeId) {
                $calendarEmployeeName = (string)$e['name'];
                break;
            }
        }
    }
}

$calendarAttendanceByDate = [];
$calendarWorkedByDate = [];
$calendarOpenSessionByDate = [];
$calendarTimesByDate = [];

if ($calendarEmployeeId > 0) {
    // 1. Fetch attendance status and worked minutes
    $cStmt = $pdo->prepare('SELECT date, status, worked_minutes FROM attendance WHERE employee_id=? AND date>=? AND date<=?');
    $cStmt->execute([$calendarEmployeeId, $calendarStart, date('Y-m-t', $calendarTs)]);
    foreach ($cStmt->fetchAll() as $row) {
        $calendarAttendanceByDate[$row['date']] = strtolower((string)$row['status']);
        $calendarWorkedByDate[$row['date']]     = (int)($row['worked_minutes'] ?? 0);
    }

    // 2. Fetch first-in and last-out times from sessions
    $tStmt = $pdo->prepare('
        SELECT session_date, MIN(check_in) as first_in, MAX(check_out) as last_out,
               COUNT(*) as sessions, SUM(CASE WHEN check_out IS NULL THEN 1 ELSE 0 END) as open_count
        FROM attendance_sessions
        WHERE employee_id = ? AND session_date >= ? AND session_date <= ?
        GROUP BY session_date
    ');
    $tStmt->execute([$calendarEmployeeId, $calendarStart, date('Y-m-t', $calendarTs)]);
    foreach ($tStmt->fetchAll() as $row) {
        $date = (string)$row['session_date'];
        $calendarTimesByDate[$date] = [
            'first_in' => $row['first_in'] ? formatStoredUtcToApp($row['first_in']) : null,
            'last_out' => ($row['last_out'] && $row['last_out'] !== '0000-00-00 00:00:00') ? formatStoredUtcToApp($row['last_out']) : null,
            'is_open'  => (int)$row['open_count'] > 0
        ];
        if ((int)$row['open_count'] > 0) {
            $calendarOpenSessionByDate[$date] = true;
        }
    }
}

// ── Calendar summary stats ─────────────────────────────────
$calPresentDays  = 0;
$calHalfDays     = 0;
$calAbsentDays   = 0;
$calTotalMinutes = 0;
foreach ($calendarAttendanceByDate as $date => $status) {
    if ($status === 'present')  { $calPresentDays++;  $calTotalMinutes += $calendarWorkedByDate[$date] ?? 0; }
    elseif ($status === 'half_day') { $calHalfDays++; $calTotalMinutes += $calendarWorkedByDate[$date] ?? 0; }
    elseif ($status === 'absent') $calAbsentDays++;
}
$calTotalHours = round($calTotalMinutes / 60, 1);


$extraStyles = <<<CSS
<style>
/* ══════════════════════════════════════════════════════════
   ATTENDANCE CALENDAR — PREMIUM REDESIGN
══════════════════════════════════════════════════════════ */

/* ── Outer card shell ─────────────────────────────────── */
.att-cal-card {
  border-radius: 24px;
  overflow: hidden;
  border: 1px solid rgba(148,163,184,.20);
  box-shadow: 0 24px 48px rgba(2,6,23,.09);
  margin-bottom: 20px;
  background: var(--card-bg, #fff);
}

/* ── Header ───────────────────────────────────────────── */
.att-cal-header {
  padding: 24px 28px 20px;
  background: linear-gradient(135deg, #064e3b 0%, #065f46 40%, #059669 100%);
  position: relative;
  overflow: hidden;
}
.att-cal-header::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 160px; height: 160px;
  border-radius: 50%;
  background: rgba(255,255,255,.06);
  pointer-events: none;
}
.att-cal-header::after {
  content: '';
  position: absolute;
  bottom: -24px; left: 30%;
  width: 200px; height: 80px;
  border-radius: 50%;
  background: rgba(255,255,255,.04);
  pointer-events: none;
}

.att-cal-header-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}

.att-cal-month-info {}
.att-cal-month-label {
  font-size: 22px;
  font-weight: 800;
  color: #fff;
  letter-spacing: -.4px;
  line-height: 1.1;
}
.att-cal-emp-name {
  font-size: 13px;
  color: rgba(255,255,255,.72);
  margin-top: 4px;
  font-weight: 500;
}

.att-cal-nav {
  display: flex;
  align-items: center;
  gap: 6px;
}
.att-cal-nav-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px; height: 36px;
  border-radius: 10px;
  background: rgba(255,255,255,.15);
  border: 1px solid rgba(255,255,255,.25);
  color: #fff;
  text-decoration: none;
  font-size: 13px;
  transition: background .2s;
}
.att-cal-nav-btn:hover { background: rgba(255,255,255,.28); color: #fff; }

/* ── Month stats strip ────────────────────────────────── */
.att-cal-stats {
  display: flex;
  gap: 0;
  margin-top: 20px;
  background: rgba(0,0,0,.18);
  border-radius: 14px;
  overflow: hidden;
}
.att-cal-stat {
  flex: 1;
  padding: 12px 14px;
  text-align: center;
  border-right: 1px solid rgba(255,255,255,.10);
}
.att-cal-stat:last-child { border-right: none; }
.att-cal-stat-num {
  font-size: 20px;
  font-weight: 800;
  color: #fff;
  line-height: 1;
}
.att-cal-stat-label {
  font-size: 10px;
  color: rgba(255,255,255,.6);
  font-weight: 600;
  letter-spacing: .8px;
  text-transform: uppercase;
  margin-top: 3px;
}

/* ── Body ─────────────────────────────────────────────── */
.att-cal-body {
  padding: 22px 22px 20px;
  background: var(--card-bg, #fff);
}

/* ── Legend ───────────────────────────────────────────── */
.att-cal-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin-bottom: 18px;
}
.att-cal-legend-item {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-mid, #6b7280);
}
.att-cal-dot {
  width: 10px; height: 10px;
  border-radius: 3px;
  flex-shrink: 0;
}
.dot-present  { background: #10b981; }
.dot-half     { background: #3b82f6; }
.dot-absent   { background: #f43f5e; }
.dot-weekend  { background: #e2e8f0; border: 1px solid #cbd5e1; }
.dot-holiday  { background: #fbbf24; }
.dot-none     { background: #f1f5f9; border: 1px solid #e2e8f0; }

/* ── Weekday header ───────────────────────────────────── */
.att-cal-weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
  margin-bottom: 8px;
}
.att-cal-weekday {
  text-align: center;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .6px;
  text-transform: uppercase;
  color: var(--text-light, #9ca3af);
  padding: 4px 0;
}
.att-cal-weekday.sun { color: #f43f5e; }
.att-cal-weekday.sat { color: #f43f5e; }

/* ── Grid ─────────────────────────────────────────────── */
.att-cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}

/* ── Day cell base ────────────────────────────────────── */
.att-day {
  position: relative;
  border-radius: 12px;
  padding: 9px 10px 8px;
  min-height: 72px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  border: 1.5px solid transparent;
  cursor: default;
  transition: transform .15s ease, box-shadow .15s ease;
  overflow: hidden;
}
.att-day:not(.att-day-empty):hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(2,6,23,.12);
}

/* ── Day number ───────────────────────────────────────── */
.att-day-num {
  font-size: 13px;
  font-weight: 800;
  line-height: 1;
  z-index: 1;
  position: relative;
}

/* ── Status tag ───────────────────────────────────────── */
.att-day-tag {
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: .3px;
  text-transform: uppercase;
  z-index: 1;
  position: relative;
}

/* ── Today ring ───────────────────────────────────────── */
.att-day.att-today {
  box-shadow: 0 0 0 2px #10b981;
}
.att-day.att-today .att-day-num::after {
  content: '•';
  font-size: 6px;
  color: #10b981;
  vertical-align: super;
  margin-left: 2px;
}

/* ── Empty ────────────────────────────────────────────── */
.att-day-empty {
  border: none;
  background: transparent;
  pointer-events: none;
  min-height: 72px;
}

/* ── Status variants ──────────────────────────────────── */
.att-day-none {
  background: #f8fafc;
  border-color: #e2e8f0;
}
.att-day-none .att-day-num { color: var(--text-dark, #1e293b); }
.att-day-none .att-day-tag { color: #cbd5e1; }

.att-day-present {
  background: linear-gradient(145deg, #ecfdf5, #d1fae5);
  border-color: rgba(16,185,129,.4);
}
.att-day-present .att-day-num { color: #064e3b; }
.att-day-present .att-day-tag { color: #059669; }
.att-day-present::after {
  content: '';
  position: absolute;
  top: 6px; right: 8px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: #10b981;
}

.att-day-half {
  background: linear-gradient(145deg, #eff6ff, #dbeafe);
  border-color: rgba(59,130,246,.4);
}
.att-day-half .att-day-num { color: #1e3a8a; }
.att-day-half .att-day-tag { color: #2563eb; }
.att-day-half::after {
  content: '';
  position: absolute;
  top: 6px; right: 8px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: #3b82f6;
}

.att-day-absent {
  background: linear-gradient(145deg, #fff1f2, #ffe4e6);
  border-color: rgba(244,63,94,.3);
}
.att-day-absent .att-day-num { color: #881337; }
.att-day-absent .att-day-tag { color: #f43f5e; }
.att-day-absent::after {
  content: '';
  position: absolute;
  top: 6px; right: 8px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: #f43f5e;
}

.att-day-weekend {
  background: #f8fafc;
  border-color: #e2e8f0;
}
.att-day-weekend .att-day-num { color: #94a3b8; }
.att-day-weekend .att-day-tag { color: #cbd5e1; }

.att-day-holiday {
  background: linear-gradient(145deg, #fffbeb, #fef3c7);
  border-color: rgba(251,191,36,.45);
}
.att-day-holiday .att-day-num { color: #78350f; }
.att-day-holiday .att-day-tag { color: #d97706; }
.att-day-holiday::after {
  content: '';
  position: absolute;
  top: 6px; right: 8px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: #fbbf24;
}

/* ── Worked hours micro-label ─────────────────────────── */
.att-day-hours {
  font-size: 9px;
  font-weight: 600;
  color: inherit;
  opacity: .55;
  margin-top: 2px;
}

/* ── Dark mode ────────────────────────────────────────── */
:root[data-theme='dark'] .att-cal-card {
  border-color: rgba(148,163,184,.22);
  box-shadow: 0 24px 48px rgba(2,6,23,.45);
}
:root[data-theme='dark'] .att-cal-body { background: #111827; }
:root[data-theme='dark'] .att-cal-legend-item { color: #94a3b8; }
:root[data-theme='dark'] .att-cal-weekday { color: #475569; }
:root[data-theme='dark'] .att-cal-weekday.sun,
:root[data-theme='dark'] .att-cal-weekday.sat { color: #f43f5e; }
:root[data-theme='dark'] .att-day-none {
  background: #1e293b; border-color: rgba(148,163,184,.18);
}
:root[data-theme='dark'] .att-day-none .att-day-num { color: #e2e8f0; }
:root[data-theme='dark'] .att-day-present {
  background: linear-gradient(145deg,rgba(16,185,129,.18),rgba(5,150,105,.22));
  border-color: rgba(16,185,129,.4);
}
:root[data-theme='dark'] .att-day-present .att-day-num { color: #6ee7b7; }
:root[data-theme='dark'] .att-day-half {
  background: linear-gradient(145deg,rgba(59,130,246,.18),rgba(37,99,235,.22));
  border-color: rgba(59,130,246,.4);
}
:root[data-theme='dark'] .att-day-half .att-day-num { color: #93c5fd; }
:root[data-theme='dark'] .att-day-absent {
  background: linear-gradient(145deg,rgba(244,63,94,.14),rgba(225,29,72,.18));
  border-color: rgba(244,63,94,.35);
}
:root[data-theme='dark'] .att-day-absent .att-day-num { color: #fda4af; }
:root[data-theme='dark'] .att-day-weekend {
  background: #1a2535; border-color: rgba(148,163,184,.15);
}
:root[data-theme='dark'] .att-day-weekend .att-day-num { color: #475569; }
:root[data-theme='dark'] .att-day-holiday {
  background: linear-gradient(145deg,rgba(251,191,36,.14),rgba(217,119,6,.18));
  border-color: rgba(251,191,36,.36);
}
:root[data-theme='dark'] .att-day-holiday .att-day-num { color: #fcd34d; }

/* ══════════════════════════════════════════════════════════
   WORK TRACKER CARD (unchanged styles kept)
══════════════════════════════════════════════════════════ */
.attendance-top-layout {
  display:block !important;
  margin-bottom:18px;
}
.attendance-top-layout .attendance-work-card { width:100%; }
.attendance-work-card {
  border:1px solid rgba(148,163,184,.28);
  border-radius:20px;
  background:
    radial-gradient(circle at 85% 15%, rgba(59,130,246,.16), transparent 40%),
    radial-gradient(circle at 16% 84%, rgba(16,185,129,.18), transparent 44%),
    linear-gradient(160deg,#ffffff 0%,#f4f9ff 100%);
  box-shadow:0 18px 38px rgba(2,6,23,.10);
}
.attendance-work-card .clock-card { padding:28px 32px 22px; }
.att-tracker-body {
  display:flex; flex-direction:column; align-items:center; text-align:center; gap:6px;
}
.attendance-work-widget-label {
  font-size:13px; font-weight:700; letter-spacing:.6px;
  text-transform:uppercase; color:var(--text-light);
}
.attendance-work-duration {
  font-size:60px; font-weight:800; letter-spacing:2px;
  color:var(--text-dark); line-height:1.05;
  margin:8px 0 12px; font-variant-numeric:tabular-nums;
}
.att-progress-row {
  display:flex; align-items:center; gap:12px;
  width:100%; max-width:500px; margin:0 auto 6px;
}
.att-progress-row .att-pct { font-size:14px; font-weight:700; color:var(--text-dark); white-space:nowrap; }
.attendance-work-progress { flex:1; height:10px; border-radius:999px; overflow:hidden; background:#e5e7eb; }
.attendance-work-progress > div { height:100%; width:0; background:linear-gradient(90deg,#10b981,#3b82f6); transition:width .35s ease; }
.attendance-status-pill {
  display:inline-flex; align-items:center; gap:6px;
  padding:5px 14px; border-radius:999px;
  font-size:13px; font-weight:700; border:1px solid transparent;
}
.attendance-status-pill.in  { color:#166534; background:rgba(34,197,94,.14);  border-color:rgba(34,197,94,.35); }
.attendance-status-pill.out { color:#1e3a8a; background:rgba(59,130,246,.14); border-color:rgba(59,130,246,.35); }
.clock-date.att-date { font-size:20px; color:var(--text-light); margin:12px 0 4px; }
.attendance-status-line { font-size:20px; text-align:center; color:var(--text-light); margin-bottom:12px; }
.attendance-status-line strong { color:var(--text-dark); }
.attendance-action-note { font-size:16px; color:var(--success); margin-top:8px; font-weight:700; text-align:center; }
.attendance-meta-row {
  display:flex; justify-content:center; gap:24px; flex-wrap:wrap;
  margin-top:16px; padding-top:16px;
  border-top:1px solid rgba(148,163,184,.18);
  font-size:14px; color:var(--text-light);
  width:100%;
}
.attendance-meta-row strong { color:var(--text-dark); font-size:15px; }

/* ── Page header ──────────────────────────────────────── */
.attendance-page-header {
  margin-bottom:14px; padding:14px 16px;
  border:1px solid var(--border); border-radius:14px;
  background:linear-gradient(180deg,rgba(255,255,255,.95),rgba(248,250,252,.95));
}
.attendance-page-header h1 { margin-bottom:2px; }
.attendance-page-sub { font-size:12px; color:var(--text-light); }

/* ── Table card ───────────────────────────────────────── */
.attendance-table-card {
  border-radius:16px;
  border:1px solid rgba(148,163,184,.24);
  box-shadow:0 14px 28px rgba(2,6,23,.06);
  overflow:hidden;
}
.attendance-table-card .table-toolbar {
  padding:14px 16px;
  border-bottom:1px solid var(--border);
  background:linear-gradient(180deg,rgba(255,255,255,.95),rgba(248,250,252,.95));
}

/* ── Dark mode for other cards ────────────────────────── */
:root[data-theme='dark'] .attendance-work-card {
  border-color: rgba(148,163,184,.24);
  background:
    radial-gradient(circle at 85% 15%, rgba(59,130,246,.20), transparent 40%),
    radial-gradient(circle at 16% 84%, rgba(16,185,129,.16), transparent 44%),
    linear-gradient(160deg, #1f2937 0%, #0f172a 100%);
  box-shadow: 0 18px 38px rgba(2,6,23,.45);
}
:root[data-theme='dark'] .attendance-page-header,
:root[data-theme='dark'] .attendance-table-card .table-toolbar {
  background: linear-gradient(180deg,rgba(30,41,59,.95),rgba(15,23,42,.95));
  border-color: rgba(148,163,184,.24);
}
:root[data-theme='dark'] .attendance-table-card {
  border-color: rgba(148,163,184,.24);
  box-shadow: 0 14px 28px rgba(2,6,23,.45);
}

/* ── Responsive ───────────────────────────────────────── */
@media (max-width: 760px) {
  .att-day { min-height: 52px; padding: 7px 7px 6px; border-radius: 9px; }
  .att-day-num { font-size: 12px; }
  .att-day-tag { font-size: 8.5px; }
  .att-day-hours { display: none; }
  .att-cal-stats { flex-wrap: wrap; }
  .att-cal-stat { min-width: 50%; }
  .att-cal-month-label { font-size: 18px; }
  .attendance-work-duration { font-size: 46px; }
  .attendance-work-card .clock-card { padding: 20px 16px 18px; }
}
</style>
CSS;

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($_SESSION['role'] === 'employee'): ?>
<?php
$activeElapsedSec     = $activeSessionStartUnix > 0 ? max(0, time() - $activeSessionStartUnix) : 0;
$todayTotalSeconds    = $todayClosedWorkedSeconds + $activeElapsedSec;
$todayWorkedPct       = $targetWorkSeconds > 0 ? min(100, (int)round($todayTotalSeconds / $targetWorkSeconds * 100)) : 0;
$activeSessionStartMs = $activeSessionStartUnix > 0 ? $activeSessionStartUnix * 1000 : 0;
?>
<div class="attendance-top-layout">
  <div class="card attendance-work-card">
    <div class="card-body clock-card">
      <div class="att-tracker-body">

        <div class="attendance-work-widget-label">Today's Working Hours</div>

        <div class="attendance-work-duration" id="att-work-duration">
          <?= sprintf('%02d:%02d:%02d',
            floor($todayTotalSeconds / 3600),
            floor(($todayTotalSeconds % 3600) / 60),
            $todayTotalSeconds % 60
          ) ?>
        </div>

        <div class="att-progress-row">
          <span class="att-pct" id="att-work-progress-text"><?= $todayWorkedPct ?>% of 8h</span>
          <div class="attendance-work-progress">
            <div id="att-work-progress-fill" style="width:<?= $todayWorkedPct ?>%"></div>
          </div>
          <span class="attendance-status-pill <?= $activeSessionStartUnix > 0 ? 'in' : 'out' ?>"
                id="att-work-status-pill">
            <i class="fas fa-circle" style="font-size:7px"></i>
            <?= $activeSessionStartUnix > 0 ? 'Checked In' : 'Checked Out' ?>
          </span>
        </div>

        <div class="clock-date att-date"><?= date('l, F d, Y') ?></div>
        <div class="attendance-status-line">Status: <strong><?= htmlspecialchars($todayStatusText) ?></strong></div>

        <div class="clock-actions">
          <?php if (!$todayRecord || $activeSessionStartUnix <= 0): ?>
          <button class="btn btn-success" onclick="doAttendance('checkin')">
            <i class="fas fa-sign-in-alt"></i>
            <?= ($todayRecord || $todaySessionCount > 0) ? 'Check In Again' : 'Check In' ?>
          </button>
          <?php endif; ?>

          <?php if ($activeSessionStartUnix > 0): ?>
          <div>
            <button class="btn btn-danger" onclick="doAttendance('checkout')">
              <i class="fas fa-sign-out-alt"></i> Check Out
            </button>
            <div class="attendance-action-note">
              <i class="fas fa-check-circle"></i> In: <?= $todayFirstIn ?>
            </div>
          </div>
          <?php elseif ($todayRecord || $todaySessionCount > 0): ?>
          <div class="attendance-action-note">
            <i class="fas fa-check-double"></i> Session Ended · <?= $todayFirstIn ?> – <?= $todayLastOut ?>
          </div>
          <?php endif; ?>
        </div>

        <div class="attendance-meta-row">
          <span>
            <i class="fas fa-arrow-right-to-bracket" style="color:var(--success)"></i>
            First In: <strong id="att-first-in"><?= $todayFirstIn ?></strong>
          </span>
          <span>
            <i class="fas fa-arrow-right-from-bracket" style="color:var(--danger)"></i>
            Last Out: <strong id="att-last-out"><?= $todayLastOut ?></strong>
          </span>
          <span>
            <i class="fas fa-layer-group" style="color:var(--primary)"></i>
            Sessions: <strong><?= $todaySessionCount ?></strong>
          </span>
        </div>

      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="page-header attendance-page-header">
  <div>
    <h1>Attendance Records</h1>
    <div class="attendance-page-sub">Live sessions, calendar view, and daily logs in one place</div>
  </div>
  <div class="actions">
    <a href="export.php?date=<?= urlencode($filterDate) ?>" class="btn btn-secondary btn-sm">
      <i class="fas fa-download"></i> Export CSV
    </a>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     PREMIUM ATTENDANCE CALENDAR
══════════════════════════════════════════════════════════ -->
<div class="att-cal-card">

  <!-- Header -->
  <div class="att-cal-header">
    <div class="att-cal-header-top">
      <div class="att-cal-month-info">
        <div class="att-cal-month-label"><?= htmlspecialchars($calendarMonthLabel) ?></div>
        <div class="att-cal-emp-name">
          <?php if ($calendarEmployeeId > 0): ?>
            <i class="fas fa-user" style="font-size:11px;margin-right:5px"></i>
            <?= htmlspecialchars($calendarEmployeeName ?: 'Selected Employee') ?>
          <?php else: ?>
            <i class="fas fa-users" style="font-size:11px;margin-right:5px"></i>
            Select an employee to view calendar
          <?php endif; ?>
        </div>
      </div>
      <div class="att-cal-nav">
        <a class="att-cal-nav-btn" href="?date=<?= urlencode($calendarPrev) ?>&emp=<?= (int)$filterEmp ?>">
          <i class="fas fa-chevron-left"></i>
        </a>
        <a class="att-cal-nav-btn" href="?date=<?= urlencode($calendarNext) ?>&emp=<?= (int)$filterEmp ?>">
          <i class="fas fa-chevron-right"></i>
        </a>
      </div>
    </div>

    <?php if ($calendarEmployeeId > 0): ?>
    <!-- Monthly stats strip -->
    <div class="att-cal-stats">
      <div class="att-cal-stat">
        <div class="att-cal-stat-num"><?= $calPresentDays ?></div>
        <div class="att-cal-stat-label">Present</div>
      </div>
      <div class="att-cal-stat">
        <div class="att-cal-stat-num"><?= $calHalfDays ?></div>
        <div class="att-cal-stat-label">Half Days</div>
      </div>
      <div class="att-cal-stat">
        <div class="att-cal-stat-num"><?= $calAbsentDays ?></div>
        <div class="att-cal-stat-label">Absent</div>
      </div>
      <div class="att-cal-stat">
        <div class="att-cal-stat-num"><?= $calTotalHours ?>h</div>
        <div class="att-cal-stat-label">Total Hours</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Body -->
  <div class="att-cal-body">

    <!-- Legend -->
    <div class="att-cal-legend">
      <div class="att-cal-legend-item"><span class="att-cal-dot dot-present"></span>Present</div>
      <div class="att-cal-legend-item"><span class="att-cal-dot dot-half"></span>Half Day</div>
      <div class="att-cal-legend-item"><span class="att-cal-dot dot-absent"></span>Absent</div>
      <div class="att-cal-legend-item"><span class="att-cal-dot dot-weekend"></span>Weekend</div>
      <div class="att-cal-legend-item"><span class="att-cal-dot dot-holiday"></span>Holiday</div>
    </div>

    <?php if ($calendarEmployeeId <= 0): ?>
      <div style="padding:32px 20px;text-align:center;border:1.5px dashed var(--border);border-radius:16px;color:var(--text-light)">
        <i class="fas fa-calendar-alt" style="font-size:32px;opacity:.3;display:block;margin-bottom:10px"></i>
        <div style="font-weight:600;font-size:14px">No employee selected</div>
        <div style="font-size:12px;margin-top:4px">Use the filter above to pick an employee and view their attendance calendar.</div>
      </div>
    <?php else: ?>

    <!-- Weekday headers -->
    <div class="att-cal-weekdays">
      <?php foreach ($calendarWeekdays as $i => $wd): ?>
        <div class="att-cal-weekday <?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $wd ?></div>
      <?php endforeach; ?>
    </div>

    <!-- Day grid -->
    <div class="att-cal-grid">
      <?php
      // Empty leading cells
      for ($i = 0; $i < $calendarFirstDow; $i++):
      ?><div class="att-day att-day-empty"></div><?php
      endfor;

      $todayFullDate = date('Y-m-d');

      for ($d = 1; $d <= $calendarDaysInMonth; $d++):
        $fullDate    = sprintf('%04d-%02d-%02d', $calendarYear, $calendarMonth, $d);
        $dow         = (int)date('w', strtotime($fullDate));
        $isToday     = ($fullDate === $todayFullDate);
        $isWeekend   = ($dow === 0 || $dow === 6);
        $isHoliday   = isset($calendarHolidays[$fullDate]);
        $workedMins  = $calendarWorkedByDate[$fullDate] ?? 0;

        $statusClass = 'att-day-none';
        $statusTag   = '';

        if (isset($calendarAttendanceByDate[$fullDate])) {
          $raw = $calendarAttendanceByDate[$fullDate];
          if ($raw === 'present')  { $statusClass = 'att-day-present'; $statusTag = 'Present'; }
          elseif ($raw === 'half_day') { $statusClass = 'att-day-half'; $statusTag = 'Half Day'; }
          elseif ($raw === 'absent')   { $statusClass = 'att-day-absent'; $statusTag = 'Absent'; }
          else { $statusClass = 'att-day-none'; $statusTag = ucfirst(str_replace('_',' ',$raw)); }
        } elseif ($isHoliday) {
          $statusClass = 'att-day-holiday';
          $statusTag   = $calendarHolidays[$fullDate];
          // Truncate long holiday names
          if (strlen($statusTag) > 9) $statusTag = substr($statusTag, 0, 8) . '…';
        } elseif ($isWeekend) {
          $statusClass = 'att-day-weekend';
          $statusTag   = $dow === 0 ? 'Sun' : 'Sat';
        }

        // Worked hours label for present days
        $hoursLabel = '';
        $hasOpenSession = !empty($calendarOpenSessionByDate[$fullDate]);
        if ($workedMins > 0 && !$hasOpenSession) {
          $wh = intdiv($workedMins, 60);
          $wm = $workedMins % 60;
          $hoursLabel = $wh > 0 ? "{$wh}h {$wm}m" : "{$wm}m";
        }

        $todayClass = $isToday ? ' att-today' : '';
      ?>
      <div class="att-day <?= $statusClass . $todayClass ?>"
           title="<?= htmlspecialchars($fullDate . ($statusTag ? ' · ' . $statusTag : '') . ($hoursLabel ? ' · ' . $hoursLabel : '')) ?>">
        <span class="att-day-num"><?= $d ?></span>
        <div>
          <?php if ($statusTag): ?>
          <div class="att-day-tag"><?= htmlspecialchars($statusTag) ?></div>
          <?php endif; ?>
          <?php if ($hoursLabel): ?>
          <div class="att-day-hours"><?= $hoursLabel ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endfor; ?>
    </div>

    <?php endif; ?>
  </div><!-- /.att-cal-body -->
</div><!-- /.att-cal-card -->

<!-- ── Records Table ───────────────────────────────────────── -->
<div class="card attendance-table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;flex:1">
      <input type="month" name="date" value="<?= $filterDate ?>"
             class="form-control" style="max-width:180px" onchange="this.form.submit()">
      <?php if (hasRole('admin','hr')): ?>
      <select name="emp" class="filter-select" onchange="this.form.submit()">
        <option value="">All Employees</option>
        <?php foreach ($allEmps as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $filterEmp == $e['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($e['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </form>
    <span style="font-size:13px;color:var(--text-light)"><?= $total ?> records</span>
  </div>

  <div class="data-table-wrap table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <?php if (hasRole('admin','hr')): ?><th>Employee</th><?php endif; ?>
          <th>Date</th>
          <th>Check In</th>
          <th>Check Out</th>
          <th>Duration</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r):
          $workedMinutes = (int)($r['worked_minutes'] ?? 0);
          if ($workedMinutes <= 0 && !empty($r['check_in']) && !empty($r['check_out'])) {
              $workedMinutes = max(1, (int)ceil((storedUtcToUnix((string)$r['check_out']) - storedUtcToUnix((string)$r['check_in'])) / 60));
          }
        ?>
        <tr>
          <?php if (hasRole('admin','hr')): ?>
          <td>
            <div class="emp-cell">
              <div class="emp-avatar"><?= strtoupper(substr($r['name'], 0, 1)) ?></div>
              <div>
                <div class="emp-name"><?= htmlspecialchars($r['name']) ?></div>
                <div class="emp-id"><?= $r['emp_id'] ?></div>
              </div>
            </div>
          </td>
          <?php endif; ?>
          <td><?= date('D, M d Y', strtotime($r['date'])) ?></td>
          <td><?= !empty($r['check_in'])  ? toIST((string)$r['check_in'])  : '—' ?></td>
          <td><?= !empty($r['check_out']) ? toIST((string)$r['check_out']) : '—' ?></td>
          <td><?= formatDuration($workedMinutes) ?></td>
          <td>
            <span class="badge badge-<?= $r['status'] === 'present' ? 'active' : ($r['status'] === 'absent' ? 'inactive' : 'leave') ?>">
              <?= ucfirst($r['status']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; if (!$records): ?>
        <tr>
          <td colspan="6" class="text-center text-muted" style="padding:40px">No records found</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <span class="page-info">
      Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
    </span>
    <div class="page-controls">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <a href="?page=<?= $p ?>&date=<?= $filterDate ?>&emp=<?= $filterEmp ?>"
         class="page-btn <?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ── Today's Session Details ─────────────────────────────── -->
<?php if ($_SESSION['role'] === 'employee' && $me): ?>
<div class="card attendance-table-card" style="margin-top:16px">
  <div class="table-toolbar">
    <strong>Today's Session Details</strong>
    <span style="font-size:13px;color:var(--text-light)"><?= count($todaySessions) ?> sessions</span>
  </div>
  <div class="data-table-wrap table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Check In <small style="font-weight:400;opacity:.6">(IST)</small></th>
          <th>Check Out <small style="font-weight:400;opacity:.6">(IST)</small></th>
          <th>Duration</th>
          <th>Source</th>
          <th>State</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $displaySessions = array_reverse($todaySessions);
          foreach ($displaySessions as $i => $s):
            $checkIn  = toIST((string)($s['check_in'] ?? ''));
            $checkOut = (!empty($s['check_out']) && $s['check_out'] !== '0000-00-00 00:00:00')
              ? toIST((string)$s['check_out'])
              : '—';

            // Duration: prefer stored minutes; fall back to computed seconds
            $mins = (int)($s['duration_minutes'] ?? 0);
            if ($mins <= 0 && $checkOut !== '—' && !empty($s['check_in'])) {
                $mins = max(1, (int)ceil(
                    (storedUtcToUnix((string)$s['check_out']) - storedUtcToUnix((string)$s['check_in'])) / 60
                ));
            }

            $isOpen = ($checkOut === '—');
        ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= $checkIn ?></td>
          <td><?= $checkOut ?></td>
          <td><?= formatDuration($mins) ?></td>
          <td><?= htmlspecialchars((string)($s['source'] ?? 'manual')) ?></td>
          <td>
            <span class="badge badge-<?= $isOpen ? 'leave' : 'active' ?>">
              <?= $isOpen ? 'Open' : 'Closed' ?>
            </span>
          </td>
        </tr>
        <?php endforeach; if (!$displaySessions): ?>
        <tr>
          <td colspan="6" class="text-center text-muted" style="padding:24px">No sessions found for today.</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
$attendanceUrl = APP_URL . '/modules/attendance/index.php';
$timerPayload  = json_encode([
    'targetSeconds'        => (int)$targetWorkSeconds,
    'closedWorkedSeconds'  => (int)$todayClosedWorkedSeconds,
    'activeSessionStartMs' => (int)($activeSessionStartUnix > 0 ? $activeSessionStartUnix * 1000 : 0),
], JSON_UNESCAPED_SLASHES);

$extraScripts = <<<JS
<script>
const ATTENDANCE_URL = '$attendanceUrl';
const ATT_TIMER      = $timerPayload;

function pad2(n) { return String(n).padStart(2, '0'); }
function toHMS(s) {
  s = Math.max(0, Math.floor(s));
  return pad2(Math.floor(s / 3600)) + ':' + pad2(Math.floor((s % 3600) / 60)) + ':' + pad2(s % 60);
}

if (document.getElementById('att-work-duration')) {
  const target  = ATT_TIMER.targetSeconds       || 28800;
  const closed  = ATT_TIMER.closedWorkedSeconds  || 0;
  const startMs = ATT_TIMER.activeSessionStartMs || 0;
  const active  = startMs > 0;

  const durationEl   = document.getElementById('att-work-duration');
  const progressFill = document.getElementById('att-work-progress-fill');
  const progressText = document.getElementById('att-work-progress-text');
  const statusPill   = document.getElementById('att-work-status-pill');

  function paintTracker() {
    const elapsed    = active ? Math.max(0, Math.floor((Date.now() - startMs) / 1000)) : 0;
    const todayTotal = closed + elapsed;
    const pct        = target > 0 ? Math.min(100, Math.round((todayTotal / target) * 100)) : 0;

    durationEl.textContent = toHMS(todayTotal);
    if (progressFill) progressFill.style.width = pct + '%';
    if (progressText) progressText.textContent  = pct + '% of 8h';
    if (statusPill) {
      statusPill.className = 'attendance-status-pill ' + (active ? 'in' : 'out');
      statusPill.innerHTML =
        '<i class="fas fa-circle" style="font-size:7px"></i> ' +
        (active ? 'Checked In' : 'Checked Out');
    }
  }

  paintTracker();
  setInterval(paintTracker, 1000);
}

async function doAttendance(action) {
  const btns = document.querySelectorAll('.clock-actions .btn');
  const notify = (msg, type) => {
    if (typeof showToast === 'function') showToast(msg, type);
    else if (msg) alert(msg);
  };
  btns.forEach(b => b.disabled = true);
  try {
    const fd = new FormData();
    fd.append('action', action);
    const r = await fetch(ATTENDANCE_URL, { method: 'POST', body: fd });
    if (!r.ok) throw new Error('Request failed');
    const d = await r.json();
    notify(d.message || 'Attendance updated.', d.success ? 'success' : 'error');
    if (d.success && action === 'checkout') {
      const lastOut = document.getElementById('att-last-out');
      if (lastOut) {
        const now = new Date();
        // Show in IST
        lastOut.textContent = now.toLocaleTimeString('en-IN', {
          hour: '2-digit', minute: '2-digit', hour12: true,
          timeZone: 'Asia/Kolkata'
        });
      }
    }
    if (d.success) setTimeout(() => window.location.reload(), 500);
  } catch (e) {
    notify('Unable to update attendance right now.', 'error');
  } finally {
    btns.forEach(b => b.disabled = false);
  }
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
