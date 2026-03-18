<?php
require_once __DIR__ . '/../includes/auth.php';
if (isLoggedIn()) redirect(APP_URL . '/dashboard/dashboard.php');

$pdo        = getDB();
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $li = trim($_POST['login']    ?? '');
    $pw = $_POST['password']       ?? '';

    if (!$li || !$pw) {
        $loginError = 'Please enter your username/email and password.';
    } else {
        $s = $pdo->prepare('SELECT u.*,e.name AS emp_name,e.photo AS emp_photo FROM users u LEFT JOIN employees e ON e.user_id=u.id WHERE (u.email=? OR u.username=?) AND u.is_verified=1 LIMIT 1');
        $s->execute([$li, $li]);
        $user = $s->fetch();

        if ($user && password_verify($pw, $user['password'])) {
            if (empty($user['display_name'])) {
                $user['display_name'] = $user['emp_name'] ?: $user['username'];
            }
            try {
                $pdo->prepare('UPDATE users SET last_login_at=NOW(),last_login_ip=? WHERE id=?')
                    ->execute([$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $user['id']]);
            } catch (\Throwable $e) {}

            loginUser($user);
            logActivity('Logged in', 'User signed in', 'auth');

            // Force password change on first login
            if (!empty($user['must_change_password'])) {
                redirect(APP_URL . '/auth/change-password.php');
            }
            redirect(APP_URL . '/dashboard/dashboard.php');
        } else {
            $loginError = 'Invalid username or password. Please try again.';
            logActivity('Failed login attempt', "Failed login for: $li", 'auth');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>EmpAxis — Sign In</title>
  <script>(function(){try{var s=localStorage.getItem('theme'),t=(s==='light'||s==='dark')?s:(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}})()</script>
  <link rel="icon" type="image/png" href="<?= APP_URL ?>/logos/EmpAxis.png?v=2">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
</head>
<body class="ea-auth-body">
<div class="ea-shell">
  <!-- LEFT: branded panel -->
  <div class="ea-left">
    <div class="ea-left-inner">
      <div class="ea-brand">
        <div class="ea-brand-icon"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div>
        <span class="ea-brand-name">Emp<b>Axis</b></span>
      </div>
      <div class="ea-hero">
        <div class="ea-pill"><span class="ea-pill-dot"></span>HR Platform</div>
        <h1 class="ea-headline">Manage your<br><em>team, smarter.</em></h1>
        <p class="ea-sub">Attendance, payroll, leaves and people data — all in one elegant workflow.</p>
      </div>
      <div class="ea-feat-list">
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-layer-group"></i></div><div><div class="ea-feat-name">Smart Dashboards</div><div class="ea-feat-desc">Role-based views for Admin, HR &amp; Employees</div></div></div>
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-clock"></i></div><div><div class="ea-feat-name">Live Shift Tracker</div><div class="ea-feat-desc">Multi-session working hours with auto-calculation</div></div></div>
        <div class="ea-feat"><div class="ea-feat-ico"><i class="fas fa-shield-halved"></i></div><div><div class="ea-feat-name">Secure &amp; Fast</div><div class="ea-feat-desc">bcrypt auth, role-based access, PDO prepared statements</div></div></div>
      </div>
      <div class="ea-metrics">
        <div class="ea-metric"><b>3</b><span>Roles</span></div>
        <div class="ea-metric-sep"></div>
        <div class="ea-metric"><b>8+</b><span>Modules</span></div>
        <div class="ea-metric-sep"></div>
        <div class="ea-metric"><b>100%</b><span>Free &amp; Open</span></div>
      </div>
      <button class="ea-theme-toggle" id="themeToggle" title="Toggle theme">
        <i class="fas fa-sun ea-sun"></i><i class="fas fa-moon ea-moon"></i>
      </button>
    </div>
  </div>

  <!-- RIGHT: login form -->
  <div class="ea-right">
    <div class="ea-right-inner">
      <div class="ea-right-brand">
        <div class="ea-brand-icon-sm"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div>
        <span class="ea-brand-name-sm">Emp<b>Axis</b></span>
      </div>
      <h2 class="ea-form-title">Welcome back</h2>
      <p class="ea-form-sub">Sign in to your workspace</p>

      <?php if ($loginError): ?>
      <div class="ea-error-box"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($loginError) ?></div>
      <?php endif; ?>

      <form method="POST" class="ea-form" novalidate>
        <div class="ea-fgroup">
          <label>Email or Username</label>
          <div class="ea-finput">
            <i class="fas fa-user ea-ficon"></i>
            <input type="text" name="login" class="ea-input" required autocomplete="username"
              value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" placeholder="john.doe or john@company.com">
          </div>
        </div>
        <div class="ea-fgroup">
          <div class="ea-flabel-row">
            <label>Password</label>
            <a href="<?= APP_URL ?>/auth/forgot-password.php" class="ea-link">Forgot password?</a>
          </div>
          <div class="ea-finput">
            <i class="fas fa-lock ea-ficon"></i>
            <input type="password" name="password" id="loginPwd" class="ea-input" required autocomplete="current-password" placeholder="Your password">
            <button type="button" class="ea-eye" data-for="loginPwd"><i class="fas fa-eye"></i></button>
          </div>
        </div>
        <button type="submit" class="ea-btn ea-btn-primary ea-btn-block">
          <i class="fas fa-arrow-right-to-bracket"></i> Sign In
        </button>
      </form>

      <div class="ea-demo">
        <span class="ea-demo-label">Quick demo login</span>
        <div class="ea-demo-row">
          <button class="ea-demo-chip" data-u="admin"      data-p="password"><i class="fas fa-crown"></i> Admin</button>
          <button class="ea-demo-chip" data-u="hrmanager"  data-p="password"><i class="fas fa-user-tie"></i> HR</button>
          <button class="ea-demo-chip" data-u="john.doe"   data-p="password"><i class="fas fa-user"></i> Employee</button>
        </div>
        <div style="font-size:11px;color:var(--text-light);text-align:center;margin-top:6px">
          Password for all demo accounts: <code style="background:var(--secondary);padding:1px 5px;border-radius:4px">password</code>
        </div>
      </div>

      <div class="ea-info-note">
        <i class="fas fa-info-circle"></i>
        Accounts are created by HR. Contact your administrator if you cannot log in.
      </div>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script>
document.getElementById('themeToggle').addEventListener('click', () => {
  const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
  try { localStorage.setItem('theme', t); } catch(e) {}
});
document.querySelectorAll('.ea-eye').forEach(b => {
  b.addEventListener('click', function() {
    const f = document.getElementById(this.dataset.for), i = this.querySelector('i');
    if (!f) return;
    f.type = f.type === 'password' ? 'text' : 'password';
    i.className = f.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
  });
});
document.querySelectorAll('.ea-demo-chip').forEach(c => {
  c.addEventListener('click', function() {
    document.querySelector('[name="login"]').value = this.dataset.u;
    document.getElementById('loginPwd').value = this.dataset.p;
    document.querySelectorAll('.ea-demo-chip').forEach(x=>x.classList.remove('active'));
    this.classList.add('active');
    setTimeout(()=>this.classList.remove('active'), 1200);
  });
});
</script>
</body></html>
