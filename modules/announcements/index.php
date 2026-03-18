<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle   = 'Announcements';
$currentPage = 'announcements';
$pdo         = getDB();
$role        = $_SESSION['role'] ?? 'employee';
$userId      = $_SESSION['user_id'];

// ── Ensure tables exist ──────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(200) NOT NULL,
        body        TEXT NOT NULL,
        priority    ENUM('normal','important','urgent') DEFAULT 'normal',
        target_type ENUM('all','department','individual') DEFAULT 'all',
        target_id   INT UNSIGNED DEFAULT NULL,
        created_by  INT UNSIGNED NOT NULL,
        is_active   TINYINT(1) DEFAULT 1,
        expires_at  DATE DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        announcement_id INT UNSIGNED NOT NULL,
        user_id         INT UNSIGNED NOT NULL,
        read_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ann_user (announcement_id, user_id)
    ) ENGINE=InnoDB");
} catch (\Throwable $e) {}

// ── AJAX Actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    // POST announcement (admin/hr only)
    if ($action === 'post' && hasRole('admin','hr')) {
        $title      = trim($_POST['title']       ?? '');
        $body       = trim($_POST['body']        ?? '');
        $priority   = $_POST['priority']         ?? 'normal';
        $targetType = $_POST['target_type']      ?? 'all';
        $targetId   = (int)($_POST['target_id']  ?? 0) ?: null;
        $expiresAt  = $_POST['expires_at']       ?? null;

        if (!$title || !$body) { echo json_encode(['success'=>false,'message'=>'Title and body are required.']); exit; }
        if ($targetType !== 'all' && !$targetId) { echo json_encode(['success'=>false,'message'=>'Please select a target.']); exit; }
        if ($targetType === 'all') $targetId = null;

        $pdo->prepare('INSERT INTO announcements (title,body,priority,target_type,target_id,created_by,expires_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$title,$body,$priority,$targetType,$targetId,$userId,$expiresAt??null]);
        $annId = (int)$pdo->lastInsertId();

        // Create notifications
        $recipients = [];
        if ($targetType === 'all') {
            $recipients = $pdo->query('SELECT id FROM users WHERE role="employee" AND is_verified=1')->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($targetType === 'department') {
            $rows = $pdo->prepare('SELECT u.id FROM users u JOIN employees e ON e.user_id=u.id WHERE e.department_id=?');
            $rows->execute([$targetId]); $recipients = $rows->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($targetType === 'individual') {
            // target_id is employee_id, get user_id
            $row = $pdo->prepare('SELECT u.id FROM users u JOIN employees e ON e.user_id=u.id WHERE e.id=?');
            $row->execute([$targetId]); $r = $row->fetch(); if($r) $recipients = [$r['id']];
        }

        $pri_label = ['urgent'=>'🚨 URGENT','important'=>'⚠️ Important','normal'=>'📢 New'][''][$priority] ?? '📢 New';
        foreach ($recipients as $rid) {
            if ($rid == $userId) continue; // don't notify self
            $pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES(?,?,?,?)')
                ->execute([$rid, $pri_label.' Announcement', $title, $priority==='urgent'?'error':($priority==='important'?'warning':'info')]);
        }

        logActivity('Posted announcement', "\"$title\" → $targetType", 'announcements');
        echo json_encode(['success'=>true,'message'=>'Announcement posted to '.count($recipients).' employee(s).', 'id'=>$annId]); exit;
    }

    // Mark read
    if ($action === 'mark_read') {
        $annId = (int)$_POST['ann_id'];
        try { $pdo->prepare('INSERT IGNORE INTO announcement_reads (announcement_id,user_id) VALUES(?,?)')->execute([$annId,$userId]); } catch(\Throwable $e){}
        echo json_encode(['success'=>true]); exit;
    }

    // Delete (admin/hr only)
    if ($action === 'delete' && hasRole('admin','hr')) {
        $pdo->prepare('UPDATE announcements SET is_active=0 WHERE id=?')->execute([(int)$_POST['id']]);
        echo json_encode(['success'=>true,'message'=>'Announcement removed']); exit;
    }

    // Edit (admin/hr only)
    if ($action === 'edit' && hasRole('admin','hr')) {
        $id    = (int)$_POST['id'];
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body']  ?? '');
        $pdo->prepare('UPDATE announcements SET title=?,body=? WHERE id=?')->execute([$title,$body,$id]);
        echo json_encode(['success'=>true,'message'=>'Updated']); exit;
    }

    exit;
}

// ── Load announcements visible to this user ───────────────────
$me = getCurrentEmployee();
$deptId = $me['department_id'] ?? null;

// For employees: show all + their dept + their individual
// For admin/hr: show all (with management view)
if (hasRole('admin','hr')) {
    $stmt = $pdo->query("
        SELECT a.*, u.username AS posted_by_name, u.display_name AS posted_by_display,
               (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id=a.id) AS read_count
        FROM announcements a
        JOIN users u ON u.id=a.created_by
        WHERE a.is_active=1
        ORDER BY
          FIELD(a.priority,'urgent','important','normal'),
          a.created_at DESC
    ");
    $announcements = $stmt->fetchAll();
} else {
    // Employee: show announcements targeted to them
    $empId = $me['id'] ?? 0;
    $stmt  = $pdo->prepare("
        SELECT a.*, u.username AS posted_by_name, u.display_name AS posted_by_display,
               (SELECT 1 FROM announcement_reads ar WHERE ar.announcement_id=a.id AND ar.user_id=?) AS is_read
        FROM announcements a
        JOIN users u ON u.id=a.created_by
        WHERE a.is_active=1
          AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
          AND (
            a.target_type = 'all'
            OR (a.target_type = 'department' AND a.target_id = ?)
            OR (a.target_type = 'individual' AND a.target_id = ?)
          )
        ORDER BY
          FIELD(a.priority,'urgent','important','normal'),
          a.created_at DESC
    ");
    $stmt->execute([$userId, $deptId, $empId]);
    $announcements = $stmt->fetchAll();
}

// Load departments and employees for the "post" modal
$departments = $employees_list = [];
if (hasRole('admin','hr')) {
    $departments    = $pdo->query('SELECT id,department_name FROM departments ORDER BY department_name')->fetchAll();
    $employees_list = $pdo->query('SELECT e.id,e.name,e.employee_id,d.department_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE e.status="active" ORDER BY e.name')->fetchAll();
}

$unreadCount = count(array_filter($announcements, fn($a) => !$a['is_read']));

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1><i class="fas fa-bullhorn" style="color:var(--primary);margin-right:8px"></i>Announcements</h1>
    <p style="color:var(--text-light);font-size:13px">
      <?php if (hasRole('admin','hr')): ?>
        Post company-wide or targeted announcements to employees
      <?php else: ?>
        <?= count($announcements) ?> announcement<?= count($announcements)!=1?'s':'' ?><?= $unreadCount > 0 ? " · <strong style='color:var(--primary)'>$unreadCount unread</strong>" : '' ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if (hasRole('admin','hr')): ?>
  <button class="btn btn-primary" onclick="openModal('annModal')">
    <i class="fas fa-plus"></i> Post Announcement
  </button>
  <?php endif; ?>
</div>

<?php if (empty($announcements)): ?>
<div class="card">
  <div style="padding:70px;text-align:center">
    <div style="width:80px;height:80px;background:var(--secondary);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
      <i class="fas fa-bullhorn" style="font-size:32px;color:var(--text-light)"></i>
    </div>
    <h3 style="color:var(--text-mid);margin-bottom:6px">No announcements yet</h3>
    <p style="color:var(--text-light);font-size:13px">
      <?= hasRole('admin','hr') ? 'Click "Post Announcement" to send your first message to employees.' : 'You have no announcements at this time.' ?>
    </p>
    <?php if (hasRole('admin','hr')): ?>
    <button class="btn btn-primary" onclick="openModal('annModal')" style="margin-top:14px"><i class="fas fa-plus"></i> Post Announcement</button>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<?php if (hasRole('admin','hr')): ?>
<!-- Admin/HR stats bar -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
  <?php
  $urgents    = count(array_filter($announcements, fn($a)=>$a['priority']==='urgent'));
  $importants = count(array_filter($announcements, fn($a)=>$a['priority']==='important'));
  $normals    = count(array_filter($announcements, fn($a)=>$a['priority']==='normal'));
  ?>
  <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-bullhorn"></i></div><div class="stat-body"><div class="stat-value"><?= count($announcements) ?></div><div class="stat-label">Total Active</div></div></div>
  <div class="stat-card"><div class="stat-icon red"><i class="fas fa-exclamation-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $urgents ?></div><div class="stat-label">Urgent</div></div></div>
  <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?= $importants ?></div><div class="stat-label">Important</div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-info-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $normals ?></div><div class="stat-label">Normal</div></div></div>
</div>
<?php endif; ?>

<div id="annList">
<?php foreach ($announcements as $ann):
  $priColors = [
    'urgent'    => ['bg'=>'#FEF2F2','border'=>'#FCA5A5','accent'=>'#DC2626','icon'=>'fas fa-exclamation-circle','label'=>'Urgent'],
    'important' => ['bg'=>'#FFFBEB','border'=>'#FDE68A','accent'=>'#D97706','icon'=>'fas fa-exclamation-triangle','label'=>'Important'],
    'normal'    => ['bg'=>'#fff',   'border'=>'var(--border)','accent'=>'var(--primary)','icon'=>'fas fa-bullhorn','label'=>'Normal'],
  ];
  $pc  = $priColors[$ann['priority']] ?? $priColors['normal'];
  $isRead = !empty($ann['is_read']);
  $targetLabel = match($ann['target_type']) {
    'all'        => '<span style="color:var(--primary)"><i class="fas fa-globe"></i> All Employees</span>',
    'department' => '<span style="color:#7C3AED"><i class="fas fa-sitemap"></i> Department</span>',
    'individual' => '<span style="color:#059669"><i class="fas fa-user"></i> Individual</span>',
    default      => ''
  };
?>
<div class="ann-card" id="ann_<?= $ann['id'] ?>" style="background:<?= $pc['bg'] ?>;border:1px solid <?= $pc['border'] ?>;border-radius:14px;margin-bottom:14px;overflow:hidden;<?= (!$isRead && !hasRole('admin','hr')) ? 'box-shadow:0 0 0 3px '.($ann['priority']==='urgent'?'rgba(220,38,38,.15)':'rgba(91,110,245,.12)').',' : '' ?>transition:all .2s">
  <?php if ($ann['priority'] !== 'normal'): ?>
  <div style="height:4px;background:<?= $pc['accent'] ?>"></div>
  <?php endif; ?>
  <div style="padding:18px 20px">
    <div style="display:flex;align-items:flex-start;gap:14px">
      <div style="width:42px;height:42px;background:<?= $pc['accent'] ?>18;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="<?= $pc['icon'] ?>" style="color:<?= $pc['accent'] ?>;font-size:16px"></i>
      </div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px">
          <h3 style="font-size:15px;font-weight:700;color:var(--text-dark);margin:0"><?= htmlspecialchars($ann['title']) ?></h3>
          <?php if ($ann['priority'] !== 'normal'): ?>
          <span style="background:<?= $pc['accent'] ?>;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.5px"><?= $pc['label'] ?></span>
          <?php endif; ?>
          <?php if (!$isRead && !hasRole('admin','hr')): ?>
          <span style="background:var(--primary);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px">NEW</span>
          <?php endif; ?>
        </div>
        <div style="font-size:13.5px;color:var(--text-mid);line-height:1.7;margin-bottom:10px;white-space:pre-wrap"><?= nl2br(htmlspecialchars($ann['body'])) ?></div>
        <div style="display:flex;align-items:center;gap:16px;font-size:12px;color:var(--text-light);flex-wrap:wrap">
          <span><i class="fas fa-user-tie" style="margin-right:4px"></i><?= htmlspecialchars($ann['posted_by_display'] ?: $ann['posted_by_name']) ?></span>
          <span><i class="fas fa-clock" style="margin-right:4px"></i><?= date('d M Y, h:i A', strtotime($ann['created_at'])) ?></span>
          <?= $targetLabel ?>
          <?php if (hasRole('admin','hr') && isset($ann['read_count'])): ?>
          <span><i class="fas fa-eye" style="margin-right:4px"></i><?= $ann['read_count'] ?> read</span>
          <?php endif; ?>
          <?php if ($ann['expires_at']): ?>
          <span style="color:#D97706"><i class="fas fa-calendar-times" style="margin-right:4px"></i>Expires <?= date('d M Y',strtotime($ann['expires_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
        <?php if (!$isRead && !hasRole('admin','hr')): ?>
        <button class="btn btn-outline btn-sm" onclick="markRead(<?= $ann['id'] ?>)" title="Mark as read"><i class="fas fa-check"></i></button>
        <?php endif; ?>
        <?php if (hasRole('admin','hr')): ?>
        <button class="btn btn-outline btn-sm" onclick="deleteAnn(<?= $ann['id'] ?>)" title="Remove" style="color:var(--danger);border-color:var(--danger)"><i class="fas fa-trash"></i></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (hasRole('admin','hr')): ?>
<!-- ═══ Post Announcement Modal ═══ -->
<div id="annModal" class="modal-overlay">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div class="modal-icon primary"><i class="fas fa-bullhorn"></i></div>
      <div class="modal-title-wrap"><h3 class="modal-title">Post Announcement</h3><div class="modal-subtitle">Notify all employees or target specific groups</div></div>
      <button class="modal-close-btn" onclick="closeModal('annModal')"><i class="fas fa-times"></i></button>
    </div>
    <form id="annForm">
      <input type="hidden" name="action" value="post">
      <div class="modal-body">
        <div class="form-group">
          <label>Title *</label>
          <input type="text" name="title" class="form-control" required maxlength="200" placeholder="e.g. Office closed on Friday, Team outing next week…">
        </div>
        <div class="form-group mt-16">
          <label>Message *</label>
          <textarea name="body" class="form-control" required rows="5" placeholder="Write the full announcement here…"></textarea>
        </div>
        <div class="form-grid form-grid-2 mt-16">
          <div class="form-group">
            <label>Priority</label>
            <select name="priority" class="form-control">
              <option value="normal">Normal</option>
              <option value="important">⚠️ Important</option>
              <option value="urgent">🚨 Urgent</option>
            </select>
          </div>
          <div class="form-group">
            <label>Expires On <span style="color:var(--text-light);font-weight:400;font-size:11px">(optional)</span></label>
            <input type="date" name="expires_at" class="form-control" min="<?= date('Y-m-d') ?>">
          </div>
        </div>

        <!-- Target audience -->
        <div class="form-group mt-16">
          <label>Send To</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:8px">
            <label style="cursor:pointer;display:block">
              <input type="radio" name="target_type" value="all" checked onchange="toggleTarget(this.value)" style="display:none" id="t_all">
              <div class="target-chip" id="chip_all" style="border:2px solid var(--primary);background:var(--primary-light);border-radius:10px;padding:12px;text-align:center">
                <i class="fas fa-globe" style="color:var(--primary);font-size:18px;margin-bottom:4px;display:block"></i>
                <div style="font-weight:700;font-size:13px;color:var(--primary)">All Employees</div>
                <div style="font-size:11px;color:var(--text-light)"><?= count($employees_list) ?> people</div>
              </div>
            </label>
            <label style="cursor:pointer;display:block">
              <input type="radio" name="target_type" value="department" onchange="toggleTarget(this.value)" style="display:none" id="t_dept">
              <div class="target-chip" id="chip_dept" style="border:2px solid var(--border);border-radius:10px;padding:12px;text-align:center;transition:all .2s">
                <i class="fas fa-sitemap" style="color:#7C3AED;font-size:18px;margin-bottom:4px;display:block"></i>
                <div style="font-weight:700;font-size:13px">A Department</div>
                <div style="font-size:11px;color:var(--text-light)"><?= count($departments) ?> departments</div>
              </div>
            </label>
            <label style="cursor:pointer;display:block">
              <input type="radio" name="target_type" value="individual" onchange="toggleTarget(this.value)" style="display:none" id="t_ind">
              <div class="target-chip" id="chip_ind" style="border:2px solid var(--border);border-radius:10px;padding:12px;text-align:center;transition:all .2s">
                <i class="fas fa-user" style="color:#059669;font-size:18px;margin-bottom:4px;display:block"></i>
                <div style="font-weight:700;font-size:13px">One Employee</div>
                <div style="font-size:11px;color:var(--text-light)">Individual</div>
              </div>
            </label>
          </div>
        </div>

        <div id="targetSelectWrap" style="display:none;margin-top:12px">
          <div class="form-group">
            <label id="targetSelectLabel">Select Target</label>
            <select name="target_id" id="targetSelect" class="form-control">
              <option value="">— Select —</option>
            </select>
          </div>
        </div>

        <!-- Preview banner -->
        <div id="previewBanner" style="background:var(--primary-light);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-top:14px;font-size:13px;color:var(--primary)">
          <i class="fas fa-paper-plane"></i>
          <span id="previewText">This announcement will be sent to <strong>all employees</strong> and they will receive an in-app notification.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('annModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post Announcement</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// Departments and employees for the target selector
const deptOptions = <?= json_encode(array_map(fn($d)=>['id'=>$d['id'],'name'=>$d['department_name']],$departments)) ?>;
const empOptions  = <?= json_encode(array_map(fn($e)=>['id'=>$e['id'],'name'=>$e['name'].' ('.$e['employee_id'].')'.' — '.($e['department_name']??'—')],$employees_list)) ?>;

function toggleTarget(val) {
  const wrap  = document.getElementById('targetSelectWrap');
  const sel   = document.getElementById('targetSelect');
  const label = document.getElementById('targetSelectLabel');
  const prev  = document.getElementById('previewText');

  // Highlight selected chip
  ['all','dept','ind'].forEach(k => {
    const chip = document.getElementById('chip_'+k);
    if(!chip) return;
    chip.style.borderColor = 'var(--border)';
    chip.style.background  = '';
  });

  if (val === 'all') {
    document.getElementById('chip_all').style.borderColor = 'var(--primary)';
    document.getElementById('chip_all').style.background  = 'var(--primary-light)';
    wrap.style.display = 'none';
    if (prev) prev.innerHTML = 'This announcement will be sent to <strong>all employees</strong>.';
  } else if (val === 'department') {
    document.getElementById('chip_dept').style.borderColor = '#7C3AED';
    document.getElementById('chip_dept').style.background  = '#F3E8FF';
    label.textContent = 'Select Department';
    sel.innerHTML = '<option value="">— Select Department —</option>' +
      deptOptions.map(d=>`<option value="${d.id}">${d.name}</option>`).join('');
    wrap.style.display = 'block';
    if (prev) prev.innerHTML = 'This will be sent to <strong>all employees in the selected department</strong>.';
  } else {
    document.getElementById('chip_ind').style.borderColor = '#059669';
    document.getElementById('chip_ind').style.background  = '#ECFDF5';
    label.textContent = 'Select Employee';
    sel.innerHTML = '<option value="">— Select Employee —</option>' +
      empOptions.map(e=>`<option value="${e.id}">${e.name}</option>`).join('');
    wrap.style.display = 'block';
    if (prev) prev.innerHTML = 'This will be sent to the <strong>selected individual employee</strong> only.';
  }
}

document.getElementById('annForm')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const r  = await fetch('', {method:'POST', body:fd});
  const d  = await r.json();
  showToast(d.message, d.success?'success':'error');
  if (d.success) { closeModal('annModal'); setTimeout(()=>location.reload(),1000); }
});

async function markRead(annId) {
  const fd = new FormData(); fd.append('action','mark_read'); fd.append('ann_id', annId);
  await fetch('', {method:'POST', body:fd});
  const card = document.getElementById('ann_' + annId);
  if (card) {
    card.querySelector('.btn')?.remove();
    // Remove "NEW" badge
    card.querySelectorAll('span').forEach(s => { if(s.textContent.trim()==='NEW') s.remove(); });
  }
  showToast('Marked as read','success');
}

async function deleteAnn(id) {
  confirmDialog('Remove this announcement? It will no longer be visible to employees.', async () => {
    const fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
    const r  = await fetch('',{method:'POST',body:fd});
    const d  = await r.json();
    showToast(d.message,'success');
    if(d.success) {
      const card = document.getElementById('ann_'+id);
      if(card) { card.style.opacity='0'; card.style.transform='scale(.97)'; setTimeout(()=>card.remove(),300); }
    }
  }, 'Remove Announcement');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
