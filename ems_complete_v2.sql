-- ============================================
-- EmpAxis v2 — Complete Database
-- Employee Management System
-- MySQL / phpMyAdmin (WAMP / XAMPP)
-- ============================================

CREATE DATABASE IF NOT EXISTS `employee_management_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `employee_management_db`;

-- -----------------------------------------------
-- Table: departments
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `department_name` VARCHAR(100) NOT NULL,
  `description`     TEXT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: users
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`           VARCHAR(50) NOT NULL UNIQUE,
  `display_name`       VARCHAR(120) DEFAULT NULL,
  `email`              VARCHAR(150) NOT NULL UNIQUE,
  `password`           VARCHAR(255) NOT NULL,
  `role`               ENUM('admin','hr','employee') NOT NULL DEFAULT 'employee',
  `avatar`             VARCHAR(255) DEFAULT NULL,
  `is_verified`        TINYINT(1) NOT NULL DEFAULT 0,
  `verification_token` VARCHAR(100) DEFAULT NULL,
  `last_login_at`      DATETIME DEFAULT NULL,
  `last_login_ip`      VARCHAR(45) DEFAULT NULL,
  `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: employees
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `employees` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL,
  `employee_id`   VARCHAR(20) NOT NULL UNIQUE,
  `name`          VARCHAR(150) NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `phone`         VARCHAR(20) DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `designation`   VARCHAR(100) DEFAULT NULL,
  `joining_date`  DATE DEFAULT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `gender`        ENUM('male','female','other') DEFAULT NULL,
  `salary`        DECIMAL(12,2) DEFAULT 0.00,
  `address`       TEXT DEFAULT NULL,
  `city`          VARCHAR(100) DEFAULT NULL,
  `state`         VARCHAR(100) DEFAULT NULL,
  `country`       VARCHAR(100) DEFAULT NULL,
  `postal_code`   VARCHAR(20) DEFAULT NULL,
  `photo`         VARCHAR(255) DEFAULT 'default.png',
  `status`        ENUM('active','inactive','on_leave') DEFAULT 'active',
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_emp_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: attendance  (now supports MULTIPLE sessions per day)
-- OLD TABLE RENAMED / REPLACED
-- -----------------------------------------------
-- Drop old table if upgrading
-- ALTER TABLE `attendance` RENAME TO `attendance_legacy_v1`;

CREATE TABLE IF NOT EXISTS `attendance` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT UNSIGNED NOT NULL,
  `session_no`  TINYINT UNSIGNED NOT NULL DEFAULT 1  COMMENT 'Session counter for same day (1,2,3...)',
  `check_in`    DATETIME DEFAULT NULL,
  `check_out`   DATETIME DEFAULT NULL,
  `date`        DATE NOT NULL,
  `status`      ENUM('present','absent','half_day','late') DEFAULT 'present',
  `entry_method` ENUM('manual','csv','card_tap') DEFAULT 'manual'
                  COMMENT 'manual=web login, csv=bulk upload, card_tap=future NFC/RFID',
  `card_uid`    VARCHAR(64) DEFAULT NULL COMMENT 'Future: NFC/RFID card UID',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_att_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_emp_date_session` (`employee_id`,`date`,`session_no`)
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: attendance_daily_summary (computed cache)
-- Stores total working hours per day per employee
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_daily_summary` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`     INT UNSIGNED NOT NULL,
  `date`            DATE NOT NULL,
  `total_minutes`   INT UNSIGNED DEFAULT 0 COMMENT 'Sum of all session durations in minutes',
  `session_count`   TINYINT UNSIGNED DEFAULT 0,
  `status`          ENUM('present','absent','half_day','late') DEFAULT 'present',
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ads_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_ads_emp_date` (`employee_id`,`date`)
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: otp_tokens  (replaces single-use OTP)
-- Now multi-use per employee login cycle
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_tokens` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `otp_code`    VARCHAR(10) NOT NULL,
  `purpose`     ENUM('login','verify_email','reset_password') DEFAULT 'login',
  `expires_at`  DATETIME NOT NULL,
  `used_at`     DATETIME DEFAULT NULL  COMMENT 'NULL = not yet used',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_otp_user` (`user_id`,`purpose`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: leave_requests
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT UNSIGNED NOT NULL,
  `leave_type`  ENUM('sick','casual','paid') NOT NULL DEFAULT 'casual',
  `start_date`  DATE NOT NULL,
  `end_date`    DATE NOT NULL,
  `reason`      TEXT DEFAULT NULL,
  `status`      ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_leave_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: payroll
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `payroll` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`  INT UNSIGNED NOT NULL,
  `basic_salary` DECIMAL(12,2) DEFAULT 0.00,
  `bonus`        DECIMAL(12,2) DEFAULT 0.00,
  `deductions`   DECIMAL(12,2) DEFAULT 0.00,
  `net_salary`   DECIMAL(12,2) DEFAULT 0.00,
  `payment_date` DATE NOT NULL,
  `month_year`   VARCHAR(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `notes`        TEXT DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_pay_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: notifications
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `message`    TEXT NOT NULL,
  `type`       ENUM('info','success','warning','error') DEFAULT 'info',
  `is_read`    TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: password_resets
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`      VARCHAR(150) NOT NULL,
  `token`      VARCHAR(100) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used`       TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: activity_log
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `username`    VARCHAR(50) DEFAULT NULL,
  `role`        VARCHAR(20) DEFAULT NULL,
  `action`      VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `module`      VARCHAR(50) DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: leave_balances
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `leave_balances` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT UNSIGNED NOT NULL,
  `year`        YEAR NOT NULL,
  `sick_total`  TINYINT UNSIGNED DEFAULT 10,
  `sick_used`   TINYINT UNSIGNED DEFAULT 0,
  `casual_total` TINYINT UNSIGNED DEFAULT 12,
  `casual_used`  TINYINT UNSIGNED DEFAULT 0,
  `paid_total`   TINYINT UNSIGNED DEFAULT 15,
  `paid_used`    TINYINT UNSIGNED DEFAULT 0,
  UNIQUE KEY `uq_lb_emp_year` (`employee_id`,`year`),
  CONSTRAINT `fk_lb_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: smtp_config
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `smtp_config` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `host`        VARCHAR(200) DEFAULT '',
  `port`        SMALLINT UNSIGNED DEFAULT 587,
  `encryption`  ENUM('tls','ssl','none') DEFAULT 'tls',
  `username`    VARCHAR(200) DEFAULT '',
  `password`    VARCHAR(200) DEFAULT '',
  `from_email`  VARCHAR(200) DEFAULT '',
  `from_name`   VARCHAR(200) DEFAULT 'EmpAxis'
) ENGINE=InnoDB;

INSERT IGNORE INTO `smtp_config` (`id`,`host`,`port`,`encryption`,`from_name`) VALUES (1,'',587,'tls','EmpAxis');

-- -----------------------------------------------
-- Table: attendance_csv_uploads  (audit trail)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_csv_uploads` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uploaded_by`     INT UNSIGNED NOT NULL,
  `filename`        VARCHAR(255) NOT NULL,
  `rows_total`      INT UNSIGNED DEFAULT 0,
  `rows_success`    INT UNSIGNED DEFAULT 0,
  `rows_failed`     INT UNSIGNED DEFAULT 0,
  `error_log`       TEXT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_acu_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SEEDING DATA
-- ============================================================

-- -----------------------------------------------
-- Departments (10 realistic departments)
-- -----------------------------------------------
INSERT INTO `departments` (`id`,`department_name`, `description`) VALUES
(1, 'Human Resources',    'Manages recruitment, training, and employee relations'),
(2, 'Engineering',        'Software development and infrastructure'),
(3, 'Finance',            'Accounting, budgeting, and financial reporting'),
(4, 'Marketing',          'Brand management and growth strategies'),
(5, 'Operations',         'Day-to-day business operations'),
(6, 'Sales',              'Revenue generation and client relations'),
(7, 'Customer Support',   'Post-sale support and client satisfaction'),
(8, 'Product',            'Product management, roadmap and design'),
(9, 'Legal & Compliance', 'Legal affairs, contracts, and regulatory compliance'),
(10,'IT Infrastructure',  'Internal IT, networks, hardware, and security')
ON DUPLICATE KEY UPDATE `department_name`=VALUES(`department_name`);

-- -----------------------------------------------
-- Users (password for all: 'password')
-- bcrypt hash of 'password'
-- -----------------------------------------------
INSERT INTO `users` (`id`,`username`,`display_name`,`email`,`password`,`role`,`is_verified`) VALUES
(1,  'admin',      'System Administrator', 'admin@company.com',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',    1),
(2,  'hrmanager',  'Priya Sharma',         'hr@company.com',           '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr',       1),
(3,  'john.doe',   'John Doe',             'john.doe@company.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1),
(4,  'jane.smith', 'Jane Smith',           'jane.smith@company.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1),
(5,  'raj.kumar',  'Raj Kumar',            'raj.kumar@company.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1),
(6,  'aisha.khan', 'Aisha Khan',           'aisha.khan@company.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1),
(7,  'tom.wilson', 'Tom Wilson',           'tom.wilson@company.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1),
(8,  'sara.lee',   'Sara Lee',             'sara.lee@company.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1),
(9,  'amit.patel', 'Amit Patel',           'amit.patel@company.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1),
(10, 'lisa.chen',  'Lisa Chen',            'lisa.chen@company.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1)
ON DUPLICATE KEY UPDATE `display_name`=VALUES(`display_name`);

-- Note: All demo passwords are 'password'. Change immediately in production!

-- -----------------------------------------------
-- Employees
-- -----------------------------------------------
INSERT INTO `employees` (`id`,`user_id`,`employee_id`,`name`,`email`,`phone`,`department_id`,`designation`,`joining_date`,`salary`,`status`) VALUES
(1,  1,  'EMP001', 'System Administrator',  'admin@company.com',        '9000000001', 1,  'Administrator',        '2022-01-01', 200000.00, 'active'),
(2,  2,  'EMP002', 'Priya Sharma',          'hr@company.com',           '9000000002', 1,  'HR Manager',           '2022-03-15',  90000.00, 'active'),
(3,  3,  'EMP003', 'John Doe',              'john.doe@company.com',     '9000000003', 2,  'Senior Software Eng',  '2023-06-01',  85000.00, 'active'),
(4,  4,  'EMP004', 'Jane Smith',            'jane.smith@company.com',   '9000000004', 4,  'Marketing Specialist', '2023-01-10',  65000.00, 'active'),
(5,  5,  'EMP005', 'Raj Kumar',             'raj.kumar@company.com',    '9000000005', 2,  'Backend Developer',    '2022-09-01',  78000.00, 'active'),
(6,  6,  'EMP006', 'Aisha Khan',            'aisha.khan@company.com',   '9000000006', 8,  'Product Manager',      '2022-07-20',  95000.00, 'active'),
(7,  7,  'EMP007', 'Tom Wilson',            'tom.wilson@company.com',   '9000000007', 6,  'Sales Executive',      '2023-02-14',  60000.00, 'active'),
(8,  8,  'EMP008', 'Sara Lee',              'sara.lee@company.com',     '9000000008', 7,  'Support Lead',         '2022-11-30',  58000.00, 'active'),
(9,  9,  'EMP009', 'Amit Patel',            'amit.patel@company.com',   '9000000009', 3,  'Finance Analyst',      '2023-04-01',  72000.00, 'active'),
(10, 10, 'EMP010', 'Lisa Chen',             'lisa.chen@company.com',    '9000000010', 10, 'IT Engineer',          '2022-05-15',  80000.00, 'active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- -----------------------------------------------
-- Attendance — Multi-session seeding (demo)
-- Showing scenario: employee logs out early and re-logs
-- -----------------------------------------------
INSERT INTO `attendance` (`employee_id`,`session_no`,`check_in`,`check_out`,`date`,`status`,`entry_method`) VALUES
-- EMP003 - John Doe - today sample: login 8:10, logout 8:20 (5 min), re-login 8:22, logout 17:15
(3, 1, CONCAT(CURDATE(),' 08:10:00'), CONCAT(CURDATE(),' 08:20:00'), CURDATE(), 'present', 'manual'),
(3, 2, CONCAT(CURDATE(),' 08:22:00'), NULL,                           CURDATE(), 'present', 'manual'),

-- EMP005 - Raj Kumar - normal full day
(5, 1, CONCAT(CURDATE(),' 09:00:00'), CONCAT(CURDATE(),' 17:30:00'), CURDATE(), 'present', 'manual'),

-- EMP002 - HR Manager
(2, 1, CONCAT(CURDATE(),' 08:45:00'), CONCAT(CURDATE(),' 18:00:00'), CURDATE(), 'present', 'manual'),

-- Historical data for current month
(3, 1, DATE_SUB(CURDATE(),INTERVAL 1 DAY), CONCAT(DATE_SUB(CURDATE(),INTERVAL 1 DAY),' 17:00:00'),
       DATE_SUB(CURDATE(),INTERVAL 1 DAY), 'present', 'manual'),
(5, 1, DATE_SUB(CURDATE(),INTERVAL 1 DAY), CONCAT(DATE_SUB(CURDATE(),INTERVAL 1 DAY),' 17:30:00'),
       DATE_SUB(CURDATE(),INTERVAL 1 DAY), 'present', 'manual'),
(4, 1, DATE_SUB(CURDATE(),INTERVAL 2 DAY), CONCAT(DATE_SUB(CURDATE(),INTERVAL 2 DAY),' 16:00:00'),
       DATE_SUB(CURDATE(),INTERVAL 2 DAY), 'half_day', 'manual'),
(6, 1, DATE_SUB(CURDATE(),INTERVAL 2 DAY), CONCAT(DATE_SUB(CURDATE(),INTERVAL 2 DAY),' 18:30:00'),
       DATE_SUB(CURDATE(),INTERVAL 2 DAY), 'present', 'manual')
ON DUPLICATE KEY UPDATE `check_out`=VALUES(`check_out`);

-- -----------------------------------------------
-- Attendance Daily Summary
-- -----------------------------------------------
INSERT INTO `attendance_daily_summary` (`employee_id`,`date`,`total_minutes`,`session_count`,`status`) VALUES
(3, CURDATE(), 10, 1, 'present'),  -- Only session 1 counted so far (5 min), session 2 ongoing
(5, CURDATE(), 510, 1, 'present'), -- 8.5 hrs
(2, CURDATE(), 555, 1, 'present')  -- 9.25 hrs
ON DUPLICATE KEY UPDATE `total_minutes`=VALUES(`total_minutes`);

-- -----------------------------------------------
-- Leave Requests
-- -----------------------------------------------
INSERT INTO `leave_requests` (`employee_id`,`leave_type`,`start_date`,`end_date`,`reason`,`status`) VALUES
(3, 'sick',   '2026-02-10', '2026-02-11', 'Fever and cold',           'approved'),
(4, 'casual', '2026-01-25', '2026-01-25', 'Personal work',            'approved'),
(5, 'paid',   '2026-03-20', '2026-03-24', 'Family vacation',          'pending'),
(7, 'sick',   '2026-03-05', '2026-03-06', 'Medical appointment',      'rejected'),
(8, 'casual', '2026-03-12', '2026-03-12', 'Bank work',                'pending');

-- -----------------------------------------------
-- Payroll (last 3 months)
-- -----------------------------------------------
INSERT INTO `payroll` (`employee_id`,`basic_salary`,`bonus`,`deductions`,`net_salary`,`payment_date`,`month_year`) VALUES
(3,  85000, 5000, 8000,  82000, '2026-01-31', '2026-01'),
(4,  65000, 2000, 6000,  61000, '2026-01-31', '2026-01'),
(5,  78000, 3000, 7500,  73500, '2026-01-31', '2026-01'),
(3,  85000, 0,    8000,  77000, '2026-02-28', '2026-02'),
(5,  78000, 0,    7500,  70500, '2026-02-28', '2026-02'),
(2,  90000, 5000, 9000,  86000, '2026-02-28', '2026-02');

-- -----------------------------------------------
-- Leave Balances
-- -----------------------------------------------
INSERT IGNORE INTO `leave_balances` (`employee_id`,`year`,`sick_used`,`casual_used`,`paid_used`) VALUES
(2, 2026, 0, 1, 0),
(3, 2026, 2, 0, 0),
(4, 2026, 0, 1, 0),
(5, 2026, 0, 0, 5),
(6, 2026, 1, 2, 0),
(7, 2026, 2, 0, 0),
(8, 2026, 0, 1, 0),
(9, 2026, 0, 0, 0),
(10,2026, 1, 0, 0);

-- ============================================================
-- MIGRATION v2 — Run this block ONLY when upgrading from v1
-- (Comment out if doing a fresh install)
-- ============================================================
/*
-- Step 1: Rename old attendance table
ALTER TABLE `attendance` RENAME TO `attendance_v1_backup`;

-- Step 2: Create new attendance table (already above)

-- Step 3: Migrate existing data into new table
INSERT INTO `attendance` (`employee_id`,`session_no`,`check_in`,`check_out`,`date`,`status`)
SELECT `employee_id`, 1, `check_in`, `check_out`, `date`, `status`
FROM `attendance_v1_backup`;

-- Step 4: Populate daily summary from migrated data
INSERT INTO `attendance_daily_summary` (`employee_id`,`date`,`total_minutes`,`session_count`,`status`)
SELECT
  employee_id,
  date,
  COALESCE(SUM(TIMESTAMPDIFF(MINUTE, check_in, check_out)), 0) AS total_minutes,
  COUNT(*) AS session_count,
  MAX(status) AS status
FROM `attendance`
WHERE check_in IS NOT NULL AND check_out IS NOT NULL
GROUP BY employee_id, date
ON DUPLICATE KEY UPDATE total_minutes=VALUES(total_minutes), session_count=VALUES(session_count);
*/
