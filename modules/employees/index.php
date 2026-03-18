<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'hr');

$pageTitle   = 'Employees';
$currentPage = 'employees';
$pdo         = getDB();

// ── Handle AJAX CRUD ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Bulk Actions (Feature 8) ─────────────────────────────
    if ($action === 'bulk') {
        $ids        = json_decode($_POST['ids'] ?? '[]', true);
        $bulkAction = $_POST['bulk_action'] ?? '';
        if (!$ids || !is_array($ids)) {
            echo json_encode(['success'=>false,'message'=>'No employees selected']);
            exit;
        }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($bulkAction === 'delete') {
            $pdo->prepare("DELETE FROM employees WHERE id IN ($placeholders)")->execute($ids);
            logActivity('Bulk delete employees', count($ids).' employees deleted', 'employees');
            echo json_encode(['success'=>true,'message'=>count($ids).' employee(s) deleted']);
        } elseif (in_array($bulkAction, ['active','inactive','on_leave'])) {
            $params = $ids; array_unshift($params, $bulkAction);
            // status is first param when using array_unshift; fix order
            $params = array_merge([$bulkAction], $ids);
            $pdo->prepare("UPDATE employees SET status=? WHERE id IN ($placeholders)")->execute($params);
            logActivity('Bulk status update', count($ids)." employees set to $bulkAction", 'employees');
            echo json_encode(['success'=>true,'message'=>count($ids).' employee(s) updated to '.str_replace('_',' ',$bulkAction)]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Unknown bulk action']);
        }
        exit;
    }

    if ($action === 'add' || $action === 'edit') {
        $name       = trim($_POST['name']        ?? '');
        $email      = trim($_POST['email']       ?? '');
        $phone      = trim($_POST['phone']       ?? '');
        $deptId     = (int)($_POST['department_id'] ?? 0) ?: null;
        $desig      = trim($_POST['designation'] ?? '');
        $joinDate   = $_POST['joining_date']     ?? null;
        $salary     = (float)($_POST['salary']      ?? 0);
        $hourlyRate = (float)($_POST['hourly_rate'] ?? 0);
        $address    = trim($_POST['address']        ?? '');
        $status     = $_POST['status']           ?? 'active';

        // Photo upload
        $photo = null;
        if (!empty($_FILES['photo']['name'])) {
            $ext    = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allow  = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allow) && $_FILES['photo']['size'] < 2 * 1024 * 1024) {
                $fname = uniqid('emp_') . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $fname)) {
                    $photo = $fname;
                }
            }
        }

        if ($action === 'add') {
            // Auto-generate credentials (Darwinbox-style flow)
            $username = generateUsername($name);
            // Temp password: Emp@ + 4 random digits
            $tempPwd  = 'Emp@' . rand(1000,9999);
            $hash     = password_hash($tempPwd, PASSWORD_BCRYPT);
            $token    = generateToken(64);

            try {
                $pdo->prepare('INSERT INTO users (username,email,password,role,is_verified,verification_token,must_change_password) VALUES(?,?,?,?,1,?,1)')
                    ->execute([$username, $email, $hash, 'employee', $token]);
            } catch (\Throwable $e) {
                // must_change_password column may not exist yet
                $pdo->prepare('INSERT INTO users (username,email,password,role,is_verified,verification_token) VALUES(?,?,?,?,1,?)')
                    ->execute([$username, $email, $hash, 'employee', $token]);
                try { $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0'); } catch(\Throwable $e2){}
                $pdo->prepare('UPDATE users SET must_change_password=1 WHERE username=?')->execute([$username]);
            }
            $userId = $pdo->lastInsertId();
            $empId  = 'EMP' . str_pad($userId, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare('INSERT INTO employees (user_id,employee_id,name,email,phone,department_id,designation,joining_date,salary,hourly_rate,address,photo,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
            try {
                $stmt->execute([$userId, $empId, $name, $email, $phone, $deptId, $desig, $joinDate, $salary, $hourlyRate, $address, $photo ?? 'default.png', $status]);
            } catch (\Throwable $e) {
                $stmt = $pdo->prepare('INSERT INTO employees (user_id,employee_id,name,email,phone,department_id,designation,joining_date,salary,address,photo,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$userId, $empId, $name, $email, $phone, $deptId, $desig, $joinDate, $salary, $address, $photo ?? 'default.png', $status]);
            }

            // Send welcome email with temp credentials
            sendEmail($email, 'Welcome to EmpAxis — Your Account is Ready',
              "<div style='font-family:Segoe UI,Arial,sans-serif;background:#f4f6f9;padding:30px'>

  <div style='max-width:620px;margin:auto;background:#ffffff;border-radius:12px;
  box-shadow:0 10px 30px rgba(0,0,0,0.08);overflow:hidden'>

    <!-- Header -->
    <div style='background:linear-gradient(135deg,#10B981,#059669);
    padding:24px 30px;color:white'>

      <div style='font-size:22px;font-weight:600'>EmpAxis</div>
      <div style='font-size:13px;opacity:.9;margin-top:4px'>
        HR Management System
      </div>

    </div>

    <!-- Body -->
    <div style='padding:32px'>

      <p style='font-size:16px;color:#1f2937;margin-bottom:14px'>
        Hi <strong>$name</strong>,
      </p>

      <p style='color:#4b5563;line-height:1.6'>
        Your <strong>EmpAxis</strong> account has been created by the HR team.
        Please use the credentials below to access your dashboard.
      </p>

      <!-- Credentials Card -->
      <div style='margin:24px 0;background:#D1FAE5;
      border:1px solid #A7F3D0;border-radius:10px;padding:20px'>

        <table style='width:100%;font-size:14px;color:#065F46'>

          <tr>
            <td style='padding:6px 0;font-weight:600'>Employee ID</td>
            <td>$empId</td>
          </tr>

          <tr>
            <td style='padding:6px 0;font-weight:600'>Username</td>
            <td>$username</td>
          </tr>

          <tr>
            <td style='padding:6px 0;font-weight:600'>Temporary Password</td>
            <td style='font-family:monospace;background:white;
            padding:5px 10px;border-radius:6px;border:1px solid #A7F3D0'>
              $tempPwd
            </td>
          </tr>

          <tr>
            <td style='padding:6px 0;font-weight:600'>Login URL</td>
            <td>
              <a href='" . APP_URL . "/auth/login.php'
              style='color:#059669;text-decoration:none;font-weight:600'>
              Open EmpAxis Login Page
              </a>
            </td>
          </tr>

        </table>

      </div>

      <!-- Login Button -->
      <div style='text-align:center;margin:28px 0'>
        <a href='" . APP_URL . "/auth/login.php'
        style='background:#10B981;color:white;
        padding:12px 30px;border-radius:8px;
        text-decoration:none;font-weight:600;
        font-size:14px;display:inline-block'>
          Login to EmpAxis
        </a>
      </div>

      <!-- Warning -->
      <div style='background:#FEF2F2;border-left:4px solid #EF4444;
      padding:12px 15px;border-radius:6px'>

        <p style='margin:0;font-size:13px;color:#991B1B'>
          <strong>Important:</strong> You will be asked to change your password
          during your first login. The temporary password will not work afterward.
        </p>

      </div>

      <p style='margin-top:24px;color:#4b5563'>
        Welcome aboard! We’re excited to have you with us.
      </p>

    </div>

    <!-- Footer -->
    <div style='background:#f9fafb;padding:18px;
    text-align:center;font-size:12px;color:#6b7280'>

      © ".date('Y')." EmpAxis HR System<br>
      Powered by EmpAxis

    </div>

  </div>

</div>");
            logActivity('Added employee', "New employee $name ($empId) added", 'employees');
            echo json_encode(['success'=>true,'message'=>"Employee added. Credentials sent to $email (temp password: $tempPwd)"]);
        } else {
            $id   = (int)($_POST['id'] ?? 0);
            $sets = 'name=?,email=?,phone=?,department_id=?,designation=?,joining_date=?,salary=?,address=?,status=?';
            $vals = [$name, $email, $phone, $deptId, $desig, $joinDate, $salary, $address, $status];
            if ($photo) { $sets .= ',photo=?'; $vals[] = $photo; }
            $vals[] = $id;
            $pdo->prepare("UPDATE employees SET $sets WHERE id=?")->execute($vals);
            echo json_encode(['success'=>true,'message'=>'Employee updated successfully']);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM employees WHERE id = ?')->execute([$id]);
        echo json_encode(['success'=>true,'message'=>'Employee deleted']);
        exit;
    }

    if ($action === 'get') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch());
        exit;
    }
}

// ── List employees ──────────────────────────────────────────
$search    = trim($_GET['search']   ?? '');
$deptFilter= (int)($_GET['dept']    ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 10;

$where = 'WHERE 1=1';
$params = [];
if ($search) { $where .= ' AND (e.name LIKE ? OR e.email LIKE ? OR e.employee_id LIKE ?)'; $s = "%$search%"; $params = array_merge($params, [$s,$s,$s]); }
if ($deptFilter) { $where .= ' AND e.department_id = ?'; $params[] = $deptFilter; }

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e $where");
$countStmt->execute($params);
$total  = $countStmt->fetchColumn();
$pages  = ceil($total / $perPage);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT e.*, d.department_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    $where ORDER BY e.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Employees</h1>
    <p>Manage all employees in your organization</p>
  </div>
  <div class="actions">
    <button class="btn btn-primary" onclick="openModal('addEmpModal')">
      <i class="fas fa-plus"></i> Add Employee
    </button>
  </div>
</div>

<div class="card">
  <!-- Toolbar -->
  <div class="table-toolbar">
    <div class="left">
      <form method="GET" style="display:flex;gap:10px;flex:1">
        <div class="table-search" style="flex:1;max-width:300px">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search employees…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <select name="dept" class="filter-select" onchange="this.form.submit()">
          <option value="">All Departments</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $deptFilter == $d['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($d['department_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="right">
      <span style="font-size:13px;color:var(--text-light)">
        <?= $total ?> employee<?= $total !== 1 ? 's' : '' ?>
      </span>
    </div>
  </div>

  <!-- Bulk Action Bar (hidden until rows selected) -->
  <div id="bulkBar" style="display:none;align-items:center;gap:12px;padding:12px 22px;background:var(--primary-light);border-bottom:1px solid var(--border)">
    <span id="bulkCount" style="font-size:13px;font-weight:600;color:var(--primary)">0 selected</span>
    <select id="bulkAction" class="filter-select" style="font-size:13px">
      <option value="">— Choose Action —</option>
      <option value="active">Set Active</option>
      <option value="inactive">Set Inactive</option>
      <option value="on_leave">Set On Leave</option>
      <option value="delete">Delete Selected</option>
    </select>
    <button class="btn btn-primary btn-sm" onclick="runBulkAction()"><i class="fas fa-bolt"></i> Apply</button>
    <button class="btn btn-secondary btn-sm" onclick="clearSelection()"><i class="fas fa-times"></i> Clear</button>
  </div>

  <!-- Table -->
  <div class="data-table-wrap">
    <table class="data-table" id="empTable">
      <thead>
        <tr>
          <th style="width:40px"><input type="checkbox" id="selectAll" style="cursor:pointer"></th>
          <th>Employee</th>
          <th>Department</th>
          <th>Designation</th>
          <th>Phone</th>
          <th>Joining Date</th>
          <th>Salary</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $emp): ?>
        <tr>
          <td><input type="checkbox" class="row-check" value="<?= $emp['id'] ?>" style="cursor:pointer"></td>
          <td>
            <div class="emp-cell">
              <div class="emp-avatar">
                <?php if ($emp['photo'] && $emp['photo'] !== 'default.png' && file_exists(UPLOAD_DIR . $emp['photo'])): ?>
                <img src="<?= UPLOAD_URL . $emp['photo'] ?>" alt="">
                <?php else: ?><?= strtoupper(substr($emp['name'],0,1)) ?>
                <?php endif; ?>
              </div>
              <div>
                <div class="emp-name"><?= htmlspecialchars($emp['name']) ?></div>
                <div class="emp-id"><?= $emp['employee_id'] ?></div>
              </div>
            </div>
          </td>
          <td><?= htmlspecialchars($emp['department_name'] ?? '—') ?></td>
          <td><?= htmlspecialchars($emp['designation'] ?? '—') ?></td>
          <td><?= htmlspecialchars($emp['phone'] ?? '—') ?></td>
          <td><?= $emp['joining_date'] ? date('M d, Y', strtotime($emp['joining_date'])) : '—' ?></td>
          <td>₹<?= number_format($emp['salary'], 0) ?></td>
          <td><span class="badge badge-<?= $emp['status'] ?>"><?= ucfirst(str_replace('_',' ',$emp['status'])) ?></span></td>
          <td>
            <div class="action-btns">
              <a href="<?= APP_URL ?>/modules/employees/profile.php?id=<?= $emp['id'] ?>" class="btn-action" title="View">
                <i class="fas fa-eye"></i>
              </a>
              <button class="btn-action" title="Edit" onclick="editEmployee(<?= $emp['id'] ?>)">
                <i class="fas fa-pen"></i>
              </button>
              <button class="btn-action danger" title="Delete" onclick="deleteEmployee(<?= $emp['id'] ?>, '<?= addslashes($emp['name']) ?>')">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$employees): ?>
        <tr><td colspan="8" class="text-center text-muted" style="padding:40px">No employees found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="pagination">
    <span class="page-info">Showing <?= ($offset+1) ?>–<?= min($offset+$perPage, $total) ?> of <?= $total ?></span>
    <div class="page-controls">
      <a href="?page=<?= max(1,$page-1) ?>&search=<?= urlencode($search) ?>&dept=<?= $deptFilter ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">
        <i class="fas fa-chevron-left"></i>
      </a>
      <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
      <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&dept=<?= $deptFilter ?>" class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a href="?page=<?= min($pages,$page+1) ?>&search=<?= urlencode($search) ?>&dept=<?= $deptFilter ?>" class="page-btn <?= $page>=$pages?'disabled':'' ?>">
        <i class="fas fa-chevron-right"></i>
      </a>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ── Add/Edit Employee Modal ── -->
<div id="addEmpModal" class="modal-overlay">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3 id="empModalTitle">Add Employee</h3>
      <button class="modal-close" data-close-modal><i class="fas fa-times"></i></button>
    </div>
    <form id="empForm" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="action" id="empAction" value="add">
        <input type="hidden" name="id" id="empId">

        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="name" id="empName" class="form-control" placeholder="John Doe" required>
          </div>
          <div class="form-group">
            <label>Email Address *</label>
            <input type="email" name="email" id="empEmail" class="form-control" placeholder="john@company.com" required>
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" id="empPhone" class="form-control" placeholder="+91 9876543210">
          </div>
          <div class="form-group">
            <label>Department</label>
            <select name="department_id" id="empDept" class="form-control">
              <option value="">Select Department</option>
              <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Designation</label>
            <input type="text" name="designation" id="empDesig" class="form-control" placeholder="Software Engineer">
          </div>
          <div class="form-group">
            <label>Joining Date</label>
            <input type="date" name="joining_date" id="empJoinDate" class="form-control">
          </div>
          <div class="form-group">
            <label>Fixed Salary (₹/mo)</label>
            <input type="number" name="salary" id="empSalary" class="form-control" placeholder="50000" min="0">
          </div>
          <div class="form-group">
            <label>Hourly Rate (₹/hr) <span style="font-weight:400;font-size:11px;color:var(--text-light)">for hourly payroll</span></label>
            <input type="number" name="hourly_rate" id="empHourlyRate" class="form-control" placeholder="e.g. 150" min="0" step="0.01">
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" id="empStatus" class="form-control">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="on_leave">On Leave</option>
            </select>
          </div>
        </div>

        <div class="form-group mt-16">
          <label>Address</label>
          <textarea name="address" id="empAddress" class="form-control" placeholder="Full address…"></textarea>
        </div>

        <div class="form-group mt-16">
          <label>Profile Photo</label>
          <div class="file-upload-area">
            <input type="file" name="photo" accept="image/*" style="display:none">
            <i class="fas fa-cloud-upload-alt"></i>
            <p>Click or drag photo here</p>
            <span>JPG, PNG, WEBP (max 2MB)</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Employee</button>
      </div>
    </form>
  </div>
</div>

<?php
$extraScripts = '
<script>
// ── Bulk Selection ─────────────────────────────────────────
document.getElementById("selectAll")?.addEventListener("change", function() {
  document.querySelectorAll(".row-check").forEach(cb => cb.checked = this.checked);
  updateBulkBar();
});
document.addEventListener("change", e => { if (e.target.classList.contains("row-check")) updateBulkBar(); });

function updateBulkBar() {
  const checked = document.querySelectorAll(".row-check:checked");
  const bar = document.getElementById("bulkBar");
  document.getElementById("bulkCount").textContent = checked.length + " selected";
  bar.style.display = checked.length > 0 ? "flex" : "none";
}

function getSelectedIds() {
  return Array.from(document.querySelectorAll(".row-check:checked")).map(c => parseInt(c.value));
}

function clearSelection() {
  document.querySelectorAll(".row-check, #selectAll").forEach(c => c.checked = false);
  updateBulkBar();
}

async function runBulkAction() {
  const ids    = getSelectedIds();
  const action = document.getElementById("bulkAction").value;
  if (!ids.length) { showToast("No employees selected","error"); return; }
  if (!action) { showToast("Please choose an action","error"); return; }

  const confirmMsg = action === "delete"
    ? `Permanently delete ${ids.length} employee(s)? This cannot be undone.`
    : `Set ${ids.length} employee(s) to "${action.replace("_"," ")}"?`;

  confirmDialog(confirmMsg, async () => {
    const fd = new FormData();
    fd.append("action","bulk");
    fd.append("ids", JSON.stringify(ids));
    fd.append("bulk_action", action);
    const r = await fetch("", {method:"POST", body:fd});
    const d = await r.json();
    showToast(d.message, d.success?"success":"error");
    if (d.success) { clearSelection(); setTimeout(() => location.reload(), 800); }
  }, action === "delete" ? "Bulk Delete" : "Bulk Update");
}

// ── Add employee
document.getElementById("empForm").addEventListener("submit", async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const res = await fetch("", { method: "POST", body: fd });
  const data = await res.json();
  if (data.success) {
    showToast(data.message, "success");
    closeModal("addEmpModal");
    setTimeout(() => location.reload(), 800);
  } else {
    showToast(data.message || "Error", "error");
  }
});

// Edit employee
async function editEmployee(id) {
  const fd = new FormData(); fd.append("action","get"); fd.append("id",id);
  const res = await fetch("", {method:"POST", body:fd});
  const emp = await res.json();
  document.getElementById("empModalTitle").textContent = "Edit Employee";
  document.getElementById("empAction").value = "edit";
  document.getElementById("empId").value     = emp.id;
  document.getElementById("empName").value   = emp.name || "";
  document.getElementById("empEmail").value  = emp.email || "";
  document.getElementById("empPhone").value  = emp.phone || "";
  document.getElementById("empDept").value   = emp.department_id || "";
  document.getElementById("empDesig").value  = emp.designation || "";
  document.getElementById("empJoinDate").value = emp.joining_date || "";
  document.getElementById("empSalary").value = emp.salary || "";
  document.getElementById("empAddress").value= emp.address || "";
  document.getElementById("empStatus").value = emp.status || "active";
  openModal("addEmpModal");
}

// Delete
function deleteEmployee(id, name) {
  confirmDialog("Delete employee \"" + name + "\"? This action cannot be undone.", async () => {
    const fd = new FormData(); fd.append("action","delete"); fd.append("id",id);
    const res = await fetch("", {method:"POST", body:fd});
    const data = await res.json();
    if (data.success) { showToast(data.message,"success"); setTimeout(() => location.reload(),800); }
    else showToast(data.message||"Error","error");
  }, "Delete Employee");
}

// Reset form on open
document.querySelector("[onclick=\"openModal(\'addEmpModal\')\"]")?.addEventListener("click", () => {
  document.getElementById("empModalTitle").textContent = "Add Employee";
  document.getElementById("empAction").value = "add";
  document.getElementById("empForm").reset();
});
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
