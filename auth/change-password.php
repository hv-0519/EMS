<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo   = getDB();
$error = '';
$done  = false;

// If password is already changed, redirect to dashboard
$userRow = $pdo->prepare('SELECT must_change_password FROM users WHERE id=?');
$userRow->execute([$_SESSION['user_id']]);
$row = $userRow->fetch();
if ($row && !(int)$row['must_change_password']) {
    redirect(APP_URL . '/dashboard/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPw  = $_POST['new_password']     ?? '';
    $confPw = $_POST['confirm_password'] ?? '';

    if (strlen($newPw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPw !== $confPw) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/[A-Z]/', $newPw) || !preg_match('/[0-9]/', $newPw)) {
        $error = 'Password must contain at least one uppercase letter and one number.';
    } else {
        $hash = password_hash($newPw, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password=?, must_change_password=0 WHERE id=?')
            ->execute([$hash, $_SESSION['user_id']]);
        logActivity('Password changed', 'Employee set new password on first login', 'auth');
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>EmpAxis — Set Your Password</title>
  <script>(function(){try{var s=localStorage.getItem('theme'),t=(s==='light'||s==='dark')?s:(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}})()</script>
  <link rel="icon" type="image/png" href="<?= APP_URL ?>/logos/EmpAxis.png?v=2">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
</head>
<body class="ea-auth-body">
<div class="ea-shell">
  <div class="ea-left">
    <div class="ea-left-inner">
      <div class="ea-brand"><div class="ea-brand-icon"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div><span class="ea-brand-name">Emp<b>Axis</b></span></div>
      <div class="ea-hero">
        <div class="ea-pill"><span class="ea-pill-dot"></span>First Login</div>
        <h1 class="ea-headline">Set your<br><em>own password.</em></h1>
        <p class="ea-sub">Your account was created by HR. For your security, you must set a personal password before continuing.</p>
      </div>
      <div class="ea-feat-list">
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-lock"></i></div><div><div class="ea-feat-name">Your Password, Your Privacy</div><div class="ea-feat-desc">HR cannot access your new password</div></div></div>
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-shield-halved"></i></div><div><div class="ea-feat-name">Minimum Requirements</div><div class="ea-feat-desc">8+ chars, one uppercase, one number</div></div></div>
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-key"></i></div><div><div class="ea-feat-name">One Time Only</div><div class="ea-feat-desc">You won't be asked again after this</div></div></div>
      </div>
    </div>
  </div>

  <div class="ea-right">
    <div class="ea-right-inner">
      <div class="ea-right-brand"><div class="ea-brand-icon-sm"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div><span class="ea-brand-name-sm">Emp<b>Axis</b></span></div>

      <?php if ($done): ?>
      <div style="text-align:center;padding:20px 0">
        <div style="width:72px;height:72px;background:linear-gradient(135deg,#10B981,#059669);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
          <i class="fas fa-check" style="color:#fff;font-size:28px"></i>
        </div>
        <h2 class="ea-form-title" style="margin-bottom:8px">Password Set!</h2>
        <p class="ea-form-sub">Your password has been saved. You can now access your dashboard.</p>
        <a href="<?= APP_URL ?>/dashboard/dashboard.php" class="ea-btn ea-btn-primary ea-btn-block" style="margin-top:24px;display:flex;justify-content:center">
          <i class="fas fa-th-large"></i> Go to Dashboard
        </a>
      </div>

      <?php else: ?>
      <h2 class="ea-form-title">Set Your Password</h2>
      <p class="ea-form-sub">This is a one-time setup. Choose a strong, memorable password.</p>

      <div style="background:linear-gradient(135deg,#FEF3C7,#FDE68A);border:1px solid #F59E0B;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:12.5px;color:#92400E">
        <i class="fas fa-exclamation-triangle"></i>&nbsp;
        You are using a <strong>temporary password</strong> set by HR. You must change it to continue.
      </div>

      <?php if ($error): ?>
      <div class="ea-error-box"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="ea-form" novalidate id="cpForm">
        <div class="ea-fgroup">
          <label>New Password</label>
          <div class="ea-finput">
            <i class="fas fa-lock ea-ficon"></i>
            <input type="password" name="new_password" id="newPwd" class="ea-input" required placeholder="At least 8 characters" oninput="checkStrength(this.value)">
            <button type="button" class="ea-eye" data-for="newPwd"><i class="fas fa-eye"></i></button>
          </div>
          <!-- Strength meter -->
          <div style="margin-top:8px">
            <div style="background:#E5E7EB;border-radius:4px;height:5px;overflow:hidden">
              <div id="strengthBar" style="height:100%;border-radius:4px;width:0%;transition:all .3s"></div>
            </div>
            <div id="strengthLabel" style="font-size:11px;color:var(--text-light);margin-top:4px">Enter a password</div>
          </div>
        </div>
        <div class="ea-fgroup">
          <label>Confirm Password</label>
          <div class="ea-finput">
            <i class="fas fa-lock ea-ficon"></i>
            <input type="password" name="confirm_password" id="confPwd" class="ea-input" required placeholder="Repeat your new password">
            <button type="button" class="ea-eye" data-for="confPwd"><i class="fas fa-eye"></i></button>
          </div>
        </div>
        <div style="background:var(--secondary);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--text-light);margin-bottom:16px">
          <strong style="color:var(--text-mid)">Requirements:</strong>
          <span id="req-len" style="margin-left:8px">✗ 8+ characters</span>
          <span id="req-upper" style="margin-left:8px">✗ Uppercase letter</span>
          <span id="req-num" style="margin-left:8px">✗ Number</span>
        </div>
        <button type="submit" class="ea-btn ea-btn-primary ea-btn-block">
          <i class="fas fa-check-circle"></i> Set My Password
        </button>
      </form>

      <div class="ea-switch" style="text-align:center;margin-top:16px">
        <a href="<?= APP_URL ?>/auth/logout.php" class="ea-switch-link" style="color:var(--text-light);font-size:12px">
          <i class="fas fa-arrow-left" style="font-size:10px"></i> Back to login
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script>
document.querySelectorAll('.ea-eye').forEach(b => {
  b.addEventListener('click', function() {
    const f = document.getElementById(this.dataset.for), i = this.querySelector('i');
    if (!f) return;
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
  });
});

function checkStrength(pw) {
  const bar = document.getElementById('strengthBar');
  const lbl = document.getElementById('strengthLabel');
  const rl  = document.getElementById('req-len');
  const ru  = document.getElementById('req-upper');
  const rn  = document.getElementById('req-num');

  const hasLen   = pw.length >= 8;
  const hasUpper = /[A-Z]/.test(pw);
  const hasNum   = /[0-9]/.test(pw);
  const hasSpec  = /[^A-Za-z0-9]/.test(pw);

  rl.textContent  = (hasLen   ? '✓' : '✗') + ' 8+ characters';
  rl.style.color  = hasLen   ? 'var(--success)' : 'var(--text-light)';
  ru.textContent  = (hasUpper ? '✓' : '✗') + ' Uppercase letter';
  ru.style.color  = hasUpper ? 'var(--success)' : 'var(--text-light)';
  rn.textContent  = (hasNum   ? '✓' : '✗') + ' Number';
  rn.style.color  = hasNum   ? 'var(--success)' : 'var(--text-light)';

  const score = [hasLen, hasUpper, hasNum, hasSpec, pw.length >= 12].filter(Boolean).length;
  const levels = [
    {w:'0%',   c:'transparent', t:''},
    {w:'25%',  c:'#EF4444',     t:'Weak'},
    {w:'50%',  c:'#F59E0B',     t:'Fair'},
    {w:'75%',  c:'#3B82F6',     t:'Good'},
    {w:'100%', c:'#10B981',     t:'Strong'},
  ];
  const l = levels[score] || levels[0];
  bar.style.width      = l.w;
  bar.style.background = l.c;
  lbl.textContent      = l.t ? 'Strength: ' + l.t : 'Enter a password';
  lbl.style.color      = l.c || 'var(--text-light)';
}
</script>
</body></html>
