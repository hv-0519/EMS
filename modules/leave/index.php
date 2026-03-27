<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle   = 'Leave Management';
$currentPage = 'leave';
$pdo         = getDB();
$me          = getCurrentEmployee();
ensureLeaveSchemaSupportHalfDay();

// ── Actions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    if ($action === 'apply' && $me) {
        $type   = $_POST['leave_type'] ?? 'casual';
        $durationType = $_POST['duration_type'] ?? 'full_day';
        $start  = $_POST['start_date'] ?? '';
        $end    = $_POST['end_date']   ?? '';
        $reason = trim($_POST['reason'] ?? '');
        if (!$start || !$end || strtotime($end) < strtotime($start)) {
            echo json_encode(['success'=>false,'message'=>'Invalid dates selected.']); exit;
        }
        if ($durationType === 'half_day' && $start !== $end) {
            echo json_encode(['success'=>false,'message'=>'Half-day leave can only be applied for a single date.']); exit;
        }
        $units = calculateLeaveUnits($start, $end, $durationType);

        $bal      = getLeaveBalance($me['id']);
        $usedKey  = match($type) { 'sick'=>'sick_used',  'paid'=>'paid_used',  default=>'casual_used'  };
        $quotaKey = match($type) { 'sick'=>'sick_quota', 'paid'=>'paid_quota', default=>'casual_quota' };
        $remaining = (float)($bal[$quotaKey] ?? 10) - (float)($bal[$usedKey] ?? 0);
        if ($units > $remaining + 0.0001) {
            echo json_encode(['success'=>false,'message'=>"Insufficient balance. You have ".rtrim(rtrim(number_format($remaining,1,'.',''),'0'),'.')." day(s) of ".ucfirst($type)." leave remaining."]); exit;
        }
        if (columnExists('leave_requests', 'duration_type')) {
            $pdo->prepare('INSERT INTO leave_requests (employee_id,leave_type,duration_type,start_date,end_date,reason) VALUES(?,?,?,?,?,?)')->execute([$me['id'],$type,$durationType,$start,$end,$reason]);
        } else {
            $pdo->prepare('INSERT INTO leave_requests (employee_id,leave_type,start_date,end_date,reason) VALUES(?,?,?,?,?)')->execute([$me['id'],$type,$start,$end,$reason]);
        }
        $labelUnits = rtrim(rtrim(number_format($units,1,'.',''),'0'),'.');
        $hrUsers = $pdo->query("SELECT id FROM users WHERE role IN('admin','hr')")->fetchAll();
        foreach ($hrUsers as $hu) {
            $pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES(?,?,?,?)')->execute([$hu['id'],'New Leave Request',"{$me['name']} applied for $type leave ({$labelUnits} day".($units>1?'s':'').").",'info']);
        }
        logActivity('Applied for leave',"{$me['name']} applied for $type leave ({$labelUnits} days)",'leave');
        echo json_encode(['success'=>true,'message'=>'Leave request submitted successfully.']); exit;
    }

    if (($action==='approve'||$action==='reject') && hasRole('admin','hr')) {
        $id     = (int)$_POST['id'];
        $status = $action==='approve' ? 'approved' : 'rejected';
        $lr     = $pdo->prepare('SELECT lr.*,e.user_id,e.name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id WHERE lr.id=?');
        $lr->execute([$id]); $lr = $lr->fetch();
        $pdo->prepare('UPDATE leave_requests SET status=?,reviewed_by=? WHERE id=?')->execute([$status,$_SESSION['user_id'],$id]);
        if ($lr) {
            $durationType = $lr['duration_type'] ?? 'full_day';
            $units = calculateLeaveUnits($lr['start_date'], $lr['end_date'], (string)$durationType);
            if ($action==='approve') deductLeaveBalance($lr['employee_id'],$lr['leave_type'],$units);
            elseif ($lr['status']==='approved') refundLeaveBalance($lr['employee_id'],$lr['leave_type'],$units);
            $pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES(?,?,?,?)')->execute([$lr['user_id'],'Leave '.ucfirst($status),"Your {$lr['leave_type']} leave has been $status.",$action==='approve'?'success':'error']);
            logActivity(ucfirst($action).' leave',"Leave #{$id} for {$lr['name']} $status",'leave');
        }
        echo json_encode(['success'=>true,'message'=>'Leave '.$status]); exit;
    }

    if ($action==='delete' && hasRole('admin','hr')) {
        $pdo->prepare('DELETE FROM leave_requests WHERE id=?')->execute([(int)$_POST['id']]);
        echo json_encode(['success'=>true,'message'=>'Record deleted']); exit;
    }
    exit;
}

// ── Data ────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';
$page    = max(1,(int)($_GET['page']??1));
$perPage = 12; $offset = ($page-1)*$perPage;

$where  = 'WHERE 1=1'; $params = [];
if ($_SESSION['role']==='employee' && $me) { $where .= ' AND lr.employee_id=?'; $params[] = $me['id']; }
if ($filterStatus) { $where .= ' AND lr.status=?'; $params[] = $filterStatus; }
if ($filterType)   { $where .= ' AND lr.leave_type=?'; $params[] = $filterType; }

$myBalance = null;
if ($me) { ensureLeaveBalance($me['id']); $myBalance = getLeaveBalance($me['id']); }

// Pending count for HR/admin badge
$pendingCount = 0;
if (hasRole('admin','hr')) {
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests lr $where"); $countStmt->execute($params);
$total = $countStmt->fetchColumn(); $pages = ceil($total/$perPage) ?: 1;

$stmt = $pdo->prepare("SELECT lr.*,e.name,e.employee_id AS emp_id,d.department_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id LEFT JOIN departments d ON d.id=e.department_id $where ORDER BY lr.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params); $leaves = $stmt->fetchAll();

// Stats for employee
$myStats = [];
if ($me && $_SESSION['role']==='employee') {
    $st = $pdo->prepare("SELECT status,COUNT(*) as cnt FROM leave_requests WHERE employee_id=? AND YEAR(start_date)=YEAR(CURDATE()) GROUP BY status");
    $st->execute([$me['id']]);
    foreach ($st->fetchAll() as $r) $myStats[$r['status']] = $r['cnt'];
}

// Upcoming approved leaves for employee
$upcoming = [];
if ($me && $_SESSION['role']==='employee') {
    $up = $pdo->prepare("SELECT * FROM leave_requests WHERE employee_id=? AND status='approved' AND end_date>=CURDATE() ORDER BY start_date LIMIT 3");
    $up->execute([$me['id']]); $upcoming = $up->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="leave-page">
<div class="page-header">
  <div>
    <h1>Leave Management</h1>
    <p style="color:var(--text-light);font-size:13px">Track, apply and manage employee leaves</p>
  </div>
  <div class="actions">
    <?php if ($_SESSION['role']==='employee'): ?>
    <button class="btn btn-primary" onclick="openModal('applyLeaveModal')">
      <i class="fas fa-plus"></i> Apply for Leave
    </button>
    <?php endif; ?>
    <?php if (hasRole('admin','hr') && $pendingCount > 0): ?>
    <span class="badge badge-pending" style="padding:8px 14px;font-size:13px">
      <i class="fas fa-clock"></i> <?= $pendingCount ?> pending review
    </span>
    <?php endif; ?>
  </div>
</div>

<?php if ($myBalance && $_SESSION['role']==='employee'): ?>
<!-- ── EMPLOYEE DASHBOARD ── -->
<div class="leave-balance-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <?php
  $leaveTypes = [
    ['Casual Leave',  'casual', '#5B6EF5', 'fas fa-sun',        'bg:#EEF2FF'],
    ['Sick Leave',    'sick',   '#EF4444', 'fas fa-heartbeat',  'bg:#FEF2F2'],
    ['Paid Leave',    'paid',   '#10B981', 'fas fa-plane',      'bg:#ECFDF5'],
  ];
  foreach ($leaveTypes as [$ln,$lk,$lc,$icon,$bg]):
    $used  = (float)($myBalance[$lk.'_used']  ?? 0);
    $quota = (float)($myBalance[$lk.'_quota'] ?? ($lk==='paid'?15:10));
    $rem   = max(0, $quota - $used);
    $pct   = $quota>0 ? min(100,round($used/$quota*100)) : 0;
    $bgColor = str_replace('bg:','',$bg);
  ?>
  <div class="card" style="margin:0;border-left:4px solid <?= $lc ?>">
    <div style="padding:18px 20px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:36px;height:36px;background:<?= $bgColor ?>;border-radius:10px;display:flex;align-items:center;justify-content:center">
            <i class="<?= $icon ?>" style="color:<?= $lc ?>;font-size:14px"></i>
          </div>
          <span style="font-weight:700;font-size:14px;color:var(--text-dark)"><?= $ln ?></span>
        </div>
        <span style="font-size:22px;font-weight:800;color:<?= $rem>0?$lc:'var(--danger)' ?>"><?= rtrim(rtrim(number_format($rem,1,'.',''),'0'),'.') ?></span>
      </div>
      <div style="background:var(--secondary);border-radius:999px;height:7px;overflow:hidden;margin-bottom:8px">
        <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct>=90?'#EF4444':$lc ?>;border-radius:999px;transition:width .4s"></div>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--text-light)">
        <span><?= rtrim(rtrim(number_format($used,1,'.',''),'0'),'.') ?> used of <?= rtrim(rtrim(number_format($quota,1,'.',''),'0'),'.') ?> days</span>
        <span style="font-weight:600"><?= rtrim(rtrim(number_format($rem,1,'.',''),'0'),'.') ?> remaining</span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Stats + Upcoming row -->
<div class="leave-insight-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
  <!-- Year stats -->
  <div class="card" style="margin:0">
    <div style="padding:18px 20px">
      <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;color:var(--text-dark)">
        <i class="fas fa-chart-pie" style="color:var(--primary);margin-right:8px"></i>This Year's Summary
      </h3>
      <div class="leave-year-stats-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <?php foreach([['Approved','approved','#10B981'],['Pending','pending','#F59E0B'],['Rejected','rejected','#EF4444']] as [$l,$k,$c]): ?>
        <div style="text-align:center;background:var(--secondary);border-radius:10px;padding:12px 8px">
          <div style="font-size:24px;font-weight:800;color:<?= $c ?>"><?= $myStats[$k]??0 ?></div>
          <div style="font-size:11px;color:var(--text-light);margin-top:2px"><?= $l ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Upcoming leaves -->
  <div class="card" style="margin:0">
    <div style="padding:18px 20px">
      <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;color:var(--text-dark)">
        <i class="fas fa-calendar-check" style="color:var(--primary);margin-right:8px"></i>Upcoming Approved Leaves
      </h3>
      <?php if ($upcoming): foreach ($upcoming as $ul):
        $udays = calculateLeaveUnits($ul['start_date'], $ul['end_date'], (string)($ul['duration_type'] ?? 'full_day'));
        $typeColors = ['sick'=>'#EF4444','paid'=>'#10B981','casual'=>'#5B6EF5'];
        $tc = $typeColors[$ul['leave_type']] ?? '#5B6EF5';
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border)">
        <div style="width:8px;height:8px;border-radius:50%;background:<?= $tc ?>;flex-shrink:0"></div>
        <div style="flex:1">
          <div style="font-size:13px;font-weight:600;color:var(--text-dark)"><?= ucfirst($ul['leave_type']) ?> Leave</div>
          <div style="font-size:11.5px;color:var(--text-light)"><?= date('M d',strtotime($ul['start_date'])) ?> – <?= date('M d, Y',strtotime($ul['end_date'])) ?> · <?= rtrim(rtrim(number_format($udays,1,'.',''),'0'),'.') ?> day<?= $udays>1?'s':'' ?></div>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div style="text-align:center;color:var(--text-light);font-size:13px;padding:12px 0">
        <i class="fas fa-check-circle" style="color:#10B981;font-size:20px;display:block;margin-bottom:6px"></i>
        No upcoming approved leaves
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── LEAVE REQUESTS TABLE ── -->
<div class="card">
  <div class="card-header">
    <h2>
      <?= hasRole('admin','hr') ? 'All Leave Requests' : 'My Leave Requests' ?>
      <?php if ($total > 0): ?>
      <span style="font-size:12px;color:var(--text-light);font-weight:400;margin-left:8px">(<?= $total ?> total)</span>
      <?php endif; ?>
    </h2>
    <div style="display:flex;gap:8px">
      <a href="<?= APP_URL ?>/modules/leave/update_balance.php" class="btn btn-outline btn-sm" title="Update balances">
        <i class="fas fa-sync"></i>
      </a>
    </div>
  </div>
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
      <select name="status" class="filter-select" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="pending"  <?= $filterStatus==='pending' ?'selected':''?>>Pending</option>
        <option value="approved" <?= $filterStatus==='approved'?'selected':''?>>Approved</option>
        <option value="rejected" <?= $filterStatus==='rejected'?'selected':''?>>Rejected</option>
      </select>
      <select name="type" class="filter-select" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="casual" <?= $filterType==='casual'?'selected':''?>>Casual</option>
        <option value="sick"   <?= $filterType==='sick'  ?'selected':''?>>Sick</option>
        <option value="paid"   <?= $filterType==='paid'  ?'selected':''?>>Paid</option>
      </select>
    </form>
    <span style="font-size:13px;color:var(--text-light)"><?= $total ?> request<?= $total!=1?'s':'' ?></span>
  </div>

  <?php if (!$leaves): ?>
  <div style="text-align:center;padding:60px 20px">
    <div style="width:72px;height:72px;background:var(--secondary);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
      <i class="fas fa-calendar-xmark" style="font-size:28px;color:var(--text-light)"></i>
    </div>
    <h3 style="color:var(--text-mid);margin-bottom:6px">No leave requests found</h3>
    <p style="color:var(--text-light);font-size:13px">
      <?= $_SESSION['role']==='employee' ? 'You haven\'t applied for any leaves yet.' : 'No leave requests match the current filters.' ?>
    </p>
    <?php if ($_SESSION['role']==='employee'): ?>
    <button class="btn btn-primary" onclick="openModal('applyLeaveModal')" style="margin-top:14px">
      <i class="fas fa-plus"></i> Apply for Leave
    </button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="data-table-wrap table-responsive">
    <table class="data-table">
      <thead>
        <tr>
          <?php if (hasRole('admin','hr')): ?><th>Employee</th><?php endif; ?>
          <th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Applied</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leaves as $lv):
          $days = calculateLeaveUnits($lv['start_date'], $lv['end_date'], (string)($lv['duration_type'] ?? 'full_day'));
          $typeStyle = match($lv['leave_type']) {
            'sick'   => 'background:#FEE2E2;color:#991B1B',
            'paid'   => 'background:#D1FAE5;color:#065F46',
            default  => 'background:#EEF2FF;color:#3730A3'
          };
          $statusCls = ['pending'=>'badge-pending','approved'=>'badge-active','rejected'=>'badge-inactive','cancelled'=>'badge-leave'][$lv['status']] ?? 'badge-pending';
        ?>
        <tr>
          <?php if (hasRole('admin','hr')): ?>
          <td>
            <div class="emp-cell">
              <div class="emp-avatar"><?= strtoupper(substr($lv['name'],0,1)) ?></div>
              <div>
                <div class="emp-name"><?= htmlspecialchars($lv['name']) ?></div>
                <div class="emp-id"><?= htmlspecialchars($lv['emp_id']) ?> <?= isset($lv['department_name']) ? '· '.htmlspecialchars($lv['department_name']) : '' ?></div>
              </div>
            </div>
          </td>
          <?php endif; ?>
          <td><span class="badge" style="<?= $typeStyle ?>"><?= ucfirst($lv['leave_type']) ?></span></td>
          <td><?= date('d M Y',strtotime($lv['start_date'])) ?></td>
          <td><?= date('d M Y',strtotime($lv['end_date'])) ?></td>
          <td><strong><?= rtrim(rtrim(number_format($days,1,'.',''),'0'),'.') ?></strong> day<?= $days>1?'s':'' ?><?= (($lv['duration_type'] ?? 'full_day')==='half_day') ? ' (Half)' : '' ?></td>
          <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($lv['reason']) ?>">
            <?= htmlspecialchars($lv['reason'] ?: '—') ?>
          </td>
          <td style="font-size:12px;color:var(--text-light)"><?= date('d M',strtotime($lv['created_at'])) ?></td>
          <td><span class="badge <?= $statusCls ?>"><?= ucfirst($lv['status']) ?></span></td>
          <td>
            <div class="action-btns">
              <?php if (hasRole('admin','hr') && $lv['status']==='pending'): ?>
              <button class="btn-action" title="Approve" onclick="reviewLeave(<?= $lv['id'] ?>,'approve')" style="color:var(--success);border-color:var(--success)"><i class="fas fa-check"></i></button>
              <button class="btn-action danger" title="Reject" onclick="reviewLeave(<?= $lv['id'] ?>,'reject')"><i class="fas fa-times"></i></button>
              <?php elseif (hasRole('admin','hr')): ?>
              <button class="btn-action danger" title="Delete" onclick="deleteLeave(<?= $lv['id'] ?>)"><i class="fas fa-trash"></i></button>
              <?php else: ?>
              <span style="font-size:12px;color:var(--text-light)">—</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <span class="page-info">Page <?= $page ?> of <?= $pages ?></span>
    <div class="page-controls">
      <?php for ($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?>
      <a href="?page=<?= $p ?>&status=<?= $filterStatus ?>&type=<?= $filterType ?>" class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ── APPLY LEAVE MODAL ── -->
<div id="applyLeaveModal" class="modal-overlay">
  <div class="modal-dialog modal-dialog-sm">
    <div class="modal-header">
      <div class="modal-icon primary"><i class="fas fa-calendar-plus"></i></div>
      <div class="modal-title-wrap"><h3 class="modal-title">Apply for Leave</h3><div class="modal-subtitle">Submit a new leave request</div></div>
      <button class="modal-close-btn" data-close-modal><i class="fas fa-times"></i></button>
    </div>
    <form id="leaveForm">
      <input type="hidden" name="action" value="apply">
      <div class="modal-body">
        <div class="form-group">
          <label>Leave Type</label>
          <select name="leave_type" id="leaveTypeSelect" class="form-control" onchange="updateBalance()">
            <option value="casual">Casual Leave</option>
            <option value="sick">Sick Leave</option>
            <option value="paid">Paid Leave</option>
          </select>
        </div>
        <div class="form-group">
          <label>Duration</label>
          <select name="duration_type" id="leaveDurationSelect" class="form-control" onchange="calcDays()">
            <option value="full_day">Full Day</option>
            <option value="half_day">Half Day</option>
          </select>
        </div>
        <!-- Live balance preview -->
        <div id="balancePreview" style="background:var(--secondary);border-radius:8px;padding:10px 14px;font-size:12.5px;margin-bottom:12px">
          <span id="balanceText">Loading balance...</span>
        </div>
        <div class="form-grid form-grid-2 mt-0">
          <div class="form-group">
            <label>From Date</label>
            <input type="date" name="start_date" id="leaveStart" class="form-control" required min="<?= date('Y-m-d') ?>" onchange="calcDays()">
          </div>
          <div class="form-group">
            <label>To Date</label>
            <input type="date" name="end_date" id="leaveEnd" class="form-control" required min="<?= date('Y-m-d') ?>" onchange="calcDays()">
          </div>
        </div>
        <div id="daysPreview" style="display:none;background:var(--primary-light);border-radius:8px;padding:8px 14px;font-size:12.5px;color:var(--primary);margin-bottom:12px;font-weight:600">
          <i class="fas fa-calendar-day"></i> <span id="daysCount">0</span> day(s) selected
        </div>
        <div class="form-group">
          <label>Reason <span style="color:var(--text-light);font-weight:400">(optional)</span></label>
          <textarea name="reason" class="form-control" placeholder="Brief reason for leave…" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
      </div>
    </form>
  </div>
</div>

<script>
// Leave balance data from PHP
const leaveBalances = {
  casual: { used: <?= (float)($myBalance['casual_used'] ?? 0) ?>, quota: <?= (float)($myBalance['casual_quota'] ?? 10) ?> },
  sick:   { used: <?= (float)($myBalance['sick_used']   ?? 0) ?>, quota: <?= (float)($myBalance['sick_quota']   ?? 10) ?> },
  paid:   { used: <?= (float)($myBalance['paid_used']   ?? 0) ?>, quota: <?= (float)($myBalance['paid_quota']   ?? 15) ?> },
};

const formatLeaveNumber = (n) => {
  const v = Number(n || 0);
  return Number.isInteger(v) ? String(v) : v.toFixed(1).replace(/\.0$/, '');
};

function updateBalance() {
  const type = document.getElementById('leaveTypeSelect')?.value || 'casual';
  const b    = leaveBalances[type] || { used:0, quota:10 };
  const rem  = Math.max(0, b.quota - b.used);
  const el   = document.getElementById('balanceText');
  if (el) el.innerHTML = `<i class="fas fa-info-circle" style="color:var(--primary)"></i> <strong>${formatLeaveNumber(rem)}</strong> day${rem!==1?'s':''} of ${type.charAt(0).toUpperCase()+type.slice(1)} leave available <span style="color:var(--text-light)">(${formatLeaveNumber(b.used)} used of ${formatLeaveNumber(b.quota)})</span>`;
}

function calcDays() {
  const s = document.getElementById('leaveStart')?.value;
  const e = document.getElementById('leaveEnd')?.value;
  const dType = document.getElementById('leaveDurationSelect')?.value || 'full_day';
  const dp = document.getElementById('daysPreview');
  if (s && e) {
    let diff = Math.round((new Date(e) - new Date(s)) / 86400000) + 1;
    if (dType === 'half_day') diff = 0.5;
    if (diff > 0) {
      document.getElementById('daysCount').textContent = formatLeaveNumber(diff);
      if (dp) dp.style.display = 'block';
      return;
    }
  }
  if (dp) dp.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
  updateBalance();
  document.getElementById('leaveForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const r  = await fetch('', {method:'POST',body:fd});
    const d  = await r.json();
    showToast(d.message, d.success?'success':'error');
    if (d.success) { closeModal('applyLeaveModal'); setTimeout(()=>location.reload(),800); }
  });
});

async function reviewLeave(id, action) {
  const msg = action==='approve' ? 'Approve this leave request?' : 'Reject this leave request?';
  confirmDialog(msg, async () => {
    const fd = new FormData(); fd.append('action',action); fd.append('id',id);
    const r = await fetch('',{method:'POST',body:fd});
    const d = await r.json();
    showToast(d.message, d.success?'success':'error');
    if (d.success) setTimeout(()=>location.reload(),800);
  }, action==='approve' ? 'Approve Leave':'Reject Leave');
}

async function deleteLeave(id) {
  confirmDialog('Delete this leave record?', async () => {
    const fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
    const r = await fetch('',{method:'POST',body:fd});
    const d = await r.json();
    showToast(d.message,'success'); if(d.success)setTimeout(()=>location.reload(),800);
  });
}
</script>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
