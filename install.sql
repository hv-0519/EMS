-- ============================================================
-- EmpAxis – COMPLETE INSTALLATION SQL
-- Run this single file in phpMyAdmin to set up everything.
-- This combines database.sql + all migrations into one file.
-- ============================================================

-- 1. Create and use database
CREATE DATABASE IF NOT EXISTS `employee_management`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `employee_management`;

-- 2. Core tables
CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `department_name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `display_name` VARCHAR(120) DEFAULT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','hr','employee') NOT NULL DEFAULT 'employee',
  `avatar` VARCHAR(255) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `verification_token` VARCHAR(100) DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Force password change on first login',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `employee_id` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `designation` VARCHAR(100) DEFAULT NULL,
  `joining_date` DATE DEFAULT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `gender` ENUM('male','female','other') DEFAULT NULL,
  `blood_group` VARCHAR(5) DEFAULT NULL,
  `emergency_name` VARCHAR(120) DEFAULT NULL,
  `emergency_phone` VARCHAR(20) DEFAULT NULL,
  `salary` DECIMAL(12,2) DEFAULT 0.00,
  `hourly_rate` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Per-hour pay rate for hourly payroll',
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(20) DEFAULT NULL,
  `linkedin_url` VARCHAR(255) DEFAULT NULL,
  `bank_name` VARCHAR(120) DEFAULT NULL,
  `bank_account` VARCHAR(30) DEFAULT NULL,
  `bank_ifsc` VARCHAR(20) DEFAULT NULL,
  `pan_number` VARCHAR(20) DEFAULT NULL,
  `photo` VARCHAR(255) DEFAULT 'default.png',
  `status` ENUM('active','inactive','on_leave') DEFAULT 'active',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_emp_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT UNSIGNED NOT NULL,
  `check_in` DATETIME DEFAULT NULL,
  `check_out` DATETIME DEFAULT NULL,
  `date` DATE NOT NULL,
  `status` ENUM('present','absent','half_day','late') DEFAULT 'present',
  UNIQUE KEY `uk_emp_date` (`employee_id`,`date`),
  CONSTRAINT `fk_att_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT UNSIGNED NOT NULL,
  `leave_type` ENUM('sick','casual','paid') NOT NULL DEFAULT 'casual',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `status` ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_leave_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `payroll` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT UNSIGNED NOT NULL,
  `basic_salary` DECIMAL(12,2) DEFAULT 0.00,
  `bonus` DECIMAL(12,2) DEFAULT 0.00,
  `deductions` DECIMAL(12,2) DEFAULT 0.00,
  `net_salary` DECIMAL(12,2) DEFAULT 0.00,
  `payment_date` DATE NOT NULL,
  `month_year` VARCHAR(7) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_pay_emp_month` (`employee_id`,`month_year`),
  CONSTRAINT `fk_pay_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info','success','warning','error') DEFAULT 'info',
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(150) NOT NULL,
  `token` VARCHAR(100) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `username` VARCHAR(50) DEFAULT NULL,
  `role` VARCHAR(20) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `module` VARCHAR(50) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user`   (`user_id`),
  INDEX `idx_module` (`module`),
  INDEX `idx_created`(`created_at`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `leave_balances` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT UNSIGNED NOT NULL,
  `year` YEAR NOT NULL,
  `casual_quota` TINYINT UNSIGNED DEFAULT 10,
  `sick_quota` TINYINT UNSIGNED DEFAULT 10,
  `paid_quota` TINYINT UNSIGNED DEFAULT 15,
  `casual_used` TINYINT UNSIGNED DEFAULT 0,
  `sick_used` TINYINT UNSIGNED DEFAULT 0,
  `paid_used` TINYINT UNSIGNED DEFAULT 0,
  UNIQUE KEY `uk_emp_year` (`employee_id`,`year`),
  CONSTRAINT `fk_lb_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS `announcements` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(200) NOT NULL,
  `body`        TEXT NOT NULL,
  `priority`    ENUM('normal','important','urgent') DEFAULT 'normal',
  `target_type` ENUM('all','department','individual') DEFAULT 'all',
  `target_id`   INT UNSIGNED DEFAULT NULL COMMENT 'dept_id or user_id depending on target_type',
  `created_by`  INT UNSIGNED NOT NULL,
  `is_active`   TINYINT(1) DEFAULT 1,
  `expires_at`  DATE DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_target` (`target_type`,`target_id`),
  INDEX `idx_active` (`is_active`,`expires_at`)
) ENGINE=InnoDB COMMENT='Company-wide and targeted announcements';

CREATE TABLE IF NOT EXISTS `announcement_reads` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `announcement_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  `read_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ann_user` (`announcement_id`,`user_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `smtp_config` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `host` VARCHAR(150) DEFAULT 'smtp.gmail.com',
  `port` SMALLINT DEFAULT 587,
  `encryption` VARCHAR(10) DEFAULT 'tls',
  `username` VARCHAR(150) DEFAULT '',
  `password` VARCHAR(255) DEFAULT '',
  `from_email` VARCHAR(150) DEFAULT 'noreply@company.com',
  `from_name` VARCHAR(100) DEFAULT 'EmpAxis HR',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Seed departments
INSERT INTO `departments` (`department_name`, `description`) VALUES
('Human Resources', 'Manages recruitment, training, and employee relations'),
('Engineering', 'Software development and infrastructure'),
('Finance', 'Accounting, budgeting, and financial reporting'),
('Marketing', 'Brand management and growth strategies'),
('Operations', 'Day-to-day business operations'),
('Sales', 'Revenue generation and client relations');

-- 4. Seed users  (password for ALL accounts: password)
INSERT INTO `users` (`username`, `display_name`, `email`, `password`, `role`, `is_verified`) VALUES
('admin', 'System Administrator', 'admin@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1),
('hrmanager', 'HR Manager', 'hr@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr', 1),
('emp48372', 'John Doe', 'john.doe@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1);

-- 5. Seed employees
INSERT INTO `employees` (`user_id`, `employee_id`, `name`, `email`, `phone`, `department_id`, `designation`, `joining_date`, `salary`, `status`) VALUES
(1, 'EMP001', 'System Administrator', 'admin@company.com', '9000000001', 1, 'Administrator', '2022-01-01', 150000.00, 'active'),
(2, 'EMP002', 'HR Manager', 'hr@company.com', '9000000002', 1, 'HR Manager', '2022-03-15', 90000.00, 'active'),
(3, 'EMP003', 'John Doe', 'john.doe@company.com', '9000000003', 2, 'Software Engineer', '2023-06-01', 75000.00, 'active');

-- 6. Seed leave balances
INSERT IGNORE INTO `leave_balances` (`employee_id`, `year`)
SELECT `id`, YEAR(CURDATE()) FROM `employees`;

-- 7. Seed SMTP config placeholder
INSERT IGNORE INTO `smtp_config` (`id`) VALUES (1);

-- Done!
-- Login: admin@company.com / hrmanager / emp48372  →  password: password
