<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'hr');

$pageTitle   = 'Payroll';
$currentPage = 'payroll';
$pdo         = getDB();

// ── AJAX Actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    // ── Calculate hours for an employee for a month ──
    if ($action === 'calc_hours') {
        $empId     = (int)$_POST['employee_id'];
        $monthYear = $_POST['month_year'] ?? date('Y-m');

        [$yr, $mo] = explode('-', $monthYear);
        $startDate = "$yr-$mo-01";
        $endDate   = date('Y-m-t', strtotime($startDate));

        // Sum all completed sessions in that month
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, check_in, check_out)), 0) AS total_seconds,
                COUNT(*) AS sessions,
                COUNT(DISTINCT DATE(check_in)) AS work_days
            FROM attendance
            WHERE employee_id = ?
              AND date BETWEEN ? AND ?
              AND check_in IS NOT NULL
              AND check_out IS NOT NULL
        ");
        $stmt->execute([$empId, $startDate, $endDate]);
        $att = $stmt->fetch();

        // Also get employee's hourly rate
        $emp = $pdo->prepare('SELECT name, salary, hourly_rate, employee_id FROM employees WHERE id=?');
        $emp->execute([$empId]); $emp = $emp->fetch();

        $totalHours   = round(($att['total_seconds'] ?? 0) / 3600, 2);
        $hourlyRate   = (float)($emp['hourly_rate'] ?? 0);
        $calculatedPay = round($totalHours * $hourlyRate, 2);

        echo json_encode([
            'success'        => true,
            'total_hours'    => $totalHours,
            'sessions'       => (int)$att['sessions'],
            'work_days'      => (int)$att['work_days'],
            'hourly_rate'    => $hourlyRate,
            'calculated_pay' => $calculatedPay,
            'emp_name'       => $emp['name'] ?? '',
            'emp_id'         => $emp['employee_id'] ?? '',
        ]);
        exit;
    }

    // ── Set / update hourly rate for an employee ──
    if ($action === 'set_hourly_rate') {
        $empId = (int)$_POST['employee_id'];
        $rate  = (float)$_POST['hourly_rate'];
        try {
            $pdo->prepare('UPDATE employees SET hourly_rate=? WHERE id=?')->execute([$rate, $empId]);
            echo json_encode(['success'=>true,'message'=>'Hourly rate updated']);
        } catch (\Throwable $e) {
            // hourly_rate column may not exist — try ALTER TABLE
            try {
                $pdo->exec('ALTER TABLE employees ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT 0.00');
                $pdo->prepare('UPDATE employees SET hourly_rate=? WHERE id=?')->execute([$rate, $empId]);
                echo json_encode(['success'=>true,'message'=>'Hourly rate updated']);
            } catch (\Throwable $e2) {
                echo json_encode(['success'=>false,'message'=>'Could not update hourly rate: '.$e2->getMessage()]);
            }
        }
        exit;
    }

    // ── Generate payroll record ──
    if ($action === 'generate') {
        $empId      = (int)$_POST['employee_id'];
        $basic      = (float)$_POST['basic_salary'];
        $bonus      = (float)($_POST['bonus']      ?? 0);
        $deductions = (float)($_POST['deductions'] ?? 0);
        $net        = $basic + $bonus - $deductions;
        $payDate    = $_POST['payment_date'] ?? date('Y-m-d');
        $monthYear  = date('Y-m', strtotime($payDate));
        $notes      = trim($_POST['notes'] ?? '');
        $totalHours = (float)($_POST['total_hours'] ?? 0);

        $chk = $pdo->prepare('SELECT id FROM payroll WHERE employee_id=? AND month_year=?');
        $chk->execute([$empId, $monthYear]);
        if ($chk->fetch()) {
            echo json_encode(['success'=>false,'message'=>'Payroll already generated for this month. Delete existing record first.']); exit;
        }

        try {
            // Add total_hours column if needed
            $pdo->exec("ALTER TABLE payroll ADD COLUMN IF NOT EXISTS total_hours DECIMAL(8,2) DEFAULT 0.00");
        } catch (\Throwable $e) {}

        $pdo->prepare('INSERT INTO payroll (employee_id,basic_salary,bonus,deductions,net_salary,payment_date,month_year,notes,total_hours) VALUES(?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary)')
            ->execute([$empId, $basic, $bonus, $deductions, $net, $payDate, $monthYear, $notes, $totalHours]);

        $emp = $pdo->prepare('SELECT e.name,u.id FROM employees e JOIN users u ON u.id=e.user_id WHERE e.id=?');
        $emp->execute([$empId]); $emp = $emp->fetch();
        if ($emp) {
            $pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES(?,?,?,?)')
                ->execute([$emp['id'], 'Salary Credited',
                  "Your salary of ₹".number_format($net,0)." for $monthYear has been processed. Hours worked: {$totalHours}h.",
                  'success']);
        }
        logActivity('Generated payroll', "Payroll for emp #{$empId} — {$monthYear} — ₹".number_format($net,0), 'payroll');
        echo json_encode(['success'=>true,'message'=>'Payroll generated successfully']);
        exit;
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM payroll WHERE id=?')->execute([(int)$_POST['id']]);
        echo json_encode(['success'=>true,'message'=>'Record deleted']); exit;
    }
    exit;
}

// ── List ────────────────────────────────────────────────────
$filterMonth = $_GET['month'] ?? date('Y-m');
$page        = max(1,(int)($_GET['page']??1));
$perPage     = 12; $offset = ($page-1)*$perPage;

$where  = 'WHERE 1=1'; $params = [];
if ($filterMonth) { $where .= ' AND p.month_year=?'; $params[] = $filterMonth; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payroll p $where"); $countStmt->execute($params);
$total = $countStmt->fetchColumn(); $pages = ceil($total/$perPage) ?: 1;

$stmt = $pdo->prepare("
    SELECT p.*, e.name, e.employee_id AS emp_id, e.hourly_rate, d.department_name
    FROM payroll p
    JOIN employees e ON e.id=p.employee_id
    LEFT JOIN departments d ON d.id=e.department_id
    $where ORDER BY p.payment_date DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params); $payrolls = $stmt->fetchAll();

$totalNet = array_sum(array_column($payrolls, 'net_salary'));
$totalHoursAll = array_sum(array_column($payrolls, 'total_hours'));

// Load employees with hourly_rate
try {
    $employees = $pdo->query('SELECT id,name,employee_id,salary,hourly_rate FROM employees WHERE status="active" ORDER BY name')->fetchAll();
} catch (\Throwable $e) {
    $employees = $pdo->query('SELECT id,name,employee_id,salary,0 AS hourly_rate FROM employees WHERE status="active" ORDER BY name')->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Payroll</h1>
    <p style="font-size:13px;color:var(--text-light)">Hourly-based salary management — pay = hourly rate × hours worked</p>
  </div>
  <div class="actions">
    <button class="btn btn-outline btn-sm" onclick="openModal('rateModal')"><i class="fas fa-sliders-h"></i> Set Hourly Rates</button>
    <button class="btn btn-primary" onclick="openPayModal()"><i class="fas fa-plus"></i> Generate Payroll</button>
  </div>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
  <?php foreach([
    ['fas fa-file-invoice-dollar','blue',  $total,             'Records This Month'],
    ['fas fa-rupee-sign',         'green', '₹'.number_format($totalNet/1000,1).'K', 'Total Payout'],
    ['fas fa-clock',              'purple',round($totalHoursAll,1).'h', 'Total Hours'],
    ['fas fa-users',              'orange',count($employees),  'Active Employees'],
  ] as [$icon,$color,$val,$label]): ?>
  <div class="stat-card">
    <div class="stat-icon <?= $color ?>"><i class="<?= $icon ?>"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $val ?></div><div class="stat-label"><?= $label ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- How-it-works info bar -->
<div style="background:linear-gradient(135deg,#EFF6FF,#EEF2FF);border:1px solid #C7D2FE;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px">
  <i class="fas fa-info-circle" style="color:#3B82F6;font-size:20px;flex-shrink:0"></i>
  <div style="font-size:13px;color:#1E40AF;line-height:1.6">
    <strong>How hourly payroll works:</strong>
    Each time an employee <strong>logs in</strong>, a session starts. When they <strong>log out</strong>, that session ends.
    All sessions for the month are summed. <strong>Net Pay = Hourly Rate × Total Hours Worked</strong>.
    Accidental logouts do not lose time — each re-login resumes a new session.
  </div>
</div>

<div class="card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:10px">
      <input type="month" name="month" value="<?= $filterMonth ?>" class="form-control" style="max-width:180px" onchange="this.form.submit()">
    </form>
    <span style="font-size:13px;color:var(--text-light)"><?= $total ?> records · Total: ₹<?= number_format($totalNet,0) ?></span>
  </div>

  <?php if (!$payrolls): ?>
  <div style="padding:60px;text-align:center;color:var(--text-light)">
    <i class="fas fa-file-invoice-dollar" style="font-size:40px;margin-bottom:14px;display:block;color:var(--border)"></i>
    <div style="font-weight:600;margin-bottom:6px">No payroll records for <?= $filterMonth ?></div>
    <div style="font-size:13px">Click "Generate Payroll" to create records for this month</div>
    <button class="btn btn-primary" style="margin-top:14px" onclick="openPayModal()"><i class="fas fa-plus"></i> Generate Payroll</button>
  </div>
  <?php else: ?>
  <div class="data-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Employee</th><th>Department</th>
          <th><i class="fas fa-clock" style="color:var(--primary)"></i> Hours</th>
          <th>Rate/hr</th>
          <th>Basic</th>
          <th style="color:var(--success)">Bonus</th>
          <th style="color:var(--danger)">Deductions</th>
          <th>Net Salary</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payrolls as $p): ?>
        <tr>
          <td>
            <div class="emp-cell">
              <div class="emp-avatar"><?= strtoupper(substr($p['name'],0,1)) ?></div>
              <div><div class="emp-name"><?= htmlspecialchars($p['name']) ?></div><div class="emp-id"><?= $p['emp_id'] ?></div></div>
            </div>
          </td>
          <td><?= htmlspecialchars($p['department_name'] ?? '—') ?></td>
          <td>
            <span style="background:#EEF2FF;color:#3730A3;padding:3px 8px;border-radius:6px;font-weight:700;font-size:12.5px">
              <?= number_format((float)($p['total_hours'] ?? 0),1) ?>h
            </span>
          </td>
          <td style="font-size:12.5px;color:var(--text-mid)">₹<?= number_format((float)($p['hourly_rate'] ?? 0),0) ?>/hr</td>
          <td>₹<?= number_format($p['basic_salary'],0) ?></td>
          <td style="color:var(--success)">+₹<?= number_format($p['bonus'],0) ?></td>
          <td style="color:var(--danger)">-₹<?= number_format($p['deductions'],0) ?></td>
          <td><strong style="font-size:14px">₹<?= number_format($p['net_salary'],0) ?></strong></td>
          <td style="font-size:12px;color:var(--text-light)"><?= date('d M Y',strtotime($p['payment_date'])) ?></td>
          <td>
            <div class="action-btns">
              <a href="payslip.php?id=<?= $p['id'] ?>" class="btn-action" title="Payslip" target="_blank"><i class="fas fa-file-pdf"></i></a>
              <button class="btn-action danger" onclick="deletePay(<?= $p['id'] ?>)"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ Generate Payroll Modal ═══ -->
<div id="payModal" class="modal-overlay">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div class="modal-icon primary"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="modal-title-wrap"><h3 class="modal-title">Generate Payroll</h3><div class="modal-subtitle">Calculate salary based on actual working hours</div></div>
      <button class="modal-close-btn" onclick="closeModal('payModal')"><i class="fas fa-times"></i></button>
    </div>
    <form id="payForm">
      <input type="hidden" name="action" value="generate">
      <div class="modal-body">
        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label>Employee *</label>
            <select name="employee_id" id="payEmpSel" class="form-control" required onchange="onEmpChange()">
              <option value="">Select Employee</option>
              <?php foreach ($employees as $e): ?>
              <option value="<?= $e['id'] ?>" data-rate="<?= (float)($e['hourly_rate']??0) ?>"><?= htmlspecialchars($e['name']) ?> (<?= $e['employee_id'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Month *</label>
            <input type="month" name="pay_month_sel" id="payMonthSel" class="form-control" value="<?= $filterMonth ?>" onchange="onEmpChange()">
          </div>
        </div>

        <!-- Hours card — populated by AJAX -->
        <div id="hoursCard" style="display:none;background:linear-gradient(135deg,#EFF6FF,#EEF2FF);border:1px solid #BFDBFE;border-radius:12px;padding:16px 18px;margin-bottom:16px">
          <div style="font-size:12px;color:#64748B;margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px"><i class="fas fa-calculator"></i> Calculated from Attendance Records</div>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
            <div style="text-align:center"><div style="font-size:20px;font-weight:800;color:#1E40AF" id="hcHours">0</div><div style="font-size:11px;color:#64748B">Total Hours</div></div>
            <div style="text-align:center"><div style="font-size:20px;font-weight:800;color:#059669" id="hcDays">0</div><div style="font-size:11px;color:#64748B">Working Days</div></div>
            <div style="text-align:center"><div style="font-size:20px;font-weight:800;color:#7C3AED" id="hcRate">₹0</div><div style="font-size:11px;color:#64748B">Hourly Rate</div></div>
            <div style="text-align:center"><div style="font-size:20px;font-weight:800;color:#DC2626" id="hcCalc">₹0</div><div style="font-size:11px;color:#64748B">Calculated Pay</div></div>
          </div>
          <div id="hcNote" style="margin-top:8px;font-size:11.5px;color:#64748B;text-align:center"></div>
        </div>
        <div id="hoursLoading" style="display:none;text-align:center;padding:12px;color:var(--primary)"><i class="fas fa-spinner fa-spin"></i> Fetching attendance data…</div>
        <div id="hoursEmpty" style="display:none;background:#FFF7ED;border:1px solid #FDE68A;border-radius:10px;padding:12px 16px;font-size:13px;color:#92400E;margin-bottom:14px">
          <i class="fas fa-exclamation-triangle"></i> No attendance records found for this period. Basic salary will be 0.
        </div>

        <input type="hidden" name="total_hours" id="payTotalHours" value="0">
        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label>Basic Salary (₹) <span style="color:var(--text-light);font-weight:400;font-size:11px">auto-filled from hours</span></label>
            <input type="number" name="basic_salary" id="payBasic" class="form-control" placeholder="0" required min="0" oninput="calcNet()">
          </div>
          <div class="form-group">
            <label>Bonus (₹)</label>
            <input type="number" name="bonus" id="payBonus" class="form-control" placeholder="0" min="0" oninput="calcNet()">
          </div>
          <div class="form-group">
            <label>Deductions (₹)</label>
            <input type="number" name="deductions" id="payDed" class="form-control" placeholder="0" min="0" oninput="calcNet()">
          </div>
          <div class="form-group">
            <label>Net Salary (₹)</label>
            <input type="number" name="_net" id="payNet" class="form-control" readonly style="background:var(--secondary);font-weight:700;font-size:15px">
          </div>
        </div>
        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label>Payment Date *</label>
            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Optional notes…">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('payModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Generate Payroll</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ Set Hourly Rates Modal ═══ -->
<div id="rateModal" class="modal-overlay">
  <div class="modal-dialog modal-dialog-lg">
    <div class="modal-header">
      <div class="modal-icon purple" style="background:#F3E8FF"><i class="fas fa-sliders-h" style="color:#7C3AED"></i></div>
      <div class="modal-title-wrap"><h3 class="modal-title">Set Hourly Rates</h3><div class="modal-subtitle">Configure per-employee hourly pay rate</div></div>
      <button class="modal-close-btn" onclick="closeModal('rateModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" style="max-height:460px;overflow-y:auto">
      <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#92400E;margin-bottom:14px">
        <i class="fas fa-info-circle"></i> Set how much each employee earns per hour. This is used to auto-calculate monthly payroll from attendance sessions.
      </div>
      <table class="data-table">
        <thead><tr><th>Employee</th><th>Department</th><th>Fixed Salary</th><th>Hourly Rate (₹/hr)</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($employees as $emp): ?>
          <tr>
            <td>
              <div class="emp-cell">
                <div class="emp-avatar"><?= strtoupper(substr($emp['name'],0,1)) ?></div>
                <div><div class="emp-name"><?= htmlspecialchars($emp['name']) ?></div><div class="emp-id"><?= $emp['employee_id'] ?></div></div>
              </div>
            </td>
            <td style="font-size:12.5px;color:var(--text-light)">—</td>
            <td style="font-size:12.5px">₹<?= number_format($emp['salary'],0) ?></td>
            <td>
              <input type="number" class="form-control rate-input" id="rate_<?= $emp['id'] ?>"
                value="<?= (float)($emp['hourly_rate']??0) ?>" min="0" step="0.01"
                style="max-width:120px;display:inline-block" placeholder="e.g. 150">
            </td>
            <td>
              <button class="btn btn-outline btn-sm" onclick="saveRate(<?= $emp['id'] ?>)">
                <i class="fas fa-save"></i> Save
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('rateModal')">Close</button>
    </div>
  </div>
</div>

<script>
let calcTimeout = null;

function openPayModal() { openModal('payModal'); }

function onEmpChange() {
  clearTimeout(calcTimeout);
  const emp = document.getElementById('payEmpSel').value;
  const mon = document.getElementById('payMonthSel').value;
  if (!emp || !mon) return;
  calcTimeout = setTimeout(() => fetchHours(emp, mon), 300);
}

async function fetchHours(empId, monthYear) {
  document.getElementById('hoursCard').style.display    = 'none';
  document.getElementById('hoursEmpty').style.display   = 'none';
  document.getElementById('hoursLoading').style.display = 'block';

  const fd = new FormData();
  fd.append('action','calc_hours');
  fd.append('employee_id', empId);
  fd.append('month_year', monthYear);

  try {
    const r = await fetch('', {method:'POST', body:fd});
    const d = await r.json();
    document.getElementById('hoursLoading').style.display = 'none';

    if (d.success && d.total_hours > 0) {
      document.getElementById('hcHours').textContent = d.total_hours + 'h';
      document.getElementById('hcDays').textContent  = d.work_days;
      document.getElementById('hcRate').textContent  = '₹' + (d.hourly_rate || 0).toLocaleString('en-IN');
      document.getElementById('hcCalc').textContent  = '₹' + (d.calculated_pay || 0).toLocaleString('en-IN');
      document.getElementById('hcNote').textContent  = d.sessions + ' session(s) across ' + d.work_days + ' working day(s)';
      document.getElementById('hoursCard').style.display = 'block';
      document.getElementById('payTotalHours').value = d.total_hours;
      document.getElementById('payBasic').value      = d.calculated_pay;
      calcNet();
    } else {
      document.getElementById('hoursEmpty').style.display = 'block';
      document.getElementById('payTotalHours').value = 0;
      document.getElementById('payBasic').value      = 0;
      calcNet();
    }
  } catch(e) {
    document.getElementById('hoursLoading').style.display = 'none';
  }
}

function calcNet() {
  const b   = parseFloat(document.getElementById('payBasic').value)  || 0;
  const bon = parseFloat(document.getElementById('payBonus').value)   || 0;
  const ded = parseFloat(document.getElementById('payDed').value)     || 0;
  document.getElementById('payNet').value = (b + bon - ded).toFixed(0);
}

document.getElementById('payBasic').addEventListener('input', calcNet);

document.getElementById('payForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.delete('_net');
  fd.delete('pay_month_sel');
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  showToast(d.message, d.success?'success':'error');
  if (d.success) { closeModal('payModal'); setTimeout(()=>location.reload(),800); }
});

function deletePay(id) {
  confirmDialog('Delete this payroll record?', async () => {
    const fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
    const r  = await fetch('',{method:'POST',body:fd});
    const d  = await r.json();
    showToast(d.message,'success');
    if(d.success) setTimeout(()=>location.reload(),800);
  });
}

async function saveRate(empId) {
  const val = parseFloat(document.getElementById('rate_' + empId).value) || 0;
  const fd  = new FormData();
  fd.append('action','set_hourly_rate');
  fd.append('employee_id', empId);
  fd.append('hourly_rate', val);
  const r = await fetch('', {method:'POST', body:fd});
  const d = await r.json();
  showToast(d.message, d.success?'success':'error');
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
