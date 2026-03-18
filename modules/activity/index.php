<?php
// modules/activity/index.php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'hr');

$pageTitle   = 'Activity Log';
$currentPage = 'activity';
$pdo         = getDB();

// Clear all (admin only)
if ($_GET['clear'] ?? false && hasRole('admin')) {
    $pdo->exec('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    setFlash('success', 'Logs older than 30 days cleared.');
    redirect(APP_URL . '/modules/activity/index.php');
}

$filterModule = $_GET['module'] ?? '';
$filterUser   = trim($_GET['user'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = 'WHERE 1=1';
$params = [];
if ($filterModule) { $where .= ' AND module = ?'; $params[] = $filterModule; }
if ($filterUser)   { $where .= ' AND username LIKE ?'; $params[] = "%$filterUser%"; }

$total = $pdo->prepare("SELECT COUNT(*) FROM activity_log $where");
$total->execute($params); $total = $total->fetchColumn();
$pages = ceil($total / $perPage);

$stmt = $pdo->prepare("SELECT * FROM activity_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$modules = $pdo->query("SELECT DISTINCT module FROM activity_log WHERE module != '' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

$moduleIcon = [
    'employees'   => 'fa-users',
    'departments' => 'fa-sitemap',
    'leave'       => 'fa-calendar-minus',
    'payroll'     => 'fa-wallet',
    'attendance'  => 'fa-calendar-check',
    'auth'        => 'fa-lock',
    'settings'    => 'fa-cog',
    'reports'     => 'fa-chart-bar',
];
$roleColor = [
    'admin'    => 'var(--primary)',
    'hr'       => '#9333EA',
    'employee' => 'var(--info)',
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Activity Log</h1>
    <p>Track all user actions across the system</p>
  </div>
  <?php if (hasRole('admin')): ?>
  <a href="?clear=1" class="btn btn-secondary btn-sm"
     onclick="return confirm('Delete logs older than 30 days?')">
    <i class="fas fa-broom"></i> Clear Old Logs
  </a>
  <?php endif; ?>
</div>

<div class="card">
  <!-- Toolbar -->
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;flex:1">
      <div class="table-search" style="max-width:220px">
        <i class="fas fa-user"></i>
        <input type="text" name="user" placeholder="Search username…" value="<?= htmlspecialchars($filterUser) ?>">
      </div>
      <select name="module" class="filter-select" onchange="this.form.submit()">
        <option value="">All Modules</option>
        <?php foreach ($modules as $m): ?>
        <option value="<?= $m ?>" <?= $filterModule===$m?'selected':'' ?>><?= ucfirst($m) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-filter"></i></button>
    </form>
    <span style="font-size:13px;color:var(--text-light)"><?= number_format($total) ?> records</span>
  </div>

  <!-- Log List -->
  <?php foreach ($logs as $log):
    $icon  = $moduleIcon[$log['module']] ?? 'fa-circle';
    $rColor= $roleColor[$log['role']] ?? 'var(--text-mid)';
  ?>
  <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 22px;border-bottom:1px solid var(--border)">
    <!-- Module icon -->
    <div style="width:36px;height:36px;background:var(--primary-light);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:15px;flex-shrink:0">
      <i class="fas <?= $icon ?>"></i>
    </div>

    <!-- Content -->
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-weight:600;font-size:13.5px"><?= htmlspecialchars($log['action']) ?></span>
        <?php if ($log['module']): ?>
        <span style="font-size:11px;background:var(--secondary);padding:2px 8px;border-radius:20px;color:var(--text-mid)"><?= ucfirst($log['module']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($log['description']): ?>
      <div style="font-size:12.5px;color:var(--text-mid);margin-top:2px"><?= htmlspecialchars($log['description']) ?></div>
      <?php endif; ?>
      <div style="display:flex;align-items:center;gap:12px;margin-top:5px;font-size:11.5px;color:var(--text-light)">
        <span style="color:<?= $rColor ?>;font-weight:600">
          <i class="fas fa-user" style="margin-right:4px"></i><?= htmlspecialchars($log['username']) ?>
        </span>
        <span><i class="fas fa-tag" style="margin-right:3px"></i><?= ucfirst($log['role']) ?></span>
        <span><i class="fas fa-clock" style="margin-right:3px"></i><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></span>
        <?php if ($log['ip_address']): ?>
        <span><i class="fas fa-globe" style="margin-right:3px"></i><?= htmlspecialchars($log['ip_address']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$logs): ?>
  <div style="padding:60px;text-align:center;color:var(--text-light)">
    <i class="fas fa-history" style="font-size:40px;margin-bottom:12px;display:block"></i>
    No activity logs found
  </div>
  <?php endif; ?>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="pagination">
    <span class="page-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= number_format($total) ?></span>
    <div class="page-controls">
      <a href="?page=<?= max(1,$page-1) ?>&module=<?= $filterModule ?>&user=<?= urlencode($filterUser) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-chevron-left"></i></a>
      <?php for ($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?>
      <a href="?page=<?= $p ?>&module=<?= $filterModule ?>&user=<?= urlencode($filterUser) ?>" class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a href="?page=<?= min($pages,$page+1) ?>&module=<?= $filterModule ?>&user=<?= urlencode($filterUser) ?>" class="page-btn <?= $page>=$pages?'disabled':'' ?>"><i class="fas fa-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
