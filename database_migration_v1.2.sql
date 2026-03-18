-- ============================================================
-- EmpAxis v1.2 – Auth/Profile Schema Upgrade
-- Run this AFTER database.sql and database_migration_v1.1.sql
-- ============================================================

USE `employee_management`;

-- -----------------------------------------------
-- Users: profile + login metadata
-- -----------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(120) DEFAULT NULL AFTER `username`,
  ADD COLUMN IF NOT EXISTS `avatar` VARCHAR(255) DEFAULT NULL AFTER `role`,
  ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME DEFAULT NULL AFTER `verification_token`,
  ADD COLUMN IF NOT EXISTS `last_login_ip` VARCHAR(45) DEFAULT NULL AFTER `last_login_at`;

-- -----------------------------------------------
-- Employees: richer profile attributes
-- -----------------------------------------------
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `date_of_birth` DATE DEFAULT NULL AFTER `joining_date`,
  ADD COLUMN IF NOT EXISTS `gender` ENUM('male','female','other') DEFAULT NULL AFTER `date_of_birth`,
  ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `state` VARCHAR(100) DEFAULT NULL AFTER `city`,
  ADD COLUMN IF NOT EXISTS `country` VARCHAR(100) DEFAULT NULL AFTER `state`,
  ADD COLUMN IF NOT EXISTS `postal_code` VARCHAR(20) DEFAULT NULL AFTER `country`;

-- -----------------------------------------------
-- Backfill display_name/avatar for existing users
-- -----------------------------------------------
UPDATE `users` u
LEFT JOIN `employees` e ON e.user_id = u.id
SET
  u.display_name = COALESCE(u.display_name, e.name, u.username),
  u.avatar       = COALESCE(u.avatar, e.photo)
WHERE u.display_name IS NULL OR u.avatar IS NULL;

