-- ============================================================
-- EmpAxis — Full Demo Seed Data
-- Run AFTER database.sql + all migrations
-- All passwords = 'password'
-- ============================================================

USE `employee_management`;

-- ── Clean slate (safe order for FK constraints) ──
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE `activity_log`;
TRUNCATE TABLE `notifications`;
TRUNCATE TABLE `payroll`;
TRUNCATE TABLE `leave_requests`;
TRUNCATE TABLE `attendance`;
TRUNCATE TABLE `employees`;
TRUNCATE TABLE `users`;
TRUNCATE TABLE `departments`;
SET FOREIGN_KEY_CHECKS = 1;

-- ── Departments ──────────────────────────────────────────────
INSERT INTO `departments` (`id`, `department_name`, `description`) VALUES
(1,  'Human Resources',   'Recruitment, training and employee relations'),
(2,  'Engineering',       'Software development and infrastructure'),
(3,  'Finance',           'Accounting, budgeting and financial reporting'),
(4,  'Marketing',         'Brand management and growth strategies'),
(5,  'Operations',        'Day-to-day business operations'),
(6,  'Sales',             'Revenue generation and client relations'),
(7,  'Design',            'UI/UX and visual communications'),
(8,  'Customer Support',  'Client success and helpdesk');

-- ── Users (all passwords = "password") ──────────────────────
-- bcrypt hash of "password"
INSERT INTO `users` (`id`, `username`, `display_name`, `email`, `password`, `role`, `is_verified`, `last_login_at`) VALUES
(1,  'admin',          'System Administrator', 'admin@company.com',          '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',    1, NOW()),
(2,  'hrmanager',      'Priya Sharma',         'priya.sharma@company.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr',       1, NOW()),
(3,  'john.doe',       'John Doe',             'john.doe@company.com',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(4,  'sarah.jones',    'Sarah Jones',          'sarah.jones@company.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(5,  'rahul.verma',    'Rahul Verma',          'rahul.verma@company.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(6,  'ananya.iyer',    'Ananya Iyer',          'ananya.iyer@company.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(7,  'michael.chen',   'Michael Chen',         'michael.chen@company.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(8,  'fatima.khan',    'Fatima Khan',          'fatima.khan@company.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(9,  'arjun.nair',     'Arjun Nair',           'arjun.nair@company.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(10, 'lisa.patel',     'Lisa Patel',           'lisa.patel@company.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(11, 'dev.hr',         'Dev HR',               'dev.hr@company.com',         '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr',       1, NULL),
(12, 'carlos.ruiz',    'Carlos Ruiz',          'carlos.ruiz@company.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(13, 'neha.singh',     'Neha Singh',           'neha.singh@company.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(14, 'tom.wright',     'Tom Wright',           'tom.wright@company.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
(15, 'sneha.kapoor',   'Sneha Kapoor',         'sneha.kapoor@company.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL);

-- ── Employees ────────────────────────────────────────────────
INSERT INTO `employees` (`id`, `user_id`, `employee_id`, `name`, `email`, `phone`, `department_id`, `designation`, `joining_date`, `date_of_birth`, `gender`, `salary`, `address`, `city`, `state`, `country`, `postal_code`, `status`) VALUES
(1,  1,  'EMP0001', 'System Administrator', 'admin@company.com',        '+91 9000000001', 1, 'Administrator',         '2020-01-01', '1985-03-12', 'male',   150000.00, '12 Admin Block, HQ',        'Mumbai',    'Maharashtra', 'India', '400001', 'active'),
(2,  2,  'EMP0002', 'Priya Sharma',         'priya.sharma@company.com', '+91 9000000002', 1, 'HR Manager',            '2020-03-15', '1990-07-24', 'female',  90000.00, '45 Green Park',             'Bengaluru', 'Karnataka',   'India', '560001', 'active'),
(3,  3,  'EMP0003', 'John Doe',             'john.doe@company.com',     '+91 9000000003', 2, 'Senior Software Engineer','2021-06-01','1993-11-05', 'male',   95000.00, '78 Tech Street',            'Hyderabad', 'Telangana',   'India', '500001', 'active'),
(4,  4,  'EMP0004', 'Sarah Jones',          'sarah.jones@company.com',  '+91 9000000004', 7, 'UI/UX Designer',        '2021-08-10', '1995-02-18', 'female',  78000.00, '22 Design Lane',            'Pune',      'Maharashtra', 'India', '411001', 'active'),
(5,  5,  'EMP0005', 'Rahul Verma',          'rahul.verma@company.com',  '+91 9000000005', 2, 'Backend Developer',     '2022-01-17', '1994-09-30', 'male',   85000.00, '55 Sector 18',              'Noida',     'Uttar Pradesh','India','201301', 'active'),
(6,  6,  'EMP0006', 'Ananya Iyer',          'ananya.iyer@company.com',  '+91 9000000006', 4, 'Marketing Manager',     '2021-04-05', '1991-06-14', 'female',  88000.00, '9 MG Road',                 'Chennai',   'Tamil Nadu',  'India', '600001', 'active'),
(7,  7,  'EMP0007', 'Michael Chen',         'michael.chen@company.com', '+91 9000000007', 3, 'Finance Analyst',       '2022-07-01', '1992-12-22', 'male',   72000.00, '33 Park Avenue',            'Mumbai',    'Maharashtra', 'India', '400051', 'active'),
(8,  8,  'EMP0008', 'Fatima Khan',          'fatima.khan@company.com',  '+91 9000000008', 6, 'Sales Executive',       '2023-02-13', '1997-04-09', 'female',  65000.00, '17 Lake View',              'Ahmedabad', 'Gujarat',     'India', '380001', 'active'),
(9,  9,  'EMP0009', 'Arjun Nair',           'arjun.nair@company.com',   '+91 9000000009', 5, 'Operations Lead',       '2020-11-20', '1988-08-17', 'male',  100000.00, '4 Brigade Road',            'Bengaluru', 'Karnataka',   'India', '560025', 'active'),
(10, 10, 'EMP0010', 'Lisa Patel',           'lisa.patel@company.com',   '+91 9000000010', 8, 'Customer Support Lead', '2023-05-22', '1996-01-28', 'female',  60000.00, '88 Linking Road',           'Mumbai',    'Maharashtra', 'India', '400050', 'active'),
(11, 11, 'EMP0011', 'Dev HR',               'dev.hr@company.com',       '+91 9000000011', 1, 'HR Executive',          '2022-09-01', '1993-03-11', 'male',   70000.00, '16 HR Colony',              'Delhi',     'Delhi',       'India', '110001', 'active'),
(12, 12, 'EMP0012', 'Carlos Ruiz',          'carlos.ruiz@company.com',  '+91 9000000012', 2, 'DevOps Engineer',       '2021-12-06', '1990-10-03', 'male',   92000.00, '7 Cloud Campus',            'Hyderabad', 'Telangana',   'India', '500032', 'active'),
(13, 13, 'EMP0013', 'Neha Singh',           'neha.singh@company.com',   '+91 9000000013', 4, 'Content Strategist',    '2022-03-28', '1995-07-16', 'female',  68000.00, '29 Writers Block',          'Jaipur',    'Rajasthan',   'India', '302001', 'active'),
(14, 14, 'EMP0014', 'Tom Wright',           'tom.wright@company.com',   '+91 9000000014', 6, 'Senior Sales Manager',  '2020-06-15', '1987-05-25', 'male',  110000.00, '54 Commerce Street',        'Bengaluru', 'Karnataka',   'India', '560002', 'active'),
(15, 15, 'EMP0015', 'Sneha Kapoor',         'sneha.kapoor@company.com', '+91 9000000015', 3, 'Chief Financial Officer','2019-01-10','1983-11-30', 'female', 180000.00, '1 Finance Tower, BKC',      'Mumbai',    'Maharashtra', 'India', '400051', 'active');

-- ── Leave Balance ─────────────────────────────────────────────
INSERT INTO `leave_balance` (`employee_id`, `leave_type`, `total_days`, `used_days`, `year`) VALUES
(1,'sick',12,2,2026),(1,'casual',12,1,2026),(1,'paid',15,0,2026),
(2,'sick',12,3,2026),(2,'casual',12,2,2026),(2,'paid',15,5,2026),
(3,'sick',12,1,2026),(3,'casual',12,3,2026),(3,'paid',15,2,2026),
(4,'sick',12,0,2026),(4,'casual',12,1,2026),(4,'paid',15,3,2026),
(5,'sick',12,2,2026),(5,'casual',12,0,2026),(5,'paid',15,1,2026),
(6,'sick',12,1,2026),(6,'casual',12,2,2026),(6,'paid',15,4,2026),
(7,'sick',12,3,2026),(7,'casual',12,1,2026),(7,'paid',15,0,2026),
(8,'sick',12,0,2026),(8,'casual',12,2,2026),(8,'paid',15,2,2026),
(9,'sick',12,1,2026),(9,'casual',12,0,2026),(9,'paid',15,6,2026),
(10,'sick',12,2,2026),(10,'casual',12,3,2026),(10,'paid',15,1,2026),
(11,'sick',12,0,2026),(11,'casual',12,1,2026),(11,'paid',15,0,2026),
(12,'sick',12,1,2026),(12,'casual',12,2,2026),(12,'paid',15,3,2026),
(13,'sick',12,4,2026),(13,'casual',12,0,2026),(13,'paid',15,2,2026),
(14,'sick',12,1,2026),(14,'casual',12,1,2026),(14,'paid',15,5,2026),
(15,'sick',12,0,2026),(15,'casual',12,2,2026),(15,'paid',15,1,2026);

-- ── Attendance (last 30 days for all employees) ───────────────
INSERT INTO `attendance` (`employee_id`, `date`, `check_in`, `check_out`, `status`) VALUES
-- Employee 3 (John Doe) — March 2026
(3,'2026-03-01','2026-03-01 09:05:00','2026-03-01 18:10:00','present'),
(3,'2026-03-02','2026-03-02 09:15:00','2026-03-02 18:00:00','present'),
(3,'2026-03-03','2026-03-03 10:30:00','2026-03-03 18:30:00','late'),
(3,'2026-03-04','2026-03-04 09:00:00','2026-03-04 18:00:00','present'),
(3,'2026-03-05','2026-03-05 09:02:00','2026-03-05 13:00:00','half_day'),
-- Employee 4 (Sarah)
(4,'2026-03-01','2026-03-01 08:55:00','2026-03-01 17:55:00','present'),
(4,'2026-03-02','2026-03-02 09:10:00','2026-03-02 18:10:00','present'),
(4,'2026-03-03',NULL,NULL,'absent'),
(4,'2026-03-04','2026-03-04 09:00:00','2026-03-04 18:00:00','present'),
(4,'2026-03-05','2026-03-05 09:20:00','2026-03-05 18:20:00','present'),
-- Employee 5 (Rahul)
(5,'2026-03-01','2026-03-01 09:30:00','2026-03-01 19:00:00','late'),
(5,'2026-03-02','2026-03-02 09:05:00','2026-03-02 18:05:00','present'),
(5,'2026-03-03','2026-03-03 09:00:00','2026-03-03 18:00:00','present'),
(5,'2026-03-04','2026-03-04 09:15:00','2026-03-04 18:15:00','present'),
(5,'2026-03-05','2026-03-05 09:00:00','2026-03-05 18:00:00','present'),
-- Employee 6 (Ananya)
(6,'2026-03-01','2026-03-01 09:00:00','2026-03-01 18:00:00','present'),
(6,'2026-03-02','2026-03-02 09:00:00','2026-03-02 18:00:00','present'),
(6,'2026-03-03','2026-03-03 09:00:00','2026-03-03 18:00:00','present'),
(6,'2026-03-04',NULL,NULL,'absent'),
(6,'2026-03-05','2026-03-05 09:05:00','2026-03-05 18:05:00','present'),
-- More employees Feb 2026
(3,'2026-02-25','2026-02-25 09:00:00','2026-02-25 18:00:00','present'),
(3,'2026-02-26','2026-02-26 09:00:00','2026-02-26 18:00:00','present'),
(3,'2026-02-27','2026-02-27 10:00:00','2026-02-27 18:00:00','late'),
(3,'2026-02-28','2026-02-28 09:00:00','2026-02-28 18:00:00','present'),
(7,'2026-03-01','2026-03-01 09:00:00','2026-03-01 18:00:00','present'),
(7,'2026-03-02','2026-03-02 09:10:00','2026-03-02 18:10:00','present'),
(7,'2026-03-03','2026-03-03 09:00:00','2026-03-03 18:00:00','present'),
(7,'2026-03-04','2026-03-04 09:00:00','2026-03-04 17:00:00','half_day'),
(7,'2026-03-05','2026-03-05 09:00:00','2026-03-05 18:00:00','present'),
(8,'2026-03-01','2026-03-01 09:00:00','2026-03-01 18:00:00','present'),
(8,'2026-03-02',NULL,NULL,'absent'),
(8,'2026-03-03','2026-03-03 09:00:00','2026-03-03 18:00:00','present'),
(8,'2026-03-04','2026-03-04 09:20:00','2026-03-04 18:20:00','late'),
(8,'2026-03-05','2026-03-05 09:00:00','2026-03-05 18:00:00','present'),
(9,'2026-03-01','2026-03-01 08:45:00','2026-03-01 18:00:00','present'),
(9,'2026-03-02','2026-03-02 08:50:00','2026-03-02 18:00:00','present'),
(9,'2026-03-03','2026-03-03 09:00:00','2026-03-03 18:00:00','present'),
(9,'2026-03-04','2026-03-04 09:00:00','2026-03-04 18:00:00','present'),
(9,'2026-03-05','2026-03-05 09:00:00','2026-03-05 18:00:00','present');

-- ── Leave Requests ─────────────────────────────────────────────
INSERT INTO `leave_requests` (`employee_id`, `leave_type`, `start_date`, `end_date`, `reason`, `status`, `reviewed_by`, `created_at`) VALUES
(3, 'sick',   '2026-02-10', '2026-02-11', 'Fever and cold',                     'approved', 2, '2026-02-09 10:00:00'),
(4, 'casual', '2026-02-20', '2026-02-21', 'Personal work',                      'approved', 2, '2026-02-18 09:00:00'),
(5, 'paid',   '2026-01-15', '2026-01-17', 'Family vacation',                    'approved', 2, '2026-01-10 11:00:00'),
(6, 'sick',   '2026-03-10', '2026-03-11', 'Medical appointment',                'pending',  NULL, '2026-03-05 08:00:00'),
(7, 'casual', '2026-03-15', '2026-03-15', 'Attending wedding',                  'pending',  NULL, '2026-03-04 14:00:00'),
(8, 'paid',   '2026-03-20', '2026-03-22', 'Annual family trip',                 'pending',  NULL, '2026-03-03 10:00:00'),
(9, 'sick',   '2026-02-01', '2026-02-03', 'Viral infection',                    'approved', 2, '2026-01-31 09:00:00'),
(10,'casual', '2026-01-25', '2026-01-25', 'House shifting',                     'approved', 2, '2026-01-22 15:00:00'),
(12,'paid',   '2026-02-14', '2026-02-16', 'International conference attendance', 'approved', 2, '2026-02-05 09:00:00'),
(13,'sick',   '2026-02-28', '2026-03-01', 'Severe migraine',                    'rejected', 2, '2026-02-27 08:00:00'),
(14,'casual', '2026-03-12', '2026-03-12', 'Child school event',                 'pending',  NULL, '2026-03-05 10:00:00'),
(15,'paid',   '2026-04-01', '2026-04-05', 'Planned leave — Goa trip',           'pending',  NULL, '2026-03-01 09:00:00');

-- ── Payroll (Feb & Mar 2026) ───────────────────────────────────
INSERT INTO `payroll` (`employee_id`, `basic_salary`, `bonus`, `deductions`, `net_salary`, `payment_date`, `month_year`, `notes`) VALUES
-- February 2026
(1,  150000.00, 10000.00, 5000.00, 155000.00, '2026-02-28', '2026-02', 'Monthly salary + performance bonus'),
(2,   90000.00,  5000.00, 2500.00,  92500.00, '2026-02-28', '2026-02', 'Monthly salary'),
(3,   95000.00,  8000.00, 3000.00, 100000.00, '2026-02-28', '2026-02', 'Monthly salary + project bonus'),
(4,   78000.00,  3000.00, 2000.00,  79000.00, '2026-02-28', '2026-02', 'Monthly salary'),
(5,   85000.00,  5000.00, 2500.00,  87500.00, '2026-02-28', '2026-02', 'Monthly salary'),
(6,   88000.00,  4000.00, 2500.00,  89500.00, '2026-02-28', '2026-02', 'Monthly salary'),
(7,   72000.00,  2000.00, 2000.00,  72000.00, '2026-02-28', '2026-02', 'Monthly salary'),
(8,   65000.00,  3000.00, 1500.00,  66500.00, '2026-02-28', '2026-02', 'Monthly salary + sales incentive'),
(9,  100000.00,  7000.00, 3500.00, 103500.00, '2026-02-28', '2026-02', 'Monthly salary'),
(10,  60000.00,  2000.00, 1500.00,  60500.00, '2026-02-28', '2026-02', 'Monthly salary'),
(11,  70000.00,  0.00,    2000.00,  68000.00, '2026-02-28', '2026-02', 'Monthly salary'),
(12,  92000.00,  6000.00, 3000.00,  95000.00, '2026-02-28', '2026-02', 'Monthly salary'),
(13,  68000.00,  2000.00, 2000.00,  68000.00, '2026-02-28', '2026-02', 'Monthly salary'),
(14, 110000.00, 15000.00, 4000.00, 121000.00, '2026-02-28', '2026-02', 'Monthly salary + sales target bonus'),
(15, 180000.00, 20000.00, 8000.00, 192000.00, '2026-02-28', '2026-02', 'Monthly salary + leadership bonus'),
-- March 2026
(1,  150000.00, 12000.00, 5000.00, 157000.00, '2026-03-31', '2026-03', 'Monthly salary + Q1 bonus'),
(2,   90000.00,  5000.00, 2500.00,  92500.00, '2026-03-31', '2026-03', 'Monthly salary'),
(3,   95000.00,  0.00,    3000.00,  92000.00, '2026-03-31', '2026-03', 'Monthly salary'),
(4,   78000.00,  5000.00, 2000.00,  81000.00, '2026-03-31', '2026-03', 'Monthly salary + design award bonus'),
(5,   85000.00,  0.00,    2500.00,  82500.00, '2026-03-31', '2026-03', 'Monthly salary'),
(6,   88000.00,  8000.00, 2500.00,  93500.00, '2026-03-31', '2026-03', 'Monthly salary + campaign bonus'),
(7,   72000.00,  0.00,    2000.00,  70000.00, '2026-03-31', '2026-03', 'Monthly salary'),
(8,   65000.00,  7000.00, 1500.00,  70500.00, '2026-03-31', '2026-03', 'Monthly salary + Q1 sales incentive'),
(9,  100000.00,  0.00,    3500.00,  96500.00, '2026-03-31', '2026-03', 'Monthly salary'),
(10,  60000.00,  3000.00, 1500.00,  61500.00, '2026-03-31', '2026-03', 'Monthly salary');

-- ── Notifications ─────────────────────────────────────────────
INSERT INTO `notifications` (`user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(1, 'New Leave Request',        'Ananya Iyer has applied for sick leave on Mar 10-11.',    'info',    0, NOW() - INTERVAL 1 HOUR),
(1, 'New Leave Request',        'Tom Wright has applied for casual leave on Mar 12.',      'info',    0, NOW() - INTERVAL 2 HOUR),
(1, 'Payroll Processed',        'February 2026 payroll has been processed successfully.',  'success', 1, NOW() - INTERVAL 5 DAY),
(1, 'New Employee Registered',  'Lisa Patel joined the Customer Support team.',            'info',    1, NOW() - INTERVAL 10 DAY),
(2, 'Leave Pending Approval',   '3 leave requests are waiting for your approval.',         'warning', 0, NOW() - INTERVAL 30 MINUTE),
(2, 'Attendance Alert',         'Sarah Jones was absent on Mar 3rd without notification.', 'warning', 0, NOW() - INTERVAL 3 HOUR),
(2, 'Payroll Reminder',         'March 2026 payroll processing is due in 2 weeks.',        'info',    0, NOW() - INTERVAL 1 DAY),
(3, 'Leave Approved',           'Your sick leave request for Feb 10-11 has been approved.','success', 1, '2026-02-09 12:00:00'),
(3, 'Payslip Available',        'Your February 2026 payslip is now available.',            'info',    0, '2026-03-01 09:00:00'),
(4, 'Leave Approved',           'Your casual leave for Feb 20-21 has been approved.',      'success', 1, '2026-02-19 10:00:00'),
(6, 'Leave Request Submitted',  'Your sick leave request for Mar 10-11 is under review.',  'info',    0, '2026-03-05 08:05:00'),
(8, 'Leave Request Submitted',  'Your paid leave request for Mar 20-22 is under review.',  'info',    0, '2026-03-03 10:05:00'),
(13,'Leave Rejected',           'Your sick leave for Feb 28 - Mar 1 has been rejected. Please submit a medical certificate.', 'error', 0, '2026-02-28 11:00:00'),
(14,'Leave Request Submitted',  'Your casual leave for Mar 12 is under review.',           'info',    0, '2026-03-05 10:05:00');

-- ── Activity Log ──────────────────────────────────────────────
INSERT INTO `activity_log` (`user_id`, `action`, `description`, `module`, `created_at`) VALUES
(1, 'Logged in',            'Admin signed in',                          'auth',      NOW() - INTERVAL 10 MINUTE),
(2, 'Approved leave',       'Approved John Doe sick leave Feb 10-11',   'leave',     NOW() - INTERVAL 2 HOUR),
(2, 'Logged in',            'HR Manager signed in',                     'auth',      NOW() - INTERVAL 3 HOUR),
(1, 'Processed payroll',    'February 2026 payroll batch processed',    'payroll',   NOW() - INTERVAL 5 DAY),
(1, 'Added employee',       'New employee Lisa Patel added',            'employees', NOW() - INTERVAL 10 DAY),
(2, 'Updated employee',     'Updated Rahul Verma department details',   'employees', NOW() - INTERVAL 12 DAY),
(3, 'Logged in',            'John Doe signed in',                       'auth',      NOW() - INTERVAL 1 DAY),
(3, 'Applied leave',        'Applied for sick leave Feb 10-11',         'leave',     '2026-02-09 10:00:00'),
(4, 'Applied leave',        'Applied for casual leave Feb 20-21',       'leave',     '2026-02-18 09:00:00'),
(9, 'Checked in',           'Arjun Nair clocked in at 08:45',           'attendance','2026-03-05 08:45:00'),
(6, 'Applied leave',        'Applied for sick leave Mar 10-11',         'leave',     '2026-03-05 08:00:00'),
(14,'Applied leave',        'Applied for casual leave Mar 12',          'leave',     '2026-03-05 10:00:00');