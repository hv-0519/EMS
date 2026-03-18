<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle   = 'Notifications';
$currentPage = 'notifications';
$pdo         = getDB();

// Mark all read
if (isset($_GET["markRead"]) || isset($_GET["mark_all"])) {
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$_SESSION['user_id']]);
    redirect(APP_URL . '/modules/notifications/index.php');
}

// Delete one
if (isset($_GET['del'])) {
    $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([(int)$_GET['del'], $_SESSION['user_id']]);
    redirect(APP_URL . '/modules/notifications/index.php');
}

// Mark single read on view
if (isset($_GET['read'])) {
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$_GET['read'], $_SESSION['user_id']]);
}

$page    = max(1,(int)($_GET['page']??1));
$perPage = 15; $offset = ($page-1)*$perPage;

$total = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=?');
$total->execute([$_SESSION['user_id']]); $total = $total->fetchColumn();
$pages = ceil($total / $perPage);

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT '.$perPage.' OFFSET '.$offset);
$stmt->execute([$_SESSION['user_id']]);
$notifs = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div><h1>Notifications</h1></div>
  <?php if ($total > 0): ?>
  <a href="?markRead=1" class="btn btn-secondary btn-sm"><i class="fas fa-check-double"></i> Mark All Read</a>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!$notifs): ?>
  <div style="padding:60px;text-align:center;color:var(--text-light)">
    <i class="fas fa-bell-slash" style="font-size:40px;margin-bottom:12px;display:block"></i>
    No notifications yet
  </div>
  <?php else: ?>
  <?php foreach ($notifs as $n):
    $typeColor = ['success'=>'var(--success)', 'error'=>'var(--danger)', 'warning'=>'var(--warning)', 'info'=>'var(--info)'][$n['type']] ?? 'var(--primary)';
    $typeIcon  = ['success'=>'check-circle', 'error'=>'times-circle', 'warning'=>'exclamation-circle', 'info'=>'info-circle'][$n['type']] ?? 'bell';
  ?>
  <div style="display:flex;align-items:flex-start;gap:14px;padding:18px 22px;border-bottom:1px solid var(--border);background:<?= $n['is_read']?'#fff':'#FAFBFF' ?>;transition:background .2s" onmouseover="this.style.background='#f5f6ff'" onmouseout="this.style.background='<?= $n['is_read']?'#fff':'#FAFBFF' ?>'">
    <div style="width:40px;height:40px;border-radius:50%;background:<?= $typeColor ?>22;display:flex;align-items:center;justify-content:center;color:<?= $typeColor ?>;font-size:18px;flex-shrink:0">
      <i class="fas fa-<?= $typeIcon ?>"></i>
    </div>
    <div style="flex:1">
      <div style="font-weight:<?= $n['is_read']?'400':'600' ?>;margin-bottom:3px"><?= htmlspecialchars($n['title']) ?></div>
      <div style="font-size:13px;color:var(--text-mid);margin-bottom:5px"><?= htmlspecialchars($n['message']) ?></div>
      <div style="font-size:11.5px;color:var(--text-light)"><?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></div>
    </div>
    <div style="display:flex;gap:6px;flex-shrink:0">
      <?php if (!$n['is_read']): ?>
      <a href="?read=<?= $n['id'] ?>" class="btn-action" title="Mark read"><i class="fas fa-check"></i></a>
      <?php endif; ?>
      <a href="?del=<?= $n['id'] ?>" class="btn-action danger" title="Delete" onclick="return confirm('Delete this notification?')"><i class="fas fa-trash"></i></a>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <span class="page-info">Page <?= $page ?> of <?= $pages ?></span>
    <div class="page-controls">
      <?php for ($p=1;$p<=$pages;$p++): ?>
      <a href="?page=<?= $p ?>" class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
