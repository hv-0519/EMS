<?php
// modules/employees/profile.php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo = getDB();

// Determine which profile to show
$isOwnProfile = false;
if ($_SESSION['role'] === 'employee') {
    $me = getCurrentEmployee();
    $id = $me['id'] ?? 0;
    $isOwnProfile = true;
} else {
    $id = (int)($_GET['id'] ?? 0);
    $me = getCurrentEmployee();
    if ($id === 0 && $me) { $id = $me['id']; $isOwnProfile = true; }
}

$stmt = $pdo->prepare('
    SELECT e.*, d.department_name, u.username, u.avatar AS user_avatar
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.id = ?
');
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) { setFlash('danger','Employee not found.'); redirect(APP_URL . '/dashboard/dashboard.php'); }

// ── Self-service update ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'self_update' && ($isOwnProfile || hasRole('admin','hr'))) {
        $phone   = trim($_POST['phone']   ?? '');
        $address = trim($_POST['address'] ?? '');
        $photo   = $emp['photo'];

        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
            $ext   = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allow = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext,$allow) && $_FILES['photo']['size'] < 2*1024*1024) {
                $fname = 'emp_'.uniqid().'.'.$ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR.$fname)) {
                    if ($photo && $photo !== 'default.png' && file_exists(UPLOAD_DIR.$photo)) @unlink(UPLOAD_DIR.$photo);
                    $photo = $fname;
                }
            }
        }
        $pdo->prepare('UPDATE employees SET phone=?,address=?,photo=? WHERE id=?')
            ->execute([$phone, $address, $photo, $id]);

        logActivity('Updated profile', "{$emp['name']} updated their profile", 'employees');
        setFlash('success','Profile updated successfully.');
        redirect(APP_URL . '/modules/employees/profile.php'.($isOwnProfile?'':"?id=$id"));
    }
}

// ── Page data ────────────────────────────────────────────────
$today = date('Y-m-d');
$month = date('Y-m');
$year  = (int)date('Y');

$attStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND DATE_FORMAT(date,'%Y-%m')=? AND status='present'");
$attStmt->execute([$emp['id'],$month]); $monthAtt = $attStmt->fetchColumn();

$todayRec = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND date=?');
$todayRec->execute([$emp['id'],$today]); $todayRec = $todayRec->fetch();

ensureLeaveBalance($emp['id'], $year);
$balance = getLeaveBalance($emp['id'], $year);

$recLeaves = $pdo->prepare('SELECT * FROM leave_requests WHERE employee_id=? ORDER BY created_at DESC LIMIT 5');
$recLeaves->execute([$emp['id']]); $recLeaves = $recLeaves->fetchAll();

$latestPay = $pdo->prepare('SELECT * FROM payroll WHERE employee_id=? ORDER BY payment_date DESC LIMIT 1');
$latestPay->execute([$emp['id']]); $latestPay = $latestPay->fetch();

$recAtt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? ORDER BY date DESC LIMIT 7');
$recAtt->execute([$emp['id']]); $recAtt = $recAtt->fetchAll();

$leaveRemainingTotal = max(0, (int)($balance['casual_quota'] ?? 10) - (int)($balance['casual_used'] ?? 0))
    + max(0, (int)($balance['sick_quota'] ?? 10) - (int)($balance['sick_used'] ?? 0))
    + max(0, (int)($balance['paid_quota'] ?? 15) - (int)($balance['paid_used'] ?? 0));
$todayStatus = $todayRec ? ($todayRec['check_out'] ? 'Done' : 'Checked In') : 'Not Marked';
$joinedOn = $emp['joining_date'] ? date('M d, Y', strtotime($emp['joining_date'])) : '—';
$profileAvatarUrl = APP_URL . '/assets/img/avatar-placeholder.svg';
$profilePhoto = trim((string)($emp['photo'] ?? ''));
if ($profilePhoto === '' || strtolower($profilePhoto) === 'default.png') {
    $profilePhoto = trim((string)($emp['user_avatar'] ?? ''));
}
if ($profilePhoto !== '' && strtolower($profilePhoto) !== 'default.png') {
    if (filter_var($profilePhoto, FILTER_VALIDATE_URL)) {
        $profileAvatarUrl = $profilePhoto;
    } else {
        $candidateName = basename($profilePhoto);
        $candidatePath = UPLOAD_DIR . $candidateName;
        if ($candidateName !== '' && $candidateName !== '.' && $candidateName !== '..' && file_exists($candidatePath)) {
            $profileAvatarUrl = UPLOAD_URL . rawurlencode($candidateName) . '?v=' . filemtime($candidatePath);
        } else if ($candidateName !== '' && $candidateName !== '.' && $candidateName !== '..') {
            $profileAvatarUrl = UPLOAD_URL . rawurlencode($candidateName);
        }
    }
}

$pageTitle   = $emp['name'];
$currentPage = 'employees';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="employee-profile-view">
  <!-- Profile Hero -->
  <div class="card profile-shell">
    <div class="profile-hero">
      <div class="profile-hero-top">
        <div class="profile-avatar-wrap">
          <img src="<?= htmlspecialchars($profileAvatarUrl) ?>" alt="avatar" onerror="this.onerror=null;this.src='<?= APP_URL ?>/assets/img/avatar-placeholder.svg';">
        </div>
        <div class="profile-hero-content">
          <div class="profile-overline">Employee Profile</div>
          <h2><?= htmlspecialchars($emp['name']) ?></h2>
          <div class="profile-hero-subtitle">
            <span><?= htmlspecialchars($emp['designation']??'—') ?></span>
            <span class="dot"></span>
            <span><?= htmlspecialchars($emp['department_name']??'No Department') ?></span>
          </div>
          <div class="profile-chip-row">
            <span class="profile-chip"><i class="fas fa-id-card"></i><?= htmlspecialchars($emp['employee_id']) ?></span>
            <span class="profile-chip"><i class="fas fa-circle-check"></i><?= ucfirst(str_replace('_',' ',$emp['status'])) ?></span>
            <span class="profile-chip"><i class="fas fa-calendar"></i>Joined <?= $joinedOn ?></span>
          </div>
        </div>
      </div>
      <div class="profile-hero-actions">
        <?php if ($isOwnProfile || hasRole('admin','hr')): ?>
        <button class="btn btn-primary btn-sm" onclick="openModal('editProfileModal')">
          <i class="fas fa-pen"></i> Edit Profile
        </button>
        <?php endif; ?>
        <a href="mailto:<?= htmlspecialchars($emp['email']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-envelope"></i> Send Email</a>
        <?php if (!empty($emp['phone'])): ?>
        <a href="tel:<?= htmlspecialchars($emp['phone']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-phone"></i> Call</a>
        <?php endif; ?>
        <?php if (hasRole('admin','hr') && !$isOwnProfile): ?>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="profile-body">
      <div class="profile-info-row">
        <div class="profile-info-item"><span class="label">Full Name</span><span class="value"><?= htmlspecialchars($emp['name']) ?></span></div>
        <div class="profile-info-item"><span class="label">Email</span><span class="value"><?= htmlspecialchars($emp['email']) ?></span></div>
        <div class="profile-info-item"><span class="label">Phone</span><span class="value"><?= htmlspecialchars($emp['phone']??'—') ?></span></div>
        <div class="profile-info-item"><span class="label">Username</span><span class="value"><?= htmlspecialchars($emp['username']??'—') ?></span></div>
        <div class="profile-info-item"><span class="label">Department</span><span class="value"><?= htmlspecialchars($emp['department_name']??'—') ?></span></div>
        <div class="profile-info-item"><span class="label">Joining Date</span><span class="value"><?= $joinedOn ?></span></div>
        <?php if (hasRole('admin','hr')): ?>
        <div class="profile-info-item"><span class="label">Salary</span><span class="value">₹<?= number_format($emp['salary'],0) ?>/month</span></div>
        <?php endif; ?>
        <div class="profile-info-item profile-info-wide"><span class="label">Address</span><span class="value"><?= htmlspecialchars($emp['address']??'—') ?></span></div>
      </div>
    </div>
  </div>

  <!-- Quick Stats -->
  <div class="stats-grid profile-stats-grid">
    <div class="stat-card profile-stat-card">
      <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $monthAtt ?></div><div class="stat-label">Present (This Month)</div></div>
    </div>
    <div class="stat-card profile-stat-card">
      <div class="stat-icon <?= $todayRec ? 'green' : 'yellow' ?>">
        <i class="fas fa-<?= $todayRec ? 'check-circle' : 'clock' ?>"></i>
      </div>
      <div class="stat-body">
        <div class="stat-value profile-status-value"><?= $todayStatus ?></div>
        <div class="stat-label">Today Attendance</div>
      </div>
    </div>
    <div class="stat-card profile-stat-card">
      <div class="stat-icon blue"><i class="fas fa-user-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= ucfirst(str_replace('_',' ', $emp['status'])) ?></div><div class="stat-label">Employment Status</div></div>
    </div>
    <div class="stat-card profile-stat-card">
      <div class="stat-icon purple"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $leaveRemainingTotal ?></div><div class="stat-label">Total Leave Remaining</div></div>
    </div>
    <?php if ($latestPay): ?>
    <div class="stat-card profile-stat-card">
      <div class="stat-icon blue"><i class="fas fa-rupee-sign"></i></div>
      <div class="stat-body"><div class="stat-value">₹<?= number_format($latestPay['net_salary']/1000,1) ?>K</div><div class="stat-label">Last Salary (<?= $latestPay['month_year'] ?>)</div></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Leave Balance -->
  <div class="card profile-section-card">
    <div class="card-header">
      <h2><i class="fas fa-balance-scale" style="color:var(--primary);margin-right:8px"></i>Leave Balance — <?= $year ?></h2>
      <?php if (hasRole('admin','hr')): ?>
      <button class="btn btn-outline btn-sm" onclick="openModal('editBalanceModal')"><i class="fas fa-pen"></i> Adjust</button>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="profile-leave-grid">
        <?php
        $leaveTypes = [
          ['Casual Leave','casual','#5B6EF5'],
          ['Sick Leave',  'sick',  '#EF4444'],
          ['Paid Leave',  'paid',  '#10B981'],
        ];
        foreach ($leaveTypes as [$lname, $lkey, $lcolor]):
          $used      = (int)($balance[$lkey.'_used']  ?? 0);
          $quota     = (int)($balance[$lkey.'_quota'] ?? ($lkey==='paid'?15:10));
          $remaining = max(0, $quota - $used);
          $pct       = $quota > 0 ? min(100, round($used/$quota*100)) : 0;
        ?>
        <div class="profile-leave-card">
          <div class="profile-leave-head">
            <span class="profile-leave-title"><?= $lname ?></span>
            <span class="profile-leave-total"><?= $quota ?> total</span>
          </div>
          <div class="profile-leave-track">
            <div class="profile-leave-fill" style="width:<?= $pct ?>%;background:<?= $pct>=100?'var(--danger)':$lcolor ?>"></div>
          </div>
          <div class="profile-leave-meta">
            <span><strong style="color:<?= $lcolor ?>"><?= $used ?></strong> used</span>
            <span style="color:<?= $remaining>0?'var(--success)':'var(--danger)' ?>"><?= $remaining ?> remaining</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Attendance + Payslip -->
  <div class="profile-dual-grid <?= $latestPay ? 'with-payslip' : 'single-col' ?>">
    <div class="card profile-section-card">
      <div class="card-header">
        <h2>Recent Attendance</h2>
        <a href="<?= APP_URL ?>/modules/attendance/index.php" class="btn btn-outline btn-sm">All</a>
      </div>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead><tr><th>Date</th><th>In</th><th>Out</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($recAtt as $a): ?>
            <tr>
              <td><?= date('D M d', strtotime($a['date'])) ?></td>
              <td><?= $a['check_in']  ? date('h:i A', strtotime($a['check_in']))  : '—' ?></td>
              <td><?= $a['check_out'] ? date('h:i A', strtotime($a['check_out'])) : '—' ?></td>
              <td><span class="badge badge-<?= $a['status']==='present'?'active':($a['status']==='absent'?'inactive':'leave') ?>"><?= ucfirst($a['status']) ?></span></td>
            </tr>
            <?php endforeach; if (!$recAtt): ?>
            <tr><td colspan="4" class="text-center text-muted" style="padding:24px">No records</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($latestPay): ?>
    <div class="card profile-section-card">
      <div class="card-header">
        <h2>Latest Payslip</h2>
        <a href="<?= APP_URL ?>/modules/payroll/payslip.php?id=<?= $latestPay['id'] ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Print</a>
      </div>
      <div class="card-body">
        <div style="background:linear-gradient(135deg,#5B6EF5,#764BA2);border-radius:10px;padding:20px;color:#fff;margin-bottom:14px;text-align:center">
          <div style="font-size:12px;opacity:.75"><?= $latestPay['month_year'] ?></div>
          <div style="font-size:30px;font-weight:700;margin:4px 0">₹<?= number_format($latestPay['net_salary'],0) ?></div>
          <div style="font-size:12px;opacity:.8">Net Salary</div>
        </div>
        <?php foreach ([['Basic Salary','₹'.number_format($latestPay['basic_salary'],0),'var(--text-mid)'],['Bonus','+₹'.number_format($latestPay['bonus'],0),'var(--success)'],['Deductions','-₹'.number_format($latestPay['deductions'],0),'var(--danger)'],['Paid On',date('M d, Y',strtotime($latestPay['payment_date'])),'var(--text-mid)']] as [$l,$v,$c]): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:13.5px">
          <span style="color:var(--text-light)"><?= $l ?></span>
          <span style="color:<?= $c ?>;font-weight:500"><?= $v ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Leave History -->
  <div class="card profile-section-card">
    <div class="card-header">
      <h2>Leave History</h2>
      <a href="<?= APP_URL ?>/modules/leave/index.php" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="data-table-wrap">
      <table class="data-table">
        <thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($recLeaves as $lv):
            $days=(strtotime($lv['end_date'])-strtotime($lv['start_date']))/86400+1;
          ?>
          <tr>
            <td><span class="badge" style="background:#EEF2FF;color:#3730A3"><?= ucfirst($lv['leave_type']) ?></span></td>
            <td><?= date('M d, Y', strtotime($lv['start_date'])) ?></td>
            <td><?= date('M d, Y', strtotime($lv['end_date'])) ?></td>
            <td><?= $days ?></td>
            <td><span class="badge badge-<?= $lv['status'] ?>"><?= ucfirst($lv['status']) ?></span></td>
          </tr>
          <?php endforeach; if (!$recLeaves): ?>
          <tr><td colspan="5" class="text-center text-muted" style="padding:28px">No leave records</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Self-Service Edit Modal -->
<div id="editProfileModal" class="modal-overlay">
  <div class="modal">
    <div class="modal-header">
      <h3>Edit My Profile</h3>
      <button class="modal-close" data-close-modal><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="self_update">
      <div class="modal-body">
        <div class="form-group">
          <label>Phone Number</label>
          <div class="input-with-icon">
            <i class="fas fa-phone input-icon"></i>
            <input type="text" name="phone" class="form-control"
                   placeholder="+91 9876543210"
                   value="<?= htmlspecialchars($emp['phone']??'') ?>">
          </div>
        </div>
        <div class="form-group mt-16">
          <label>Address</label>
          <textarea name="address" class="form-control" rows="3"
                    placeholder="Your full address…"><?= htmlspecialchars($emp['address']??'') ?></textarea>
        </div>
        <div class="form-group mt-16">
          <label>Profile Photo <span style="color:var(--text-light);font-weight:400">(optional)</span></label>
          <div class="file-upload-area">
            <input type="file" name="photo" accept="image/*" style="display:none">
            <i class="fas fa-camera" style="font-size:28px;color:var(--primary);margin-bottom:8px"></i>
            <p>Click to upload new photo</p>
            <span>JPG, PNG, WEBP · max 2MB</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<?php if (hasRole('admin','hr')): ?>
<!-- Adjust Leave Balance Modal -->
<div id="editBalanceModal" class="modal-overlay">
  <div class="modal modal-sm">
    <div class="modal-header">
      <h3>Adjust Leave Balance</h3>
      <button class="modal-close" data-close-modal><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="<?= APP_URL ?>/modules/leave/update_balance.php">
      <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
      <input type="hidden" name="year" value="<?= $year ?>">
      <input type="hidden" name="redirect" value="<?= APP_URL ?>/modules/employees/profile.php?id=<?= $id ?>">
      <div class="modal-body">
        <div class="form-grid form-grid-2">
          <?php foreach ([['casual_quota','Casual Quota',$balance['casual_quota']??10],['sick_quota','Sick Quota',$balance['sick_quota']??10],['paid_quota','Paid Quota',$balance['paid_quota']??15],['casual_used','Casual Used',$balance['casual_used']??0],['sick_used','Sick Used',$balance['sick_used']??0],['paid_used','Paid Used',$balance['paid_used']??0]] as [$fn,$fl,$fv]): ?>
          <div class="form-group">
            <label><?= $fl ?></label>
            <input type="number" name="<?= $fn ?>" class="form-control" value="<?= $fv ?>" min="0" max="365">
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Balance</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
