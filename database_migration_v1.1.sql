-- ============================================================
-- EmpAxis v1.1 – Migration SQL
-- Run this AFTER the original database.sql
-- ============================================================

USE `employee_management`;

-- -----------------------------------------------
-- Table: activity_log  (Feature 4)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `username`    VARCHAR(50)  DEFAULT NULL,
  `role`        VARCHAR(20)  DEFAULT NULL,
  `action`      VARCHAR(100) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `module`      VARCHAR(50)  DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user`   (`user_id`),
  INDEX `idx_module` (`module`),
  INDEX `idx_created`(`created_at`)
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Table: leave_balances  (Feature 7)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `leave_balances` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id`  INT UNSIGNED NOT NULL,
  `year`         YEAR NOT NULL DEFAULT (YEAR(CURDATE())),
  `casual_quota` TINYINT UNSIGNED DEFAULT 10,
  `sick_quota`   TINYINT UNSIGNED DEFAULT 10,
  `paid_quota`   TINYINT UNSIGNED DEFAULT 15,
  `casual_used`  TINYINT UNSIGNED DEFAULT 0,
  `sick_used`    TINYINT UNSIGNED DEFAULT 0,
  `paid_used`    TINYINT UNSIGNED DEFAULT 0,
  UNIQUE KEY `uk_emp_year` (`employee_id`, `year`),
  CONSTRAINT `fk_lb_emp` FOREIGN KEY (`employee_id`)
    REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- Seed leave balances for existing employees
-- -----------------------------------------------
INSERT IGNORE INTO `leave_balances` (`employee_id`, `year`)
SELECT `id`, YEAR(CURDATE()) FROM `employees`;

-- -----------------------------------------------
-- SMTP config table  (Feature 5 – optional override)
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `smtp_config` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `host`       VARCHAR(150) DEFAULT 'smtp.gmail.com',
  `port`       SMALLINT     DEFAULT 587,
  `encryption` VARCHAR(10)  DEFAULT 'tls',
  `username`   VARCHAR(150) DEFAULT '',
  `password`   VARCHAR(255) DEFAULT '',
  `from_email` VARCHAR(150) DEFAULT 'noreply@company.com',
  `from_name`  VARCHAR(100) DEFAULT 'EmpAxis HR',
  `updated_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `smtp_config` (`id`) VALUES (1);
