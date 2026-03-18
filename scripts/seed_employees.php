<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$passwordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // "password"
$targetCount = 50;
$startCode = 1001; // Generates EMP1001..EMP1050

$firstNames = [
    'Aarav','Vivaan','Aditya','Arjun','Sai','Reyansh','Krishna','Ishaan','Rohan','Kabir',
    'Meera','Ananya','Diya','Priya','Aisha','Saanvi','Ira','Kavya','Riya','Pooja',
    'Rahul','Neha','Karan','Nikita','Aman','Sneha','Varun','Isha','Tarun','Maya',
    'Nikhil','Shreya','Ritvik','Tanvi','Yash','Anika','Dev','Simran','Harsh','Naina',
    'Manav','Avni','Parth','Radhika','Rishi','Aditi','Siddharth','Muskan','Akash','Palak'
];

$lastNames = [
    'Sharma','Verma','Gupta','Patel','Singh','Kumar','Malhotra','Nair','Iyer','Kapoor',
    'Joshi','Mehta','Reddy','Bose','Khanna','Arora','Chopra','Saxena','Mishra','Bhatia'
];

$addressPool = [
    'MG Road, Bengaluru',
    'Baner Road, Pune',
    'Banjara Hills, Hyderabad',
    'Sector 62, Noida',
    'Andheri East, Mumbai',
    'Salt Lake, Kolkata',
    'Anna Nagar, Chennai',
    'Vaishali Nagar, Jaipur'
];

$designationByDept = [
    'Human Resources' => ['HR Executive', 'Talent Acquisition Specialist', 'HR Generalist'],
    'Engineering'     => ['Software Engineer', 'Senior Developer', 'QA Engineer', 'DevOps Engineer'],
    'Finance'         => ['Accountant', 'Financial Analyst', 'Payroll Specialist'],
    'Marketing'       => ['Marketing Executive', 'Content Strategist', 'Digital Marketer'],
    'Operations'      => ['Operations Executive', 'Process Analyst', 'Admin Coordinator'],
    'Sales'           => ['Sales Executive', 'Business Development Executive', 'Account Manager']
];

function randomDate(string $start, string $end): string {
    $min = strtotime($start);
    $max = strtotime($end);
    $rand = random_int($min, $max);
    return date('Y-m-d', $rand);
}

function randomFloat(float $min, float $max): float {
    return round($min + lcg_value() * ($max - $min), 2);
}

$pdo = getDB();
$pdo->beginTransaction();

try {
    $departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY id')->fetchAll();
    if (!$departments) {
        $seedDept = [
            ['Human Resources', 'Manages recruitment, training, and employee relations'],
            ['Engineering', 'Software development and infrastructure'],
            ['Finance', 'Accounting, budgeting, and financial reporting'],
            ['Marketing', 'Brand management and growth strategies'],
            ['Operations', 'Day-to-day business operations'],
            ['Sales', 'Revenue generation and client relations'],
        ];
        $deptStmt = $pdo->prepare('INSERT INTO departments (department_name, description) VALUES (?, ?)');
        foreach ($seedDept as [$name, $desc]) {
            $deptStmt->execute([$name, $desc]);
        }
        $departments = $pdo->query('SELECT id, department_name FROM departments ORDER BY id')->fetchAll();
    }

    $insertUser = $pdo->prepare(
        'INSERT INTO users (username, email, password, role, is_verified, verification_token) VALUES (?, ?, ?, ?, 1, NULL)'
    );
    $insertEmp = $pdo->prepare(
        'INSERT INTO employees (user_id, employee_id, name, email, phone, department_id, designation, joining_date, salary, address, photo, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ensureLeave = $pdo->prepare(
        'INSERT IGNORE INTO leave_balances (employee_id, year) VALUES (?, YEAR(CURDATE()))'
    );

    $inserted = 0;

    for ($i = 0; $i < $targetCount; $i++) {
        $empCode = 'EMP' . str_pad((string)($startCode + $i), 4, '0', STR_PAD_LEFT);

        $exists = $pdo->prepare('SELECT id FROM employees WHERE employee_id = ?');
        $exists->execute([$empCode]);
        if ($exists->fetchColumn()) {
            continue;
        }

        $first = $firstNames[$i % count($firstNames)];
        $last = $lastNames[$i % count($lastNames)];
        $fullName = $first . ' ' . $last;

        $baseUser = strtolower($first . '.' . $last . ($i + 1));
        $username = preg_replace('/[^a-z0-9.]/', '', $baseUser) ?: ('employee' . ($i + 1));
        $email = $username . '@company.com';

        $uniqueSuffix = 1;
        while (true) {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $check->execute([$username, $email]);
            if (!$check->fetchColumn()) {
                break;
            }
            $uniqueSuffix++;
            $username = $baseUser . $uniqueSuffix;
            $email = $username . '@company.com';
        }

        $dept = $departments[$i % count($departments)];
        $deptName = $dept['department_name'];
        $designationOptions = $designationByDept[$deptName] ?? ['Executive'];
        $designation = $designationOptions[$i % count($designationOptions)];

        $salaryBase = match ($deptName) {
            'Engineering' => randomFloat(60000, 150000),
            'Finance' => randomFloat(50000, 110000),
            'Sales' => randomFloat(45000, 120000),
            'Human Resources' => randomFloat(40000, 95000),
            'Marketing' => randomFloat(45000, 100000),
            default => randomFloat(35000, 90000),
        };

        $status = match (true) {
            $i % 13 === 0 => 'on_leave',
            $i % 17 === 0 => 'inactive',
            default => 'active',
        };

        $phone = '98' . str_pad((string)(10000000 + $i), 8, '0', STR_PAD_LEFT);
        $joiningDate = randomDate('2021-01-01', '2025-12-31');
        $address = $addressPool[$i % count($addressPool)];

        $insertUser->execute([$username, $email, $passwordHash, 'employee']);
        $userId = (int)$pdo->lastInsertId();

        $insertEmp->execute([
            $userId,
            $empCode,
            $fullName,
            $email,
            $phone,
            (int)$dept['id'],
            $designation,
            $joiningDate,
            $salaryBase,
            $address,
            'default.png',
            $status,
        ]);

        $employeePk = (int)$pdo->lastInsertId();
        $ensureLeave->execute([$employeePk]);
        $inserted++;
    }

    $pdo->commit();
    echo "Seeding complete. Newly inserted employees: {$inserted}" . PHP_EOL;
    echo "Employee IDs range attempted: EMP{$startCode} to EMP" . ($startCode + $targetCount - 1) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seeder failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

