<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin','hr');

$pageTitle   = 'Settings';
$currentPage = 'settings';
$pdo         = getDB();

function detectErrorLogPath(): ?string {
    $candidates = [
        ini_get('error_log') ?: '',
        'C:/wamp64/logs/php_error.log',
        'C:/wamp64/logs/apache_error.log',
    ];
    foreach ($candidates as $path) {
        $path = trim((string)$path);
        if ($path !== '' && is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function findLastSmtpErrorLine(?string $logPath): ?string {
    if (!$logPath || !is_readable($logPath)) return null;
    $fp = @fopen($logPath, 'rb');
    if (!$fp) return null;

    fseek($fp, 0, SEEK_END);
    $size = ftell($fp);
    if ($size <= 0) {
        fclose($fp);
        return null;
    }

    $lines = [];
    $buffer = '';
    for ($pos = -1; ($size + $pos) >= 0 && count($lines) < 600; $pos--) {
        fseek($fp, $pos, SEEK_END);
        $char = fgetc($fp);
        if ($char === "\n") {
            $lines[] = strrev($buffer);
            $buffer = '';
            continue;
        }
        if ($char !== "\r") $buffer .= $char;
    }
    if ($buffer !== '') $lines[] = strrev($buffer);
    fclose($fp);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/\bSMTP\b|PHPMailer|sendEmailWithSmtpConfig/i', $line)) {
            return $line;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id=?');
        $stmt->execute([$_SESSION['user_id']]); $u = $stmt->fetch();
        if (!password_verify($current, $u['password'])) {
            setFlash('danger', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('danger', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            setFlash('danger', 'Passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);
            setFlash('success', 'Password changed successfully.');
        }
        redirect(APP_URL . '/modules/settings/index.php');
    }

    if ($action === 'test_smtp') {
        if (!hasRole('admin')) {
            setFlash('error', 'Only administrators can test SMTP settings.');
            redirect(APP_URL . '/modules/settings/index.php');
        }
        header('Content-Type: application/json; charset=utf-8');
        $host    = trim($_POST['host']       ?? '');
        $port    = (int)($_POST['port']      ?? 587);
        $enc     = trim($_POST['encryption'] ?? 'tls');
        $user    = trim($_POST['username']   ?? '');
        $pass    = $_POST['password']        ?? '';
        $from    = trim($_POST['from_email'] ?? $user);
        $fromN   = trim($_POST['from_name']  ?? 'EmpAxis');
        $testTo  = trim($_POST['test_email'] ?? '');
        if (!$testTo || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success'=>false,'message'=>'Please enter a valid test email address.']);
            exit;
        }
        if (!$host || !$from || !$port) {
            echo json_encode(['success'=>false,'message'=>'Please fill in Host, Port and From Email first.']);
            exit;
        }

        $smtp = [
            'host'       => $host,
            'port'       => $port,
            'encryption' => $enc,
            'username'   => $user,
            'password'   => $pass,
            'from_email' => $from,
            'from_name'  => $fromN,
        ];

        $ok = sendEmailWithSmtpConfig(
            $smtp,
            $testTo,
            'EmpAxis SMTP Test',
            '<p>This is a test email from EmpAxis. SMTP is configured correctly.</p>'
        );

        if ($ok) {
            echo json_encode(['success'=>true,'message'=>"Test email sent successfully to $testTo."]);
        } else {
            echo json_encode(['success'=>false,'message'=>'SMTP test failed. Check host/port/encryption/credentials and try again.']);
        }
        exit;
    }

    if ($action === 'save_smtp') {
        if (!hasRole('admin')) {
            setFlash('error', 'Only administrators can change SMTP settings.');
            redirect(APP_URL . '/modules/settings/index.php');
        }
        $fields = ['host','port','encryption','username','password','from_email','from_name'];
        $vals   = [];
        $sets   = [];
        foreach ($fields as $f) {
            $vals[] = trim($_POST[$f] ?? '');
            $sets[] = "$f = ?";
        }
        $pdo->prepare('UPDATE smtp_config SET '.implode(',',$sets).' WHERE id=1')->execute($vals);
        logActivity('Updated SMTP settings', '', 'settings');
        setFlash('success', 'SMTP settings saved.');
        redirect(APP_URL . '/modules/settings/index.php');
    }

    if ($action === 'add_announcement' && hasRole('admin','hr')) {
        $title = trim($_POST['title'] ?? '');
        $msg   = trim($_POST['message'] ?? '');
        // Send to all users
        $users = $pdo->query('SELECT id FROM users')->fetchAll();
        $stmt  = $pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES(?,?,?,?)');
        foreach ($users as $u) {
            $stmt->execute([$u['id'], $title, $msg, 'info']);
        }
        setFlash('success', 'Announcement sent to all users.');
        redirect(APP_URL . '/modules/settings/index.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';

// Fetch SMTP config
$smtp = [];
try { $smtp = $pdo->query('SELECT * FROM smtp_config WHERE id=1')->fetch() ?: []; } catch(\Throwable $e) {}

$smtpDebugLogPath   = detectErrorLogPath();
$smtpDebugLastError = findLastSmtpErrorLine($smtpDebugLogPath);
$smtpDebugLogMTime  = ($smtpDebugLogPath && is_file($smtpDebugLogPath)) ? @filemtime($smtpDebugLogPath) : false;
?>

<div class="page-header">
  <div><h1>Settings</h1><p>Manage system configuration</p></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
  <!-- Change Password -->
  <div class="card">
    <div class="card-header"><h2><i class="fas fa-key" style="color:var(--primary);margin-right:8px"></i>Change Password</h2></div>
    <div class="card-body">
      <form method="POST" data-validate>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
          <label>Current Password</label>
          <div class="input-with-icon">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" name="current_password" class="form-control" placeholder="Current password" required>
          </div>
        </div>
        <div class="form-group mt-16">
          <label>New Password</label>
          <div class="input-with-icon">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" name="new_password" class="form-control" placeholder="Min 8 characters" required>
          </div>
        </div>
        <div class="form-group mt-16">
          <label>Confirm New Password</label>
          <div class="input-with-icon">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat new password" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary mt-24">
          <i class="fas fa-save"></i> Update Password
        </button>
      </form>
    </div>
  </div>

  <!-- Send Announcement -->
  <div class="card">
    <div class="card-header"><h2><i class="fas fa-bullhorn" style="color:var(--warning);margin-right:8px"></i>Send Announcement</h2></div>
    <div class="card-body">
      <form method="POST" data-validate>
        <input type="hidden" name="action" value="add_announcement">
        <div class="form-group">
          <label>Title *</label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Company Holiday" required>
        </div>
        <div class="form-group mt-16">
          <label>Message *</label>
          <textarea name="message" class="form-control" placeholder="Your announcement…" rows="5" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary mt-24">
          <i class="fas fa-paper-plane"></i> Send to All
        </button>
      </form>
    </div>
  </div>
</div>

<!-- SMTP Configuration -->
<div class="card mt-24">
  <div class="card-header">
    <h2><i class="fas fa-envelope" style="color:var(--info);margin-right:8px"></i>SMTP Email Configuration</h2>
    <button class="btn btn-outline btn-sm" onclick="openModal('smtpGuideModal')"><i class="fas fa-book"></i> Setup Guide</button>
  </div>
  <div class="card-body">

    <!-- Provider quick-select -->
    <div style="margin-bottom:20px">
      <div style="font-size:12px;font-weight:700;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Quick Provider Fill</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn btn-outline btn-sm" onclick="fillProvider('gmail')"><img src="https://www.google.com/favicon.ico" style="width:14px;height:14px;margin-right:5px">Gmail</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="fillProvider('outlook')"><i class="fas fa-envelope" style="color:#0078D4;margin-right:5px"></i>Outlook</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="fillProvider('yahoo')"><i class="fas fa-envelope" style="color:#720E9E;margin-right:5px"></i>Yahoo</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="fillProvider('ses')"><i class="fas fa-aws" style="color:#FF9900;margin-right:5px"></i>AWS SES</button>
        <button type="button" class="btn btn-outline btn-sm" onclick="fillProvider('custom')"><i class="fas fa-server" style="margin-right:5px"></i>Custom</button>
      </div>
    </div>

    <form method="POST" data-validate id="smtpForm">
      <input type="hidden" name="action" value="save_smtp">
      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label>SMTP Host *</label>
          <input type="text" name="host" id="smtpHost" class="form-control" placeholder="smtp.gmail.com" value="<?= htmlspecialchars($smtp['host']??'smtp.gmail.com') ?>">
        </div>
        <div class="form-group">
          <label>Port *</label>
          <input type="number" name="port" id="smtpPort" class="form-control" placeholder="587" value="<?= htmlspecialchars($smtp['port']??'587') ?>">
          <div style="font-size:11px;color:var(--text-light);margin-top:4px">TLS→587 &nbsp;|&nbsp; SSL→465 &nbsp;|&nbsp; No encryption→25</div>
        </div>
        <div class="form-group">
          <label>Encryption</label>
          <select name="encryption" id="smtpEnc" class="form-control">
            <option value="tls" <?= ($smtp['encryption']??'tls')==='tls'?'selected':'' ?>>TLS (recommended)</option>
            <option value="ssl" <?= ($smtp['encryption']??'')==='ssl'?'selected':'' ?>>SSL</option>
            <option value=""   <?= ($smtp['encryption']??'')==='ssl'||($smtp['encryption']??'')==='tls'?'':'selected' ?>>None</option>
          </select>
        </div>
        <div class="form-group">
          <label>SMTP Username *</label>
          <input type="text" name="username" id="smtpUser" class="form-control" placeholder="your@gmail.com" value="<?= htmlspecialchars($smtp['username']??'') ?>">
        </div>
        <div class="form-group">
          <label>SMTP Password / App Password *</label>
          <div style="position:relative">
            <input type="password" name="password" id="smtpPass" class="form-control" placeholder="App password or SMTP password" value="<?= htmlspecialchars($smtp['password']??'') ?>" style="padding-right:40px">
            <button type="button" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-light)" onclick="togglePass()"><i class="fas fa-eye" id="passEyeIcon"></i></button>
          </div>
        </div>
        <div class="form-group">
          <label>From Email *</label>
          <input type="email" name="from_email" id="smtpFrom" class="form-control" placeholder="noreply@yourcompany.com" value="<?= htmlspecialchars($smtp['from_email']??'') ?>">
        </div>
        <div class="form-group">
          <label>From Name</label>
          <input type="text" name="from_name" class="form-control" placeholder="EmpAxis HR" value="<?= htmlspecialchars($smtp['from_name']??'EmpAxis HR') ?>">
        </div>
        <div class="form-group">
          <label>Test Email (optional)</label>
          <div style="display:flex;gap:8px">
            <input type="email" id="testEmailAddr" class="form-control" placeholder="send test to…">
            <button type="button" class="btn btn-outline btn-sm" onclick="sendTest()" style="white-space:nowrap"><i class="fas fa-paper-plane"></i> Send Test</button>
          </div>
        </div>
      </div>

      <div style="margin-top:16px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:14px 16px;font-size:13px;color:#92400E">
        <strong><i class="fas fa-exclamation-triangle"></i> Requirements:</strong>
        Use valid SMTP details from your provider.
        &nbsp;·&nbsp; For Gmail use an <strong>App Password</strong> (not your regular password).
        &nbsp;·&nbsp; PHPMailer is optional; EmpAxis can send directly via SMTP.
        <a href="#" onclick="openModal('smtpGuideModal');return false" style="color:var(--primary);font-weight:600">See step-by-step guide →</a>
      </div>

      <div id="smtpStatusBadge" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px"></div>

      <div style="margin-top:16px;display:flex;gap:10px">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save SMTP Settings</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ SMTP Setup Guide Modal ═══ -->
<div id="smtpGuideModal" class="modal-overlay">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div class="modal-icon blue" style="background:#EFF6FF"><i class="fas fa-book" style="color:#3B82F6"></i></div>
      <div class="modal-title-wrap"><h3 class="modal-title">SMTP Setup Guide</h3><div class="modal-subtitle">Step-by-step for Gmail, Outlook, and custom SMTP</div></div>
      <button class="modal-close-btn" onclick="closeModal('smtpGuideModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="max-height:500px;overflow-y:auto">

      <!-- Tab selector -->
      <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
        <?php foreach([['gmail','fab fa-google','Gmail','#EA4335'],['outlook','fas fa-envelope','Outlook/Office 365','#0078D4'],['ses','fas fa-cloud','AWS SES','#FF9900'],['custom','fas fa-server','Custom Server','']] as [$k,$ic,$lb,$col]): ?>
        <button class="btn btn-outline btn-sm guide-tab" id="tab_<?= $k ?>" onclick="showGuide('<?= $k ?>')"
          style="<?= $k==='gmail'?'border-color:var(--primary);color:var(--primary)':'' ?>">
          <i class="<?= $ic ?>" style="<?= $col?"color:$col":'' ?>"></i> <?= $lb ?>
        </button>
        <?php endforeach; ?>
      </div>

      <!-- Gmail Guide -->
      <div id="guide_gmail">
        <div style="background:#FEF2F2;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#991B1B">
          <i class="fas fa-exclamation-triangle"></i> Gmail requires an <strong>App Password</strong> — your regular Gmail password will NOT work.
        </div>
        <?php
        $gmailSteps = [
          ['fas fa-shield-alt','Enable 2-Step Verification','Go to <a href="https://myaccount.google.com/security" target="_blank" style="color:var(--primary)">Google Account → Security</a>. Under "How you sign in to Google", enable <strong>2-Step Verification</strong>.'],
          ['fas fa-key','Generate App Password','After enabling 2FA, go back to Security → scroll down to <strong>App passwords</strong> (search for it if not visible). Select App: <em>Mail</em> and Device: <em>Other (custom name)</em> → type "EmpAxis" → click Generate.'],
          ['fas fa-copy','Copy the 16-character password','Google will show a 16-character password like <code style="background:#FEF2F2;padding:2px 5px;border-radius:4px">abcd efgh ijkl mnop</code>. Copy it (without spaces). This is your SMTP password.'],
          ['fas fa-cog','Fill in EmpAxis settings','<strong>Host:</strong> smtp.gmail.com &nbsp;|&nbsp; <strong>Port:</strong> 587 &nbsp;|&nbsp; <strong>Encryption:</strong> TLS &nbsp;|&nbsp; <strong>Username:</strong> your Gmail address &nbsp;|&nbsp; <strong>Password:</strong> the 16-char app password'],
          ['fas fa-flask','Test it','Enter a test email address in the Test Email field and click Send Test.'],
        ];
        foreach ($gmailSteps as $i => [$icon,$title,$desc]):
        ?>
        <div style="display:flex;gap:14px;margin-bottom:14px">
          <div style="width:32px;height:32px;background:var(--primary);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0"><?= $i+1 ?></div>
          <div>
            <div style="font-weight:700;font-size:13.5px;color:var(--text-dark);margin-bottom:4px"><i class="<?= $icon ?>" style="color:var(--primary);margin-right:6px"></i><?= $title ?></div>
            <div style="font-size:13px;color:var(--text-mid);line-height:1.7"><?= $desc ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="background:var(--secondary);border-radius:8px;padding:12px 14px;font-size:12.5px;font-family:monospace;margin-top:12px">
          Host: smtp.gmail.com &nbsp;|&nbsp; Port: 587 &nbsp;|&nbsp; Encryption: TLS<br>
          Username: yourname@gmail.com &nbsp;|&nbsp; Password: [16-char App Password]
        </div>
      </div>

      <!-- Outlook Guide -->
      <div id="guide_outlook" style="display:none">
        <?php
        $outSteps = [
          ['fas fa-sign-in-alt','Login to Microsoft Account','Go to <a href="https://account.microsoft.com" target="_blank" style="color:var(--primary)">account.microsoft.com</a> and sign in.'],
          ['fas fa-shield-alt','Enable 2FA (if not done)','Security → Advanced security options → enable Two-step verification.'],
          ['fas fa-key','Create App Password','Go to Security → Advanced security options → App passwords → Create a new app password → Name it "EmpAxis".'],
          ['fas fa-cog','Fill in EmpAxis settings','<strong>Host:</strong> smtp-mail.outlook.com &nbsp;|&nbsp; <strong>Port:</strong> 587 &nbsp;|&nbsp; <strong>Encryption:</strong> TLS &nbsp;|&nbsp; <strong>Username:</strong> your Outlook email &nbsp;|&nbsp; <strong>Password:</strong> app password'],
        ];
        foreach ($outSteps as $i => [$icon,$title,$desc]):
        ?>
        <div style="display:flex;gap:14px;margin-bottom:14px">
          <div style="width:32px;height:32px;background:#0078D4;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0"><?= $i+1 ?></div>
          <div><div style="font-weight:700;font-size:13.5px;margin-bottom:4px"><?= $title ?></div><div style="font-size:13px;color:var(--text-mid);line-height:1.7"><?= $desc ?></div></div>
        </div>
        <?php endforeach; ?>
        <div style="background:var(--secondary);border-radius:8px;padding:12px 14px;font-size:12.5px;font-family:monospace;margin-top:12px">
          Host: smtp-mail.outlook.com &nbsp;|&nbsp; Port: 587 &nbsp;|&nbsp; Encryption: TLS<br>
          Username: yourname@outlook.com &nbsp;|&nbsp; Password: [App Password]
        </div>
      </div>

      <!-- AWS SES -->
      <div id="guide_ses" style="display:none">
        <?php
        $sesSteps = [
          ['fas fa-user-shield','Create IAM user','AWS Console → IAM → Users → Add user → Programmatic access. Attach policy: AmazonSESFullAccess.'],
          ['fas fa-envelope','Verify sender email','SES Console → Verified Identities → Create Identity → Email address → Enter your from-email → Verify via email link.'],
          ['fas fa-key','Get SMTP credentials','SES Console → SMTP Settings → Create SMTP credentials → Download the .csv file (these are different from IAM credentials!).'],
          ['fas fa-globe','Check region','Note your SES region. The SMTP endpoint is region-specific, e.g. email-smtp.us-east-1.amazonaws.com.'],
          ['fas fa-cog','Fill in EmpAxis','<strong>Host:</strong> email-smtp.{region}.amazonaws.com &nbsp;|&nbsp; <strong>Port:</strong> 587 &nbsp;|&nbsp; <strong>Encryption:</strong> TLS &nbsp;|&nbsp; <strong>Username:</strong> SMTP Username from .csv &nbsp;|&nbsp; <strong>Password:</strong> SMTP Password from .csv'],
        ];
        foreach ($sesSteps as $i => [$icon,$title,$desc]):
        ?>
        <div style="display:flex;gap:14px;margin-bottom:14px">
          <div style="width:32px;height:32px;background:#FF9900;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0"><?= $i+1 ?></div>
          <div><div style="font-weight:700;font-size:13.5px;margin-bottom:4px"><?= $title ?></div><div style="font-size:13px;color:var(--text-mid);line-height:1.7"><?= $desc ?></div></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Custom -->
      <div id="guide_custom" style="display:none">
        <p style="font-size:13.5px;color:var(--text-mid);line-height:1.8;margin-bottom:16px">
          For a custom mail server (cPanel, Plesk, Zoho, etc.), get these values from your email provider or hosting control panel:
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <?php foreach([
            ['SMTP Host','From hosting panel, e.g. mail.yourdomain.com'],
            ['Port','587 for TLS, 465 for SSL, 25 for plain (avoid 25)'],
            ['Username','Usually your full email address'],
            ['Password','Your email account password'],
            ['From Email','Must match the authenticated account or a verified alias'],
            ['Encryption','TLS preferred, SSL if TLS fails'],
          ] as [$t,$d]): ?>
          <div style="background:var(--secondary);border-radius:8px;padding:12px">
            <div style="font-weight:700;font-size:13px;margin-bottom:3px"><?= $t ?></div>
            <div style="font-size:12px;color:var(--text-light)"><?= $d ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:8px;padding:12px 14px;margin-top:14px;font-size:13px;color:#065F46">
          <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> If you use cPanel, go to Email Accounts → your account → Connect Devices — all SMTP details are listed there.
        </div>
      </div>

      <!-- Optional PHPMailer install -->
      <div style="background:#F8F9FA;border-radius:10px;padding:16px 18px;margin-top:20px;border:1px solid var(--border)">
        <div style="font-weight:700;font-size:13.5px;margin-bottom:10px"><i class="fas fa-terminal" style="color:var(--primary)"></i> Install PHPMailer (optional)</div>
        <div style="font-size:12.5px;color:var(--text-mid);margin-bottom:8px">EmpAxis works without this, but you can install PHPMailer if you prefer:</div>
        <code style="display:block;background:#1E293B;color:#7DD3FC;padding:10px 14px;border-radius:8px;font-size:13px">composer require phpmailer/phpmailer</code>
        <div style="font-size:12px;color:var(--text-light);margin-top:8px">If Composer is not installed: <a href="https://getcomposer.org/download/" target="_blank" style="color:var(--primary)">getcomposer.org/download</a></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('smtpGuideModal')">Close</button>
      <button class="btn btn-primary" onclick="closeModal('smtpGuideModal')"><i class="fas fa-check"></i> Got it, configure now</button>
    </div>
  </div>
</div>

<script>
const smtpProviders = {
  gmail:   {host:'smtp.gmail.com',        port:'587', enc:'tls'},
  outlook: {host:'smtp-mail.outlook.com', port:'587', enc:'tls'},
  yahoo:   {host:'smtp.mail.yahoo.com',   port:'587', enc:'tls'},
  ses:     {host:'email-smtp.us-east-1.amazonaws.com', port:'587', enc:'tls'},
  custom:  {host:'mail.yourdomain.com',   port:'587', enc:'tls'},
};
function fillProvider(key) {
  const p = smtpProviders[key]; if(!p) return;
  document.getElementById('smtpHost').value = p.host;
  document.getElementById('smtpPort').value = p.port;
  document.getElementById('smtpEnc').value  = p.enc;
  showToast('Filled ' + key + ' defaults', 'success');
}
function togglePass() {
  const f = document.getElementById('smtpPass');
  const i = document.getElementById('passEyeIcon');
  f.type = f.type==='password'?'text':'password';
  i.className = f.type==='password'?'fas fa-eye':'fas fa-eye-slash';
}
async function sendTest() {
  const addr = document.getElementById('testEmailAddr').value.trim();
  if (!addr) { showToast('Please enter a test email address','error'); return; }
  const fd = new FormData(document.getElementById('smtpForm'));
  fd.set('action','test_smtp');
  fd.append('test_email', addr);
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  const badge = document.getElementById('smtpStatusBadge');
  badge.style.display = 'block';
  badge.style.background = d.success ? '#ECFDF5' : '#FEF2F2';
  badge.style.color      = d.success ? '#065F46' : '#991B1B';
  badge.style.border     = '1px solid ' + (d.success ? '#A7F3D0' : '#FCA5A5');
  badge.innerHTML = '<i class="fas fa-' + (d.success?'check-circle':'times-circle') + '"></i> ' + d.message;
}
function showGuide(key) {
  ['gmail','outlook','ses','custom'].forEach(k => {
    document.getElementById('guide_'+k).style.display = k===key ? 'block' : 'none';
    document.getElementById('tab_'+k).style.borderColor = k===key ? 'var(--primary)' : '';
    document.getElementById('tab_'+k).style.color = k===key ? 'var(--primary)' : '';
  });
}
</script>

<!-- SMTP Debug Log -->
<div class="card mt-24">
  <div class="card-header">
    <h2><i class="fas fa-bug" style="color:#B45309;margin-right:8px"></i>SMTP Debug Log</h2>
    <a href="<?= APP_URL ?>/modules/settings/index.php" class="btn btn-outline btn-sm"><i class="fas fa-rotate-right"></i> Refresh</a>
  </div>
  <div class="card-body">
    <?php if (!$smtpDebugLogPath): ?>
      <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:12px 14px;color:#92400E;font-size:13px">
        Could not read PHP error log path. Check <code>error_log</code> in php.ini.
      </div>
    <?php elseif ($smtpDebugLastError): ?>
      <div style="display:grid;gap:10px">
        <div style="font-size:12px;color:var(--text-light)">
          Source: <code><?= htmlspecialchars($smtpDebugLogPath) ?></code>
          <?php if ($smtpDebugLogMTime): ?> · Updated: <?= date('Y-m-d H:i:s', (int)$smtpDebugLogMTime) ?><?php endif; ?>
        </div>
        <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:12px 14px;color:#991B1B;font-size:13px;line-height:1.6;word-break:break-word">
          <strong>Last SMTP error:</strong><br>
          <code style="white-space:pre-wrap"><?= htmlspecialchars($smtpDebugLastError) ?></code>
        </div>
      </div>
    <?php else: ?>
      <div style="display:grid;gap:10px">
        <div style="font-size:12px;color:var(--text-light)">Source: <code><?= htmlspecialchars($smtpDebugLogPath) ?></code></div>
        <div style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:10px;padding:12px 14px;color:#065F46;font-size:13px">
          No SMTP-related errors found in recent log entries.
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- System Info -->
<div class="card mt-24">
  <div class="card-header"><h2>System Information</h2></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:20px">
      <div><div style="font-size:11.5px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Application</div><div style="font-weight:600"><?= APP_NAME ?> v<?= APP_VERSION ?></div></div>
      <div><div style="font-size:11.5px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">PHP Version</div><div style="font-weight:600"><?= phpversion() ?></div></div>
      <div><div style="font-size:11.5px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Database</div><div style="font-weight:600">MySQL / MariaDB</div></div>
      <div><div style="font-size:11.5px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Server</div><div style="font-weight:600"><?= $_SERVER['SERVER_SOFTWARE'] ?? 'Apache' ?></div></div>
      <div><div style="font-size:11.5px;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Timezone</div><div style="font-weight:600"><?= date_default_timezone_get() ?></div></div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
