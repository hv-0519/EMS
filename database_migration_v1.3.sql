-- ============================================================
-- EmpAxis v1.3 — Extended Profile Schema Migration
-- Run AFTER database.sql, v1.1, and v1.2 migrations
-- ============================================================

USE `employee_management`;

-- ── Users: richer profile data ─────────────────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `display_name`   VARCHAR(120)  DEFAULT NULL AFTER `username`,
  ADD COLUMN IF NOT EXISTS `avatar`         VARCHAR(255)  DEFAULT NULL AFTER `role`,
  ADD COLUMN IF NOT EXISTS `bio`            TEXT          DEFAULT NULL AFTER `avatar`,
  ADD COLUMN IF NOT EXISTS `last_login_at`  DATETIME      DEFAULT NULL AFTER `verification_token`,
  ADD COLUMN IF NOT EXISTS `last_login_ip`  VARCHAR(45)   DEFAULT NULL AFTER `last_login_at`,
  ADD COLUMN IF NOT EXISTS `updated_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ── Employees: comprehensive profile ───────────────────────
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `date_of_birth`    DATE           DEFAULT NULL AFTER `joining_date`,
  ADD COLUMN IF NOT EXISTS `gender`           ENUM('male','female','other') DEFAULT NULL AFTER `date_of_birth`,
  ADD COLUMN IF NOT EXISTS `blood_group`      VARCHAR(5)     DEFAULT NULL AFTER `gender`,
  ADD COLUMN IF NOT EXISTS `emergency_name`   VARCHAR(120)   DEFAULT NULL AFTER `blood_group`,
  ADD COLUMN IF NOT EXISTS `emergency_phone`  VARCHAR(20)    DEFAULT NULL AFTER `emergency_name`,
  ADD COLUMN IF NOT EXISTS `city`             VARCHAR(100)   DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `state`            VARCHAR(100)   DEFAULT NULL AFTER `city`,
  ADD COLUMN IF NOT EXISTS `country`          VARCHAR(100)   DEFAULT NULL AFTER `state`,
  ADD COLUMN IF NOT EXISTS `postal_code`      VARCHAR(20)    DEFAULT NULL AFTER `country`,
  ADD COLUMN IF NOT EXISTS `linkedin_url`     VARCHAR(255)   DEFAULT NULL AFTER `postal_code`,
  ADD COLUMN IF NOT EXISTS `bank_name`        VARCHAR(120)   DEFAULT NULL AFTER `linkedin_url`,
  ADD COLUMN IF NOT EXISTS `bank_account`     VARCHAR(30)    DEFAULT NULL AFTER `bank_name`,
  ADD COLUMN IF NOT EXISTS `bank_ifsc`        VARCHAR(20)    DEFAULT NULL AFTER `bank_account`,
  ADD COLUMN IF NOT EXISTS `pan_number`       VARCHAR(20)    DEFAULT NULL AFTER `bank_ifsc`,
  ADD COLUMN IF NOT EXISTS `updated_at`       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ── Backfill display_name and avatar ───────────────────────
UPDATE `users` u
LEFT JOIN `employees` e ON e.user_id = u.id
SET
  u.display_name = COALESCE(u.display_name, e.name, u.username),
  u.avatar       = COALESCE(u.avatar, e.photo)
WHERE u.display_name IS NULL OR u.avatar IS NULL;

-- ── Ensure indexes for new columns ─────────────────────────
CREATE INDEX IF NOT EXISTS `idx_emp_city`    ON `employees`(`city`);
CREATE INDEX IF NOT EXISTS `idx_emp_gender`  ON `employees`(`gender`);
CREATE INDEX IF NOT EXISTS `idx_emp_dob`     ON `employees`(`date_of_birth`);
