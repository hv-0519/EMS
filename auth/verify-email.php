<?php
// auth/verify-email.php
require_once __DIR__ . '/../includes/auth.php';
$token = trim($_GET['token'] ?? '');
$pdo   = getDB();
$ok    = false;

if ($token) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE verification_token = ? AND is_verified = 0');
    $stmt->execute([$token]);
    $u = $stmt->fetch();
    if ($u) {
        $pdo->prepare('UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = ?')
            ->execute([$u['id']]);
        $ok = true;
    }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><title>Email Verified | EmpAxis</title>
<link rel="icon" type="image/png" href="<?= APP_URL ?>/logos/EmpAxis.png?v=2">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
</head><body class="auth-body">
<div class="auth-card" style="text-align:center">
  <div class="auth-logo" style="justify-content:center;margin-bottom:16px"><div class="logo-icon"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div><h1>Emp<span>Axis</span></h1></div>
  <?php if ($ok): ?>
  <div style="font-size:56px;color:var(--success);margin-bottom:16px"><i class="fas fa-check-circle"></i></div>
  <h2 style="margin-bottom:8px">Email Verified!</h2>
  <p style="color:var(--text-mid);margin-bottom:24px">Your account is now active. You can log in.</p>
  <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary" style="justify-content:center">Go to Login</a>
  <?php else: ?>
  <div style="font-size:56px;color:var(--danger);margin-bottom:16px"><i class="fas fa-times-circle"></i></div>
  <h2 style="margin-bottom:8px">Invalid Link</h2>
  <p style="color:var(--text-mid)">This verification link is invalid or already used.</p>
  <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-secondary mt-24" style="justify-content:center">Back to Login</a>
  <?php endif; ?>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body></html>
