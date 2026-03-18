<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'hr');

$pageTitle   = 'Departments';
$currentPage = 'departments';
$pdo         = getDB();

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['department_name'] ?? '');
        $desc = trim($_POST['description']     ?? '');
        if ($action === 'add') {
            $pdo->prepare('INSERT INTO departments (department_name, description) VALUES(?,?)')->execute([$name,$desc]);
            echo json_encode(['success'=>true,'message'=>'Department created']);
        } else {
            $id = (int)$_POST['id'];
            $pdo->prepare('UPDATE departments SET department_name=?,description=? WHERE id=?')->execute([$name,$desc,$id]);
            echo json_encode(['success'=>true,'message'=>'Department updated']);
        }
        exit;
    }
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE department_id = ?');
        $cnt->execute([$id]);
        if ($cnt->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: department has employees']);
        } else {
            $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            echo json_encode(['success'=>true,'message'=>'Department deleted']);
        }
        exit;
    }
    if ($action === 'get') {
        $stmt = $pdo->prepare('SELECT * FROM departments WHERE id = ?');
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode($stmt->fetch());
        exit;
    }
}

$departments = $pdo->query('
    SELECT d.*, COUNT(e.id) AS emp_count
    FROM departments d
    LEFT JOIN employees e ON e.department_id = d.id
    GROUP BY d.id ORDER BY d.department_name
')->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div><h1>Departments</h1><p>Organise your workforce into departments</p></div>
  <button class="btn btn-primary" onclick="openDeptModal()">
    <i class="fas fa-plus"></i> Add Department
  </button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px">
  <?php foreach ($departments as $dept): ?>
  <div class="card" style="transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='var(--shadow-md)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
        <div style="width:44px;height:44px;background:var(--primary-light);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:20px">
          <i class="fas fa-sitemap"></i>
        </div>
        <div class="action-btns">
          <button class="btn-action" onclick="editDept(<?= $dept['id'] ?>)"><i class="fas fa-pen"></i></button>
          <button class="btn-action danger" onclick="deleteDept(<?= $dept['id'] ?>, '<?= addslashes($dept['department_name']) ?>')"><i class="fas fa-trash"></i></button>
        </div>
      </div>
      <h3 style="font-size:16px;font-weight:600;margin-bottom:6px"><?= htmlspecialchars($dept['department_name']) ?></h3>
      <p style="font-size:12.5px;color:var(--text-light);margin-bottom:16px;min-height:36px"><?= htmlspecialchars($dept['description'] ?: 'No description') ?></p>
      <div style="display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-mid)">
          <i class="fas fa-users" style="color:var(--primary)"></i>
          <strong><?= $dept['emp_count'] ?></strong> employee<?= $dept['emp_count'] != 1 ? 's' : '' ?>
        </div>
        <span style="font-size:11px;color:var(--text-light)"><?= date('M Y', strtotime($dept['created_at'])) ?></span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Dept Modal -->
<div id="deptModal" class="modal-overlay">
  <div class="modal modal-sm">
    <div class="modal-header">
      <h3 id="deptModalTitle">Add Department</h3>
      <button class="modal-close" data-close-modal><i class="fas fa-times"></i></button>
    </div>
    <form id="deptForm">
      <div class="modal-body">
        <input type="hidden" name="action" id="deptAction" value="add">
        <input type="hidden" name="id" id="deptId">
        <div class="form-group">
          <label>Department Name *</label>
          <input type="text" name="department_name" id="deptName" class="form-control" placeholder="e.g. Engineering" required>
        </div>
        <div class="form-group mt-16">
          <label>Description</label>
          <textarea name="description" id="deptDesc" class="form-control" placeholder="Brief description…" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openDeptModal(){ document.getElementById("deptModalTitle").textContent="Add Department"; document.getElementById("deptAction").value="add"; document.getElementById("deptForm").reset(); openModal("deptModal"); }
async function editDept(id){ const fd=new FormData();fd.append("action","get");fd.append("id",id); const r=await fetch("",{method:"POST",body:fd}); const d=await r.json(); document.getElementById("deptModalTitle").textContent="Edit Department"; document.getElementById("deptAction").value="edit"; document.getElementById("deptId").value=d.id; document.getElementById("deptName").value=d.department_name; document.getElementById("deptDesc").value=d.description||""; openModal("deptModal"); }
function deleteDept(id,name){ confirmDialog('Delete department "'+name+'"?',async()=>{ const fd=new FormData();fd.append("action","delete");fd.append("id",id); const r=await fetch("",{method:"POST",body:fd}); const d=await r.json(); showToast(d.message,d.success?"success":"error"); if(d.success)setTimeout(()=>location.reload(),800); },"Delete Department"); }
document.getElementById("deptForm").addEventListener("submit",async function(e){ e.preventDefault(); const fd=new FormData(this); const r=await fetch("",{method:"POST",body:fd}); const d=await r.json(); if(d.success){showToast(d.message,"success");closeModal("deptModal");setTimeout(()=>location.reload(),800);}else showToast(d.message,"error"); });
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
