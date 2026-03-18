<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/*
 * Seeds attendance, leave requests, and payroll for employees
 * in employee_id range EMP1001..EMP1050.
 *
 * Safe to re-run: skips records that already exist for the same
 * employee/date (attendance), employee/month (payroll), and
 * same leave tuple (employee + start/end + type).
 */

const EMP_START = 'EMP1001';
const EMP_END = 'EMP1050';
const ATT_DAYS_BACK = 45;
const PAYROLL_MONTHS_BACK = 3; // seeds current month + previous 3

function randomFloat(float $min, float $max): float {
    return round($min + lcg_value() * ($max - $min), 2);
}

function chooseStatus(): string {
    $r = random_int(1, 100);
    if ($r <= 74) return 'present';
    if ($r <= 86) return 'late';
    if ($r <= 94) return 'half_day';
    return 'absent';
}

function randomWorkDate(int $pastDays): string {
    $dayOffset = random_int(3, max(3, $pastDays));
    $ts = strtotime("-{$dayOffset} days");
    return date('Y-m-d', $ts);
}

function monthList(int $monthsBack): array {
    $out = [];
    $cursor = new DateTimeImmutable('first day of this month');
    for ($i = 0; $i <= $monthsBack; $i++) {
        $m = $cursor->modify("-{$i} month");
        $out[] = $m->format('Y-m');
    }
    return $out;
}

$pdo = getDB();
$pdo->beginTransaction();

try {
    $empStmt = $pdo->prepare(
        "SELECT id, employee_id, name, salary, status
         FROM employees
         WHERE employee_id BETWEEN ? AND ?
         ORDER BY id"
    );
    $empStmt->execute([EMP_START, EMP_END]);
    $employees = $empStmt->fetchAll();

    if (!$employees) {
        throw new RuntimeException('No employees found in range EMP1001..EMP1050. Run seed_employees.php first.');
    }

    $checkAtt = $pdo->prepare('SELECT id FROM attendance WHERE employee_id = ? AND date = ? LIMIT 1');
    $insAtt = $pdo->prepare(
        'INSERT INTO attendance (employee_id, check_in, check_out, date, status) VALUES (?, ?, ?, ?, ?)'
    );

    $checkLeave = $pdo->prepare(
        'SELECT id FROM leave_requests WHERE employee_id = ? AND leave_type = ? AND start_date = ? AND end_date = ? LIMIT 1'
    );
    $checkSeedLeaveByEmp = $pdo->prepare(
        'SELECT COUNT(*) FROM leave_requests WHERE employee_id = ?'
    );
    $insLeave = $pdo->prepare(
        'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason, status, reviewed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $checkPay = $pdo->prepare('SELECT id FROM payroll WHERE employee_id = ? AND month_year = ? LIMIT 1');
    $insPay = $pdo->prepare(
        'INSERT INTO payroll (employee_id, basic_salary, bonus, deductions, net_salary, payment_date, month_year, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $attendanceInserted = 0;
    $leaveInserted = 0;
    $payrollInserted = 0;

    // Attendance for last ATT_DAYS_BACK days
    for ($d = ATT_DAYS_BACK; $d >= 1; $d--) {
        $date = date('Y-m-d', strtotime("-{$d} days"));
        $weekday = (int)date('N', strtotime($date)); // 1..7 (Mon..Sun)
        if ($weekday >= 6) continue; // skip weekends

        foreach ($employees as $emp) {
            $status = chooseStatus();
            if ($emp['status'] === 'inactive' && random_int(1, 100) <= 80) {
                $status = 'absent';
            }

            $checkAtt->execute([(int)$emp['id'], $date]);
            if ($checkAtt->fetchColumn()) {
                continue;
            }

            $checkIn = null;
            $checkOut = null;

            if ($status !== 'absent') {
                $inHour = $status === 'late' ? random_int(10, 11) : random_int(9, 10);
                $inMinute = random_int(0, 59);
                $inTs = strtotime($date . sprintf(' %02d:%02d:00', $inHour, $inMinute));
                $checkIn = date('Y-m-d H:i:s', $inTs);

                if ($status === 'half_day') {
                    $outTs = $inTs + random_int(3, 5) * 3600;
                } else {
                    $outTs = $inTs + random_int(7, 9) * 3600 + random_int(0, 59) * 60;
                }
                $checkOut = date('Y-m-d H:i:s', $outTs);
            }

            $insAtt->execute([(int)$emp['id'], $checkIn, $checkOut, $date, $status]);
            $attendanceInserted++;
        }
    }

    // Leave requests: 1-2 per employee in last 120 days
    $leaveTypes = ['sick', 'casual', 'paid'];
    $leaveReasons = [
        'Family function',
        'Medical appointment',
        'Personal work',
        'Travel plan',
        'Health recovery',
        'Urgent personal matter',
    ];

    foreach ($employees as $emp) {
        $checkSeedLeaveByEmp->execute([(int)$emp['id']]);
        if ((int)$checkSeedLeaveByEmp->fetchColumn() > 0) {
            continue;
        }

        $numLeaves = random_int(1, 2);
        for ($i = 0; $i < $numLeaves; $i++) {
            $start = randomWorkDate(120);
            $duration = random_int(1, 3);
            $end = date('Y-m-d', strtotime($start . " +{$duration} days"));
            $type = $leaveTypes[array_rand($leaveTypes)];
            $status = ['approved', 'approved', 'pending', 'rejected'][array_rand(['a', 'b', 'c', 'd'])];
            $reason = 'Auto-generated seed leave: ' . $leaveReasons[array_rand($leaveReasons)];
            $reviewedBy = $status === 'pending' ? null : random_int(1, 2);

            $checkLeave->execute([(int)$emp['id'], $type, $start, $end]);
            if ($checkLeave->fetchColumn()) {
                continue;
            }

            $insLeave->execute([(int)$emp['id'], $type, $start, $end, $reason, $status, $reviewedBy]);
            $leaveInserted++;
        }
    }

    // Payroll for current month + previous PAYROLL_MONTHS_BACK months
    $months = monthList(PAYROLL_MONTHS_BACK);
    $today = new DateTimeImmutable('today');

    foreach ($employees as $emp) {
        $basic = (float)$emp['salary'];
        if ($basic <= 0) $basic = randomFloat(30000, 90000);

        foreach ($months as $month) {
            $checkPay->execute([(int)$emp['id'], $month]);
            if ($checkPay->fetchColumn()) {
                continue;
            }

            $monthDate = new DateTimeImmutable($month . '-01');
            $paymentDay = min(28, (int)$today->format('d'));
            $paymentDate = $monthDate->setDate(
                (int)$monthDate->format('Y'),
                (int)$monthDate->format('m'),
                $paymentDay
            )->format('Y-m-d');

            $bonus = randomFloat(500, 5000);
            $deductions = randomFloat(200, 2200);
            if ($emp['status'] === 'inactive') {
                $bonus = randomFloat(0, 1500);
                $deductions = randomFloat(500, 3500);
            }
            $net = max(0, round($basic + $bonus - $deductions, 2));

            $insPay->execute([
                (int)$emp['id'],
                $basic,
                $bonus,
                $deductions,
                $net,
                $paymentDate,
                $month,
                'Auto-generated seed payroll',
            ]);
            $payrollInserted++;
        }
    }

    $pdo->commit();

    echo "Workforce seed complete." . PHP_EOL;
    echo "Employees considered: " . count($employees) . PHP_EOL;
    echo "Attendance inserted: {$attendanceInserted}" . PHP_EOL;
    echo "Leave requests inserted: {$leaveInserted}" . PHP_EOL;
    echo "Payroll rows inserted: {$payrollInserted}" . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Workforce seeder failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
