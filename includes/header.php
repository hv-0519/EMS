<?php
// includes/header.php
if (!defined('APP_URL')) require_once __DIR__ . '/../config/database.php';
$flash       = getFlash();
$unread      = unreadNotifCount();
$headerEmp   = getCurrentEmployee();
$displayName = $_SESSION['display_name'] ?? ($_SESSION['username'] ?? 'User');
$initials    = strtoupper(substr(trim($displayName) ?: 'U', 0, 1));
$avatarPlaceholder = APP_URL . '/assets/img/avatar-placeholder.svg';
$userAvatarUrl = $avatarPlaceholder;
$avatarCandidates = [];
if ($headerEmp && !empty($headerEmp['photo']) && strtolower((string)$headerEmp['photo']) !== 'default.png') {
    $avatarCandidates[] = (string)$headerEmp['photo'];
}
if (!empty($_SESSION['avatar']) && strtolower((string)$_SESSION['avatar']) !== 'default.png') {
    $avatarCandidates[] = (string)$_SESSION['avatar'];
}
foreach ($avatarCandidates as $candidate) {
    if (filter_var($candidate, FILTER_VALIDATE_URL)) {
        $userAvatarUrl = $candidate;
        break;
    }
    $candidateName = basename($candidate);
    if ($candidateName === '' || $candidateName === '.' || $candidateName === '..') continue;
    $candidatePath = UPLOAD_DIR . $candidateName;
    if (file_exists($candidatePath)) {
        $userAvatarUrl = UPLOAD_URL . rawurlencode($candidateName) . '?v=' . filemtime($candidatePath);
    } else {
        // Try direct URL even if PHP cannot stat file (sync/permission edge case).
        $userAvatarUrl = UPLOAD_URL . rawurlencode($candidateName);
    }
    break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> | <?= APP_NAME ?></title>
  <script>(function(){try{var s=localStorage.getItem('theme'),t=(s==='light'||s==='dark')?s:(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}})()</script>
  <link rel="icon" type="image/png" href="<?= APP_URL ?>/logos/EmpAxis.png?v=2">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <?php if (isset($extraStyles)) echo $extraStyles; ?>
</head>
<body>

<div class="app-wrapper">
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="main-content" id="main-content">
  <header class="topbar">
    <div class="topbar-left">
      <button class="hamburger-btn" id="hamburger-btn" aria-label="Menu"><i class="fas fa-bars"></i></button>
      <span class="page-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></span>
    </div>
    <div class="topbar-right">
      <form method="GET" action="<?= APP_URL ?>/search.php" style="flex:1;max-width:280px">
        <div class="search-bar">
          <i class="fas fa-search"></i>
          <input type="text" name="q" placeholder="Search employees…" autocomplete="off">
        </div>
      </form>

      <button class="topbar-icon-btn theme-toggle" id="theme-toggle" data-theme-toggle type="button" aria-label="Toggle theme" title="Toggle theme">
        <i class="fas fa-sun sun"></i><i class="fas fa-moon moon"></i>
      </button>

      <!-- Notifications -->
      <div class="dropdown">
        <button class="topbar-icon-btn dropdown-trigger" aria-label="Notifications">
          <i class="fas fa-bell"></i>
          <?php if ($unread > 0): ?><span class="badge"><?= min($unread, 99) ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu notif-dropdown">
          <div class="notif-header">
            <span>Notifications</span>
            <?php if ($unread > 0): ?><a href="<?= APP_URL ?>/modules/notifications/index.php?markRead=1" class="notif-markall">Mark all read</a><?php endif; ?>
          </div>
          <?php
          try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY is_read ASC, created_at DESC LIMIT 6');
            $stmt->execute([$_SESSION['user_id']]);
            $notifs = $stmt->fetchAll();
          } catch (\Throwable $e) { $notifs = []; }
          $nIcons = ['success'=>'fa-check-circle','error'=>'fa-times-circle','warning'=>'fa-exclamation-circle','info'=>'fa-info-circle'];
          $nColors= ['success'=>'#10B981','error'=>'#EF4444','warning'=>'#F59E0B','info'=>'#3B82F6'];
          if ($notifs): foreach ($notifs as $n):
            $ic = $nIcons[$n['type']] ?? 'fa-info-circle';
            $nc = $nColors[$n['type']] ?? '#3B82F6';
          ?>
          <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
            <i class="fas <?= $ic ?>" style="color:<?= $nc ?>;font-size:15px;margin-top:2px;flex-shrink:0"></i>
            <div class="notif-body">
              <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
              <div class="notif-msg"><?= htmlspecialchars(mb_strimwidth($n['message'],0,60,'…')) ?></div>
              <div class="notif-time"><?= date('M d, H:i', strtotime($n['created_at'])) ?></div>
            </div>
            <?php if (!$n['is_read']): ?><div class="notif-dot"></div><?php endif; ?>
          </div>
          <?php endforeach; else: ?>
          <div class="notif-empty"><i class="fas fa-bell-slash"></i><span>No notifications</span></div>
          <?php endif; ?>
          <div class="dropdown-divider"></div>
          <a href="<?= APP_URL ?>/modules/notifications/index.php" class="notif-viewall">View all notifications</a>
        </div>
      </div>

      <!-- Quick Menu -->
      <!-- <div class="dropdown">
        <button class="topbar-icon-btn dropdown-trigger" aria-label="Quick menu" title="Quick menu">
          <i class="fas fa-bolt"></i>
        </button>
        <div class="dropdown-menu">
          <a href="<?= APP_URL ?>/dashboard/dashboard.php" class="dropdown-item"><i class="fas fa-th-large"></i> Dashboard</a>
          <a href="<?= APP_URL ?>/modules/attendance/index.php" class="dropdown-item"><i class="fas fa-calendar-check"></i> Attendance</a>
          <a href="<?= APP_URL ?>/modules/leave/index.php" class="dropdown-item"><i class="fas fa-calendar-minus"></i> Leave Requests</a>
          <?php if (($_SESSION['role'] ?? '') !== 'employee'): ?>
          <a href="<?= APP_URL ?>/modules/employees/index.php" class="dropdown-item"><i class="fas fa-users"></i> Employees</a>
          <?php endif; ?>
        </div>
      </div> -->

      <!-- Profile -->
      <div class="dropdown">
        <div class="profile-btn dropdown-trigger">
          <div class="avatar">
            <img src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarPlaceholder) ?>';">
          </div>
          <div>
            <div class="profile-name"><?= htmlspecialchars(mb_strimwidth($displayName, 0, 18, '…')) ?></div>
            <div class="profile-role"><?= ucfirst($_SESSION['role'] ?? '') ?></div>
          </div>
          <i class="fas fa-chevron-down" style="font-size:10px;color:var(--text-light);margin-left:4px"></i>
        </div>
        <div class="dropdown-menu">
          <?php if ($headerEmp): ?>
          <a href="<?= APP_URL ?>/modules/employees/profile.php?id=<?= $headerEmp['id'] ?>" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/modules/settings/index.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
          <div class="dropdown-divider"></div>
          <a href="<?= APP_URL ?>/auth/logout.php" class="dropdown-item" style="color:var(--danger)"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
      </div>
    </div>
  </header>

  <?php if ($flash): ?>
  <div style="padding:14px 28px 0">
    <div class="alert alert-<?= $flash['type'] ?>" id="flash-msg">
      <i class="fas fa-<?= $flash['type']==='success'?'check-circle':'exclamation-circle' ?>"></i>
      <?= htmlspecialchars($flash['message']) ?>
      <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;opacity:.6;font-size:18px;line-height:1">&times;</button>
    </div>
  </div>
  <script>setTimeout(()=>{const e=document.getElementById('flash-msg');if(e){e.style.transition='opacity .5s';e.style.opacity='0';setTimeout(()=>e.remove(),500);}},4500);</script>
  <?php endif; ?>

  <div class="page-body">
