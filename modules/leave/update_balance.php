<?php
// modules/leave/update_balance.php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'hr');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/dashboard/dashboard.php');
}

$pdo       = getDB();
$empId     = (int)$_POST['employee_id'];
$year      = (int)$_POST['year'];
$redirect  = $_POST['redirect'] ?? APP_URL . '/modules/employees/index.php';

$fields = ['casual_quota','sick_quota','paid_quota','casual_used','sick_used','paid_used'];
$sets   = [];
$vals   = [];
foreach ($fields as $f) {
    if (isset($_POST[$f])) {
        $sets[] = "$f = ?";
        $vals[] = max(0, (float)$_POST[$f]);
    }
}

if ($sets) {
    ensureLeaveBalance($empId, $year);
    $vals[] = $empId;
    $vals[] = $year;
    $pdo->prepare('UPDATE leave_balances SET '.implode(',',$sets).' WHERE employee_id=? AND year=?')
        ->execute($vals);
    logActivity('Updated leave balance', "Adjusted leave balance for employee ID $empId", 'leave');
    setFlash('success', 'Leave balance updated.');
}

redirect($redirect);
