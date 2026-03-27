<?php
// search.php  –  Global Search
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle   = 'Search Results';
$currentPage = '';
$pdo         = getDB();
$q           = trim($_GET['q'] ?? '');
$results     = ['employees'=>[], 'departments'=>[], 'leaves'=>[]];
$total       = 0;

if (strlen($q) >= 2) {
    $like = "%$q%";

    // Employees
    $stmt = $pdo->prepare("
        SELECT e.id, e.employee_id, e.name, e.email, e.designation, e.status, d.department_name
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE e.name LIKE ? OR e.email LIKE ? OR e.employee_id LIKE ? OR e.designation LIKE ?
        LIMIT 10
    ");
    $stmt->execute([$like,$like,$like,$like]);
    $results['employees'] = $stmt->fetchAll();

    // Departments
    $stmt = $pdo->prepare("
        SELECT d.id, d.department_name, d.description,
               COUNT(e.id) AS emp_count
        FROM departments d
        LEFT JOIN employees e ON e.department_id = d.id
        WHERE d.department_name LIKE ? OR d.description LIKE ?
        GROUP BY d.id LIMIT 6
    ");
    $stmt->execute([$like,$like]);
    $results['departments'] = $stmt->fetchAll();

    // Leave Requests (admin/hr only)
    if (hasRole('admin','hr')) {
        $stmt = $pdo->prepare("
            SELECT lr.id, lr.leave_type, lr.start_date, lr.end_date, lr.status, lr.reason,
                   e.name, e.employee_id AS emp_id
            FROM leave_requests lr
            JOIN employees e ON e.id = lr.employee_id
            WHERE e.name LIKE ? OR lr.reason LIKE ? OR lr.leave_type LIKE ?
            ORDER BY lr.created_at DESC LIMIT 8
        ");
        $stmt->execute([$like,$like,$like]);
        $results['leaves'] = $stmt->fetchAll();
    }

    $total = count($results['employees']) + count($results['departments']) + count($results['leaves']);

    // Log search activity
    logActivity('Search', "Searched for: \"$q\"", 'search');
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Search Results</h1>
    <?php if ($q): ?>
    <p><?= $total ?> result<?= $total!==1?'s':'' ?> for "<strong><?= htmlspecialchars($q) ?></strong>"</p>
    <?php endif; ?>
  </div>
</div>

<!-- Search Bar -->
<div class="card" style="margin-bottom:24px">
  <div class="card-body" style="padding:18px 22px">
    <form method="GET" style="display:flex;gap:12px">
      <div class="search-bar" style="flex:1;max-width:560px">
        <i class="fas fa-search"></i>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
               placeholder="Search employees, departments, leaves…"
               autofocus style="font-size:15px">
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
    </form>
  </div>
</div>

<?php if (!$q): ?>
<div style="text-align:center;padding:60px 0;color:var(--text-light)">
  <i class="fas fa-search" style="font-size:48px;margin-bottom:14px;display:block;opacity:.3"></i>
  <p style="font-size:15px">Enter at least 2 characters to search</p>
</div>

<?php elseif ($total === 0): ?>
<div style="text-align:center;padding:60px 0;color:var(--text-light)">
  <i class="fas fa-search-minus" style="font-size:48px;margin-bottom:14px;display:block;opacity:.3"></i>
  <p style="font-size:15px">No results found for "<strong><?= htmlspecialchars($q) ?></strong>"</p>
  <p style="font-size:13px;margin-top:6px">Try different keywords</p>
</div>

<?php else: ?>

<!-- ── Employees Results ── -->
<?php if ($results['employees']): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <h2><i class="fas fa-users" style="color:var(--primary);margin-right:8px"></i>Employees
      <span style="font-size:12px;color:var(--text-light);font-weight:400;margin-left:6px"><?= count($results['employees']) ?> found</span>
    </h2>
    <a href="<?= APP_URL ?>/modules/employees/index.php?search=<?= urlencode($q) ?>" class="btn btn-outline btn-sm">View All</a>
  </div>
  <div class="data-table-wrap table-responsive">
    <table class="data-table">
      <thead><tr><th>Employee</th><th>Department</th><th>Designation</th><th>Email</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($results['employees'] as $emp): ?>
        <tr>
          <td>
            <div class="emp-cell">
              <div class="emp-avatar"><?= strtoupper(substr($emp['name'],0,1)) ?></div>
              <div>
                <div class="emp-name"><?= highlight($emp['name'], $q) ?></div>
                <div class="emp-id"><?= highlight($emp['employee_id'], $q) ?></div>
              </div>
            </div>
          </td>
          <td><?= htmlspecialchars($emp['department_name']??'—') ?></td>
          <td><?= highlight($emp['designation']??'—', $q) ?></td>
          <td><?= highlight($emp['email'], $q) ?></td>
          <td><span class="badge badge-<?= $emp['status'] ?>"><?= ucfirst(str_replace('_',' ',$emp['status'])) ?></span></td>
          <td>
            <a href="<?= APP_URL ?>/modules/employees/profile.php?id=<?= $emp['id'] ?>" class="btn-action" title="View">
              <i class="fas fa-eye"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Departments Results ── -->
<?php if ($results['departments']): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <h2><i class="fas fa-sitemap" style="color:var(--success);margin-right:8px"></i>Departments
      <span style="font-size:12px;color:var(--text-light);font-weight:400;margin-left:6px"><?= count($results['departments']) ?> found</span>
    </h2>
    <a href="<?= APP_URL ?>/modules/departments/index.php" class="btn btn-outline btn-sm">View All</a>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
      <?php foreach ($results['departments'] as $dept): ?>
      <div style="background:var(--secondary);border-radius:var(--radius-sm);padding:16px;border:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <div style="width:36px;height:36px;background:var(--primary-light);border-radius:9px;display:flex;align-items:center;justify-content:center;color:var(--primary)">
            <i class="fas fa-sitemap"></i>
          </div>
          <div>
            <div style="font-weight:600;font-size:14px"><?= highlight($dept['department_name'], $q) ?></div>
            <div style="font-size:11.5px;color:var(--text-light)"><?= $dept['emp_count'] ?> employees</div>
          </div>
        </div>
        <?php if ($dept['description']): ?>
        <p style="font-size:12.5px;color:var(--text-mid)"><?= highlight(substr($dept['description'],0,80), $q) ?>…</p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Leave Results ── -->
<?php if ($results['leaves']): ?>
<div class="card">
  <div class="card-header">
    <h2><i class="fas fa-calendar-minus" style="color:var(--warning);margin-right:8px"></i>Leave Requests
      <span style="font-size:12px;color:var(--text-light);font-weight:400;margin-left:6px"><?= count($results['leaves']) ?> found</span>
    </h2>
    <a href="<?= APP_URL ?>/modules/leave/index.php" class="btn btn-outline btn-sm">View All</a>
  </div>
  <div class="data-table-wrap table-responsive">
    <table class="data-table">
      <thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Reason</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($results['leaves'] as $lv): ?>
        <tr>
          <td>
            <div class="emp-cell">
              <div class="emp-avatar"><?= strtoupper(substr($lv['name'],0,1)) ?></div>
              <div><div class="emp-name"><?= highlight($lv['name'], $q) ?></div><div class="emp-id"><?= $lv['emp_id'] ?></div></div>
            </div>
          </td>
          <td><span class="badge" style="background:<?= $lv['leave_type']==='sick'?'#FEE2E2':'#EEF2FF' ?>;color:<?= $lv['leave_type']==='sick'?'#991B1B':'#3730A3' ?>"><?= ucfirst($lv['leave_type']) ?></span></td>
          <td><?= date('M d, Y', strtotime($lv['start_date'])) ?></td>
          <td><?= date('M d, Y', strtotime($lv['end_date'])) ?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= highlight(htmlspecialchars($lv['reason']??''), $q) ?></td>
          <td><span class="badge badge-<?= $lv['status'] ?>"><?= ucfirst($lv['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; // end results ?>

<?php
// Highlight helper function
function highlight(string $text, string $term): string {
    if (!$term) return htmlspecialchars($text);
    $escaped = htmlspecialchars($text);
    $term    = preg_quote(htmlspecialchars($term), '/');
    return preg_replace('/('.$term.')/i',
        '<mark style="background:#FEF3C7;padding:0 2px;border-radius:3px">$1</mark>',
        $escaped);
}

require_once __DIR__ . '/includes/footer.php';
?>
