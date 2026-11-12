-- PAYWISE — role-based access migration
-- Run once on an existing database (phpMyAdmin SQL tab or mysql CLI).
-- Fresh installs do NOT need this: paywise_schema.sql / schema.sql already
-- include the role columns.
--
-- What it does:
-- 1. Adds role + is_active to admins (idempotent: safe to run twice).
-- 2. Promotes every existing admin to superadmin (first-run bootstrap).

SET time_zone = '+00:00';

-- role column (ENUM keeps the role list closed at the DB level too)
SET @has_role := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'role');
SET @add_role := IF(@has_role = 0,
    'ALTER TABLE admins ADD COLUMN role ENUM(''superadmin'',''finance'',''hr'') NOT NULL DEFAULT ''superadmin'' AFTER full_name',
    'SELECT 1');
PREPARE stmt FROM @add_role; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- is_active column (deactivation keeps history; rows are never deleted)
SET @has_active := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'is_active');
SET @add_active := IF(@has_active = 0,
    'ALTER TABLE admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
    'SELECT 1');
PREPARE stmt FROM @add_active; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Bootstrap: every pre-roles account becomes superadmin and stays active.
UPDATE admins SET role = 'superadmin', is_active = 1 WHERE role = 'superadmin';
