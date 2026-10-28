-- Pannello Admin della piattaforma: stato delle organizzazioni, amministratori, ultimo accesso.

ALTER TABLE organizations
  ADD COLUMN status ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER slug,
  ADD COLUMN suspended_at DATETIME NULL AFTER status,
  ADD COLUMN suspended_reason VARCHAR(500) NULL AFTER suspended_at,
  ADD COLUMN admin_notes TEXT NULL AFTER suspended_reason;

ALTER TABLE users
  ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
  ADD COLUMN last_login_at DATETIME NULL AFTER is_platform_admin;
