<?php
// auth/reset-password.php
require_once __DIR__ . '/../includes/auth.php';
$token = trim($_GET['token'] ?? '');
$pdo   = getDB();
$done  = false; $error = '';

// Validate token
$rec = null;
if ($token) {
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()');
    $stmt->execute([$token]);
    $rec = $stmt->fetch();
}
if (!$rec && $token) $error = 'This link is invalid or has expired.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rec) {
    $pw  = $_POST['password'] ?? '';
    $cfm = $_POST['confirm']  ?? '';
    if (strlen($pw) < 8)   $error = 'Password must be at least 8 characters.';
    elseif ($pw !== $cfm)  $error = 'Passwords do not match.';
    else {
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE email = ?')->execute([$hash, $rec['email']]);
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')->execute([$token]);
        $done = true;
    }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password | EmpAxis</title>
<link rel="icon" type="image/png" href="<?= APP_URL ?>/logos/EmpAxis.png?v=2">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-logo"><div class="logo-icon"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div><h1>Emp<span>Axis</span></h1></div>
  <p class="auth-subtitle">Set a new password</p>
  <?php if ($done): ?>
  <div class="alert alert-success"><i class="fas fa-check-circle"></i> Password reset successfully! You can now log in.</div>
  <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px">Go to Login</a>
  <?php elseif ($error && !$rec): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
  <div class="auth-footer"><a href="<?= APP_URL ?>/auth/forgot-password.php">Request a new link</a></div>
  <?php else: ?>
  <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><?= $error ?></div><?php endif; ?>
  <form method="POST" data-validate>
    <div class="form-group">
      <label>New Password</label>
      <div class="input-with-icon">
        <i class="fas fa-lock input-icon"></i>
        <input type="password" name="password" class="form-control" placeholder="Min 8 chars" required>
      </div>
    </div>
    <div class="form-group mt-16">
      <label>Confirm Password</label>
      <div class="input-with-icon">
        <i class="fas fa-lock input-icon"></i>
        <input type="password" name="confirm" class="form-control" placeholder="Repeat" required>
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt-24" style="width:100%;justify-content:center">
      <i class="fas fa-save"></i> Reset Password
    </button>
  </form>
  <?php endif; ?>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body></html>
