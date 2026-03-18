<?php
// auth/forgot-password.php
require_once __DIR__ . '/../includes/auth.php';
$sent = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $token   = generateToken(64);
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $pdo->prepare('INSERT INTO password_resets (email,token,expires_at) VALUES(?,?,?)')
                ->execute([$email, $token, $expires]);
            $link = APP_URL . '/auth/reset-password.php?token=' . $token;
            sendEmail($email, 'Reset your EmpAxis password',
              "<p>Click the link below to reset your password (expires in 1 hour):</p>
               <a href='$link' style='color:#5B6EF5'>$link</a>");
        }
        $sent = true; // Always show success (security)
    }
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password | EmpAxis</title>
<link rel="icon" type="image/png" href="<?= APP_URL ?>/logos/EmpAxis.png?v=2">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css">
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-logo"><div class="logo-icon"><img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis"></div><h1>Emp<span>Axis</span></h1></div>
  <p class="auth-subtitle">Reset your password</p>
  <?php if ($sent): ?>
  <div class="alert alert-success"><i class="fas fa-check-circle"></i> If an account exists, a reset link has been sent to your email.</div>
  <?php else: ?>
  <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><?= $error ?></div><?php endif; ?>
  <form method="POST" data-validate>
    <div class="form-group">
      <label>Email Address</label>
      <div class="input-with-icon">
        <i class="fas fa-envelope input-icon"></i>
        <input type="email" name="email" class="form-control" placeholder="you@company.com" required>
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt-24" style="width:100%;justify-content:center">
      <i class="fas fa-paper-plane"></i> Send Reset Link
    </button>
  </form>
  <?php endif; ?>
  <div class="auth-footer"><a href="<?= APP_URL ?>/auth/login.php">← Back to Login</a></div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body></html>
