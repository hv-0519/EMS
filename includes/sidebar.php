<?php
// includes/sidebar.php
if (!defined('APP_URL')) require_once __DIR__ . '/../config/database.php';
if (!isset($currentPage)) $currentPage = '';
$role        = $_SESSION['role'] ?? 'employee';
$displayName = $_SESSION['display_name'] ?? ($_SESSION['username'] ?? 'User');
$unread      = unreadNotifCount();
$sideEmp     = getCurrentEmployee();
$scriptPath  = $_SERVER['SCRIPT_NAME'] ?? '';
$isProfilePage = str_contains(str_replace('\\', '/', $scriptPath), '/modules/employees/profile.php');
?>
<div id="sidebar-overlay" class="sidebar-overlay" onclick="document.getElementById('sidebar').classList.remove('mobile-open')"></div>

<aside id="sidebar" class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">
      <img src="<?= APP_URL ?>/logos/EmpAxis.png" alt="EmpAxis Icon">
    </div>
    <span class="logo-text">EmpAxis</span>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
    <a href="<?= APP_URL ?>/dashboard/dashboard.php" class="nav-item <?= $currentPage==='dashboard'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-th-large"></i></span><span class="nav-text">Dashboard</span>
    </a>
    <?php if ($sideEmp): ?>
    <a href="<?= APP_URL ?>/modules/employees/profile.php?id=<?= (int)$sideEmp['id'] ?>" class="nav-item <?= $isProfilePage ? 'active' : '' ?>">
      <span class="nav-icon"><i class="fas fa-user-circle"></i></span><span class="nav-text">My Profile</span>
    </a>
    <?php endif; ?>

    <?php if ($role !== 'employee'): ?>
    <a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-item <?= ($currentPage==='employees' && !$isProfilePage)?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-users"></i></span><span class="nav-text">Employees</span>
    </a>
    <a href="<?= APP_URL ?>/modules/departments/index.php" class="nav-item <?= $currentPage==='departments'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-sitemap"></i></span><span class="nav-text">Departments</span>
    </a>
    <?php endif; ?>

    <div class="nav-section-label">Workforce</div>
    <a href="<?= APP_URL ?>/modules/attendance/index.php" class="nav-item <?= $currentPage==='attendance'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-calendar-check"></i></span><span class="nav-text">Attendance</span>
    </a>
    <a href="<?= APP_URL ?>/modules/leave/index.php" class="nav-item <?= $currentPage==='leave'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-calendar-minus"></i></span><span class="nav-text">Leaves</span>
    </a>
    <?php if ($role !== 'employee'): ?>
    <a href="<?= APP_URL ?>/modules/payroll/index.php" class="nav-item <?= $currentPage==='payroll'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-wallet"></i></span><span class="nav-text">Payroll</span>
    </a>
    <?php endif; ?>

    <div class="nav-section-label">System</div>
    <a href="<?= APP_URL ?>/modules/reports/index.php" class="nav-item <?= $currentPage==='reports'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-chart-bar"></i></span><span class="nav-text">Reports</span>
    </a>
    <?php if ($role !== 'employee'): ?>
    <a href="<?= APP_URL ?>/modules/activity/index.php" class="nav-item <?= $currentPage==='activity'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-history"></i></span><span class="nav-text">Activity Log</span>
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/modules/notifications/index.php" class="nav-item <?= $currentPage==='notifications'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-bell"></i></span><span class="nav-text">Notifications</span>
      <?php if ($unread > 0): ?><span class="nav-badge"><?= min($unread,99) ?></span><?php endif; ?>
    </a>
    <a href="<?= APP_URL ?>/modules/announcements/index.php" class="nav-item <?= $currentPage==='announcements'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-bullhorn"></i></span><span class="nav-text">Announcements</span>
    </a>
    <?php if ($role === 'admin'): ?>
    <a href="<?= APP_URL ?>/modules/settings/index.php" class="nav-item <?= $currentPage==='settings'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-cog"></i></span><span class="nav-text">Settings</span>
    </a>
    <?php endif; ?>

    <a href="<?= APP_URL ?>/auth/logout.php" class="nav-item nav-logout">
      <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span><span class="nav-text">Logout</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar">
        <?php if ($sideEmp && !empty($sideEmp['photo']) && $sideEmp['photo']!=='default.png' && file_exists(UPLOAD_DIR.$sideEmp['photo'])): ?>
        <img src="<?= UPLOAD_URL.htmlspecialchars($sideEmp['photo']) ?>" alt="">
        <?php else: ?><?= strtoupper(substr($displayName,0,1)) ?><?php endif; ?>
      </div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars(mb_strimwidth($displayName,0,16,'…')) ?></div>
        <div class="user-role"><?= ucfirst($role) ?></div>
      </div>
    </div>
    <button class="toggle-sidebar" id="toggle-sidebar" title="Collapse" style="border: 3px solid black;"><i class="fas fa-chevron-left"></i></button>
  </div>
</aside>
