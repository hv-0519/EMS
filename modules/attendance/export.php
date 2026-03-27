<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin','hr');
$pdo  = getDB();
$date = $_GET['date'] ?? date('Y-m');

$stmt = $pdo->prepare("
    SELECT e.employee_id, e.name, d.department_name, a.date, a.check_in, a.check_out, a.status
    FROM attendance a
    JOIN employees e ON e.id = a.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE DATE_FORMAT(a.date,'%Y-%m') = ?
    ORDER BY a.date, e.name
");
$stmt->execute([$date]);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="attendance_' . $date . '.csv"');

$out = fopen('php://output','w');
fputcsv($out, ['Employee ID','Name','Department','Date','Check In','Check Out','Status']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['employee_id'], $r['name'], $r['department_name'] ?? '',
        $r['date'],
        $r['check_in']  ? formatStoredUtcToApp($r['check_in'],  'h:i A') : '',
        $r['check_out'] ? formatStoredUtcToApp($r['check_out'], 'h:i A') : '',
        $r['status']
    ]);
}
fclose($out);
