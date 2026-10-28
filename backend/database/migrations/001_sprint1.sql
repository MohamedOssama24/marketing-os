-- Sprint 1: rate limiting, brand kit, archivio file, coda lavori, revisione contenuti.

CREATE TABLE auth_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_attempts_key_created (key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  kind ENUM('image','video','document') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_assets_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_assets_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_assets_org_created (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE brand_profiles
  ADD COLUMN primary_color CHAR(7) NULL AFTER brand_guidelines,
  ADD COLUMN secondary_color CHAR(7) NULL AFTER primary_color,
  ADD COLUMN font_family VARCHAR(120) NULL AFTER secondary_color,
  ADD COLUMN logo_asset_id BIGINT UNSIGNED NULL AFTER font_family,
  ADD COLUMN products TEXT NULL AFTER logo_asset_id,
  ADD COLUMN keywords TEXT NULL AFTER products,
  ADD COLUMN words_to_avoid TEXT NULL AFTER keywords,
  ADD CONSTRAINT fk_brands_logo FOREIGN KEY (logo_asset_id) REFERENCES assets(id) ON DELETE SET NULL;

CREATE TABLE jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  type VARCHAR(60) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  result JSON NULL,
  error VARCHAR(500) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  CONSTRAINT fk_jobs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_jobs_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_jobs_status (status, id),
  INDEX idx_jobs_org (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE content_items
  ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER ai_generated,
  ADD COLUMN review_note VARCHAR(1000) NULL AFTER job_id,
  ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER review_note,
  ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
  ADD CONSTRAINT fk_content_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_content_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;
