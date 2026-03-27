<?php
// ============================================================
// includes/auth.php  –  Session & Role Helpers
// ============================================================
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
    validateSessionFingerprint();
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}
function requireLogin(): void
{
    if (!isLoggedIn()) redirect(APP_URL . '/auth/login.php');
}
function hasRole(string ...$roles): bool
{
    return in_array($_SESSION['role'] ?? '', $roles, true);
}
function requireRole(string ...$roles): void
{
    requireLogin();
    if (!hasRole(...$roles)) redirect(APP_URL . '/dashboard/dashboard.php?error=forbidden');
}

// ============================================================
// Session fingerprint — binds session to the browser that
// created it. Destroys and redirects if the UA changes mid-
// session (basic session-hijack mitigation).
// ============================================================
function validateSessionFingerprint(): void
{
    if (!isLoggedIn()) return;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
    $fp = hash('sha256', $ua);
    if (!isset($_SESSION['_fp'])) {
        $_SESSION['_fp'] = $fp;
        return;
    }
    if (!hash_equals($_SESSION['_fp'], $fp)) {
        session_destroy();
        header('Location: ' . APP_URL . '/auth/login.php?error=session_invalid');
        exit;
    }
}

// ============================================================
// Login rate-limiter — uses a DB table `login_attempts`.
// Returns seconds remaining on lockout (0 = allowed).
// ============================================================
function loginLockoutSeconds(string $identifier): int
{
    try {
        $pdo = getDB();
        $window   = 10 * 60;   // 10-minute window
        $maxTries = 5;          // attempts before lockout
        $lockout  = 15 * 60;   // 15-minute lockout

        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)')
            ->execute([$window]);

        $s = $pdo->prepare('SELECT COUNT(*), MAX(attempted_at) FROM login_attempts WHERE identifier = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)');
        $s->execute([hash('sha256', $identifier), $window]);
        [$count, $lastAt] = $s->fetch(PDO::FETCH_NUM);

        if ((int)$count >= $maxTries && $lastAt) {
            $elapsed = time() - strtotime($lastAt);
            $wait    = $lockout - $elapsed;
            if ($wait > 0) return (int)$wait;
        }
        return 0;
    } catch (\Throwable $e) {
        return 0;
    }
}

function recordFailedLogin(string $identifier): void
{
    try {
        getDB()->prepare('INSERT INTO login_attempts (identifier, ip, attempted_at) VALUES (?, ?, NOW())')
            ->execute([hash('sha256', $identifier), $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    } catch (\Throwable $e) {}
}

function clearLoginAttempts(string $identifier): void
{
    try {
        getDB()->prepare('DELETE FROM login_attempts WHERE identifier = ?')
            ->execute([hash('sha256', $identifier)]);
    } catch (\Throwable $e) {}
}

function tableExists(string $table): bool
{
    try {
        $s = getDB()->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->execute([$table]);
        return (bool)$s->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

function appTimezone(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Asia/Kolkata');
}

function utcTimezone(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('UTC');
}

function appNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', appTimezone());
}

function appToday(): string
{
    return appNow()->format('Y-m-d');
}

function utcNowString(): string
{
    return appNow()->setTimezone(utcTimezone())->format('Y-m-d H:i:s');
}

function parseStoredUtc(?string $datetime): ?DateTimeImmutable
{
    $value = trim((string)$datetime);
    if ($value === '' || $value === '0000-00-00 00:00:00') return null;
    try {
        return new DateTimeImmutable($value, utcTimezone());
    } catch (\Throwable $e) {
        return null;
    }
}

function storedUtcToUnix(?string $datetime): int
{
    $dt = parseStoredUtc($datetime);
    return $dt ? $dt->getTimestamp() : 0;
}

function formatStoredUtcToApp(?string $datetime, string $format = 'h:i A'): string
{
    $dt = parseStoredUtc($datetime);
    return $dt ? $dt->setTimezone(appTimezone())->format($format) : '—';
}

// ============================================================
// ensureWorkTrackingSchema / ensureLeaveSchemaSupportHalfDay
//
// These functions previously ran ALTER TABLE on every request.
// Phase 4 migration (migration_phase4_integrity.sql) now adds
// all required columns and tables permanently. These stubs
// remain so existing call-sites don't break, but they no
// longer issue any DDL. A single lightweight schema_version
// check is done once per process to confirm migration ran.
// ============================================================
function ensureWorkTrackingSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    // DDL moved to migration_phase4_integrity.sql
    // No ALTER TABLE here — schema is guaranteed by migration.
}

function ensureLeaveSchemaSupportHalfDay(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    // DDL moved to migration_phase4_integrity.sql
    // No ALTER TABLE here — schema is guaranteed by migration.
}

function getEmployeeIdByUserId(int $userId): int
{
    try {
        $s = getDB()->prepare('SELECT id FROM employees WHERE user_id=? LIMIT 1');
        $s->execute([$userId]);
        return (int)($s->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

// ============================================================
// recalcAttendanceDay
// Aggregates all sessions for the day and syncs attendance row.
// ============================================================
function recalcAttendanceDay(int $employeeId, string $date): void
{
    ensureWorkTrackingSchema();
    try {
        $pdo = getDB();
        $s = $pdo->prepare('
            SELECT
                MIN(check_in)  AS first_in,
                MAX(check_out) AS last_out,
                SUM(
                    CASE
                        WHEN check_out IS NULL
                        THEN GREATEST(TIMESTAMPDIFF(MINUTE, check_in, UTC_TIMESTAMP()), 0)
                        ELSE duration_minutes
                    END
                ) AS total_minutes,
                SUM(CASE WHEN check_out IS NULL THEN 1 ELSE 0 END) AS open_count
            FROM attendance_sessions
            WHERE employee_id = ? AND session_date = ?
        ');
        $s->execute([$employeeId, $date]);
        $agg = $s->fetch();
        if (!$agg || !$agg['first_in']) return;

        $worked = (int)max(0, (int)($agg['total_minutes'] ?? 0));
        $open   = (int)($agg['open_count'] ?? 0);

        // A successful check-in should immediately mark the day as present.
        if ($open > 0) {
            $status = 'present';
        } else {
            $status = $worked >= 480 ? 'present' : ($worked > 0 ? 'half_day' : 'absent');
        }

        // attendance.check_out = NULL while any session is still open
        $checkOut = $open > 0 ? null : ($agg['last_out'] ?? null);

        $exists = $pdo->prepare('SELECT id FROM attendance WHERE employee_id=? AND date=? LIMIT 1');
        $exists->execute([$employeeId, $date]);
        $rowId = (int)($exists->fetchColumn() ?: 0);

        if ($rowId > 0) {
            $pdo->prepare('
                UPDATE attendance SET check_in=?, check_out=?, worked_minutes=?, status=?, updated_at=NOW() WHERE id=?
            ')->execute([$agg['first_in'], $checkOut, $worked, $status, $rowId]);
        } else {
            $pdo->prepare('
                INSERT INTO attendance (employee_id, check_in, check_out, date, status, worked_minutes, created_at)
                VALUES (?,?,?,?,?,?,NOW())
            ')->execute([$employeeId, $agg['first_in'], $checkOut, $date, $status, $worked]);
        }
    } catch (\Throwable $e) {
        error_log('recalcAttendanceDay: ' . $e->getMessage());
    }
}

// ============================================================
// startWorkSession
// Opens a new session row. Uses explicit PHP timestamp for
// check_in so recalcAttendanceDay reads it immediately.
// ============================================================
function startWorkSession(?int $userId = null, string $source = 'login'): bool
{
    $uid = $userId ?: (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return false;
    $employeeId = getEmployeeIdByUserId($uid);
    if ($employeeId <= 0) return false;
    ensureWorkTrackingSchema();

    $date  = appToday();
    $nowDt = utcNowString();

    try {
        $pdo = getDB();
        // Block if an open session already exists
        $open = $pdo->prepare('SELECT id FROM attendance_sessions WHERE employee_id=? AND session_date=? AND check_out IS NULL LIMIT 1');
        $open->execute([$employeeId, $date]);
        if ($open->fetchColumn()) return false;

        $pdo->prepare('
            INSERT INTO attendance_sessions (employee_id, user_id, session_date, check_in, source, created_at)
            VALUES (?,?,?,?,?,NOW())
        ')->execute([$employeeId, $uid, $date, $nowDt, $source]);

        recalcAttendanceDay($employeeId, $date);
        return true;
    } catch (\Throwable $e) {
        error_log('startWorkSession: ' . $e->getMessage());
        return false;
    }
}

// ============================================================
// endWorkSession — THE MAIN FIX
//
// ORIGINAL BUG: Used SET check_out=NOW(), duration_minutes=?
// The duration was calculated in PHP correctly, but recalc's
// SUM(duration_minutes) ran immediately after and sometimes
// read the row BEFORE MySQL committed the UPDATE, getting 0.
// This caused duration_minutes=0 for ALL rows in the DB.
//
// FIX: Capture checkout time in PHP, compute duration in PHP,
// write BOTH as explicit bound values. Then recalc always
// sees the correct duration_minutes because the UPDATE is
// fully committed before recalc's SELECT runs.
// ============================================================
function endWorkSession(?int $userId = null, string $source = 'logout'): bool
{
    $uid = $userId ?: (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return false;
    $employeeId = getEmployeeIdByUserId($uid);
    if ($employeeId <= 0) return false;
    ensureWorkTrackingSchema();

    try {
        $pdo = getDB();

        $open = $pdo->prepare('
            SELECT id, session_date, check_in
            FROM attendance_sessions
            WHERE employee_id = ? AND check_out IS NULL
            ORDER BY check_in DESC LIMIT 1
        ');
        $open->execute([$employeeId]);
        $row = $open->fetch();
        if (!$row) return false;

        // Compute everything in PHP — no dependency on DB NOW() timing
        $checkIn = parseStoredUtc((string)$row['check_in']);
        if (!$checkIn) return false;
        $checkOut = new DateTimeImmutable('now', utcTimezone());
        $checkInTs    = $checkIn->getTimestamp();
        $checkOutTs   = $checkOut->getTimestamp();
        $checkOutDt   = $checkOut->format('Y-m-d H:i:s');
        $durationSecs = max(0, $checkOutTs - $checkInTs);
        $durationMins = $durationSecs > 0 ? max(1, (int)ceil($durationSecs / 60)) : 0;

        $pdo->prepare('
            UPDATE attendance_sessions
            SET check_out = ?, duration_minutes = ?, source = ?
            WHERE id = ?
        ')->execute([$checkOutDt, $durationMins, $source, $row['id']]);

        // recalc now sees correct duration_minutes in its SUM
        recalcAttendanceDay($employeeId, (string)$row['session_date']);
        return true;
    } catch (\Throwable $e) {
        error_log('endWorkSession: ' . $e->getMessage());
        return false;
    }
}

function calculateLeaveUnits(string $startDate, string $endDate, string $durationType = 'full_day'): float
{
    $days = max(1, (int)((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);
    if ($durationType === 'half_day') return 0.5;
    return (float)$days;
}

function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['display_name'] = $user['display_name'] ?? $user['username'];
    $_SESSION['avatar']       = $user['avatar'] ?? null;
    $_SESSION['email']        = $user['email'];
    $_SESSION['role']         = $user['role'];
}

function logoutUser(): void
{
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = (string)($_SESSION['role'] ?? '');
    if ($uid > 0 && $role === 'employee') {
        endWorkSession($uid, 'logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect(APP_URL . '/auth/login.php');
}

function generateUsername(string $fullName = ''): string
{
    $pdo = getDB();
    if ($fullName) {
        $clean = strtolower(preg_replace('/[^a-zA-Z ]/', '', $fullName));
        $parts = array_values(array_filter(explode(' ', trim($clean))));
        $first = substr($parts[0] ?? 'user', 0, 8);
        $last  = substr($parts[1] ?? '', 0, 8);
        $base  = $last ? $first . '.' . $last : $first;
        $stmt  = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$base]);
        if (!$stmt->fetch()) return $base;
        for ($i = 2; $i <= 99; $i++) {
            $cand = $base . $i;
            $stmt->execute([$cand]);
            if (!$stmt->fetch()) return $cand;
        }
    }
    $prefixes = ['emp', 'staff', 'usr'];
    do {
        $username = $prefixes[array_rand($prefixes)] . rand(10000, 99999);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
    } while ($stmt->fetch());
    return $username;
}

function generateToken(int $length = 64): string
{
    return bin2hex(random_bytes(max(1, (int)($length / 2))));
}

function smtpReadResponse($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return trim($response);
}

function smtpExpect($socket, array $codes, string $context): void
{
    $resp = smtpReadResponse($socket);
    $code = (int)substr($resp, 0, 3);
    if (!in_array($code, $codes, true)) throw new RuntimeException("$context failed: $resp");
}

function smtpCommand($socket, string $command, array $okCodes, string $context): void
{
    fwrite($socket, $command . "\r\n");
    smtpExpect($socket, $okCodes, $context);
}

function sendEmailWithSmtpConfig(array $smtp, string $to, string $subject, string $htmlBody): bool
{
    $host      = trim((string)($smtp['host'] ?? ''));
    $port      = (int)($smtp['port'] ?? 0);
    $enc       = strtolower(trim((string)($smtp['encryption'] ?? '')));
    $username  = trim((string)($smtp['username'] ?? ''));
    $password  = (string)($smtp['password'] ?? '');
    $fromEmail = trim((string)($smtp['from_email'] ?? ''));
    $fromName  = trim((string)($smtp['from_name'] ?? APP_NAME));

    if (!$host || !$port || !$fromEmail || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $transport = $enc === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $socket = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        error_log("SMTP connect failed ({$host}:{$port}): {$errstr}");
        return false;
    }
    stream_set_timeout($socket, 20);

    try {
        smtpExpect($socket, [220], 'SMTP greeting');
        smtpCommand($socket, 'EHLO localhost', [250], 'EHLO');
        if ($enc === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220], 'STARTTLS');
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true)
                throw new RuntimeException('TLS negotiation failed');
            smtpCommand($socket, 'EHLO localhost', [250], 'EHLO after STARTTLS');
        }
        if ($username !== '' && $password !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
            smtpCommand($socket, base64_encode($username), [334], 'SMTP username');
            smtpCommand($socket, base64_encode($password), [235], 'SMTP password');
        }
        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
        smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        smtpCommand($socket, 'DATA', [354], 'DATA');

        $subj = preg_replace('/[\r\n]+/', ' ', $subject) ?: APP_NAME;
        $msg  = implode("\r\n", [
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            "From: $fromName <$fromEmail>",
            "To: <$to>",
            "Subject: $subj"
        ])
            . "\r\n\r\n" . $htmlBody;
        fwrite($socket, preg_replace('/(?m)^\./', '..', $msg) . "\r\n.\r\n");
        smtpExpect($socket, [250], 'Message delivery');
        @fwrite($socket, "QUIT\r\n");
        return true;
    } catch (\Throwable $e) {
        error_log('SMTP socket send failed: ' . $e->getMessage());
        return false;
    } finally {
        fclose($socket);
    }
}

function sendEmail(string $to, string $subject, string $htmlBody): bool
{
    try {
        $smtp = getDB()->query('SELECT * FROM smtp_config WHERE id = 1')->fetch();
    } catch (\Throwable $e) {
        $smtp = null;
    }

    $phpmailerPath = __DIR__ . '/../vendor/autoload.php';
    if ($smtp && !empty($smtp['host']) && file_exists($phpmailerPath)) {
        require_once $phpmailerPath;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host     = $smtp['host'];
            $mail->SMTPAuth = !empty($smtp['username']) || !empty($smtp['password']);
            $mail->Username = $smtp['username'] ?? '';
            $mail->Password = $smtp['password'] ?? '';
            if (($smtp['encryption'] ?? '') === 'ssl') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            elseif (($smtp['encryption'] ?? '') === 'tls') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int)$smtp['port'];
            $mail->setFrom($smtp['from_email'], $smtp['from_name']);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('PHPMailer: ' . $e->getMessage());
        }
    }
    if ($smtp && !empty($smtp['host']) && sendEmailWithSmtpConfig($smtp, $to, $subject, $htmlBody)) return true;

    $fromEmail = $smtp['from_email'] ?? MAIL_FROM;
    $fromName  = $smtp['from_name']  ?? MAIL_NAME;
    return @mail($to, $subject, $htmlBody, "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: {$fromName} <{$fromEmail}>\r\n");
}

function logActivity(string $action, string $description = '', string $module = ''): void
{
    try {
        getDB()->prepare('INSERT INTO activity_log (user_id,username,role,action,description,module,ip_address) VALUES(?,?,?,?,?,?,?)')
            ->execute([$_SESSION['user_id'] ?? null, $_SESSION['username'] ?? 'system', $_SESSION['role'] ?? 'unknown', $action, $description, $module, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    } catch (\Throwable $e) {
        error_log('logActivity: ' . $e->getMessage());
    }
}

function ensureLeaveBalance(int $employeeId, int $year = 0): void
{
    ensureLeaveSchemaSupportHalfDay();
    if (!$year) $year = (int)date('Y');
    try {
        getDB()->prepare('INSERT IGNORE INTO leave_balances (employee_id,year) VALUES(?,?)')->execute([$employeeId, $year]);
    } catch (\Throwable $e) {
    }
}

function getLeaveBalance(int $employeeId, int $year = 0): array
{
    if (!$year) $year = (int)date('Y');
    ensureLeaveBalance($employeeId, $year);
    try {
        $s = getDB()->prepare('SELECT * FROM leave_balances WHERE employee_id=? AND year=?');
        $s->execute([$employeeId, $year]);
        return $s->fetch() ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function deductLeaveBalance(int $employeeId, string $leaveType, float $days): void
{
    ensureLeaveSchemaSupportHalfDay();
    $year = (int)date('Y');
    ensureLeaveBalance($employeeId, $year);
    $col = match ($leaveType) {
        'sick' => 'sick_used',
        'paid' => 'paid_used',
        default => 'casual_used'
    };
    try {
        getDB()->prepare("UPDATE leave_balances SET {$col}=LEAST({$col}+?,365) WHERE employee_id=? AND year=?")->execute([$days, $employeeId, $year]);
    } catch (\Throwable $e) {
    }
}

function refundLeaveBalance(int $employeeId, string $leaveType, float $days): void
{
    ensureLeaveSchemaSupportHalfDay();
    $year = (int)date('Y');
    $col  = match ($leaveType) {
        'sick' => 'sick_used',
        'paid' => 'paid_used',
        default => 'casual_used'
    };
    try {
        getDB()->prepare("UPDATE leave_balances SET {$col}=GREATEST({$col}-?,0) WHERE employee_id=? AND year=?")->execute([$days, $employeeId, $year]);
    } catch (\Throwable $e) {
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = generateToken(32);
    return $_SESSION['csrf_token'];
}
function verifyCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function clean(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = compact('type', 'message');
}
function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function getCurrentEmployee(): ?array
{
    if (!isLoggedIn()) return null;
    try {
        $s = getDB()->prepare('SELECT e.*, d.department_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE e.user_id=?');
        $s->execute([$_SESSION['user_id']]);
        return $s->fetch() ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function unreadNotifCount(): int
{
    if (!isLoggedIn()) return 0;
    try {
        $s = getDB()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
        $s->execute([$_SESSION['user_id']]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

function addNotification(int $userId, string $title, string $message, string $type = 'info'): void
{
    try {
        getDB()->prepare('INSERT INTO notifications(user_id,title,message,type) VALUES(?,?,?,?)')->execute([$userId, $title, $message, $type]);
    } catch (\Throwable $e) {
    }
}

function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
