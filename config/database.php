<?php
// ============================================================
// config/database.php  –  EmpAxis Configuration
// ============================================================

date_default_timezone_set('Asia/Kolkata');

define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'employee_management');
define('DB_CHARSET', 'utf8mb4');

// App settings – change APP_URL to match your WAMP path
define('APP_NAME',    'EmpAxis');
define('APP_URL',     'http://localhost/ems2');
define('APP_VERSION', '2.0.0');

// Mail settings (configure SMTP in Settings page for real email)
define('MAIL_FROM',  'noreply@company.com');
define('MAIL_NAME',  'EmpAxis HR System');

// Session name
define('SESSION_NAME', 'empaxis_session');

// Upload directory
define('UPLOAD_DIR', __DIR__ . '/../uploads/employee_photos/');
define('UPLOAD_URL', APP_URL . '/uploads/employee_photos/');

// ── PDO Connection (singleton) ──────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        http_response_code(503);
        die('<!DOCTYPE html><html><head><title>EmpAxis – DB Error</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f4f6fc}
.box{background:#fff;border-radius:14px;padding:44px 40px;max-width:500px;width:90%;text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.12)}
h2{color:#EF4444;margin-bottom:12px;font-size:22px}.icon{font-size:52px;margin-bottom:16px}
p{color:#6B7280;font-size:14px;line-height:1.7}
.steps{background:#FEF3C7;border-radius:10px;padding:18px 22px;font-size:13px;color:#92400E;text-align:left;margin-top:20px}
.steps ol{padding-left:18px}
.steps li{margin-bottom:6px}code{background:#F3F4F6;padding:2px 6px;border-radius:4px;font-family:monospace}</style></head>
<body><div class="box">
<div class="icon">⚠️</div>
<h2>Database Connection Failed</h2>
<p>Cannot connect to MySQL. Make sure your WAMP/XAMPP server is running and the database is imported.</p>
<div class="steps"><strong>Quick Setup:</strong><ol>
<li>Start WAMP Server (green icon in taskbar)</li>
<li>Open phpMyAdmin → Create DB: <code>employee_management</code></li>
<li>Import <code>database.sql</code> then all migration files</li>
<li>Verify credentials in <code>config/database.php</code></li>
<li>Ensure folder is at <code>www/employee-management-system/</code></li>
</ol></div></div></body></html>');
    }
    return $pdo;
}

// ── Column existence check (cached) ───────────────────────
function columnExists(string $table, string $col): bool {
    static $cache = [];
    $key = "$table.$col";
    if (isset($cache[$key])) return $cache[$key];
    try {
        $s = getDB()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $s->execute([$table, $col]);
        return $cache[$key] = (bool)$s->fetchColumn();
    } catch (\Throwable $e) {
        return $cache[$key] = false;
    }
}
