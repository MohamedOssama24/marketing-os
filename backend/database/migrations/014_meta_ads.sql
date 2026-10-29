-- Regole di Meta per le sponsorizzate: pagine ufficiali controllate ogni giorno, cambiamenti trovati,
-- sponsorizzate preparate con la procedura guidata e il loro controllo.

CREATE TABLE meta_policy_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  topic VARCHAR(300) NOT NULL,
  url VARCHAR(500) NOT NULL,
  content MEDIUMTEXT NULL,
  content_hash CHAR(64) NULL,
  checked_at DATETIME NULL,
  changed_at DATETIME NULL,
  last_error VARCHAR(300) NULL,
  UNIQUE KEY uq_meta_policy_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE meta_policy_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  detected_at DATETIME NOT NULL,
  added JSON NOT NULL,
  removed JSON NOT NULL,
  summary JSON NULL,
  CONSTRAINT fk_meta_change_source FOREIGN KEY (source_id) REFERENCES meta_policy_sources(id) ON DELETE CASCADE,
  KEY idx_meta_change_detected (detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE organizations ADD COLUMN meta_rules_seen_at DATETIME NULL;

CREATE TABLE ad_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  content_item_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  objective ENUM('awareness','traffic','engagement','leads','app_promotion','sales') NOT NULL,
  special_category ENUM('none','financial','employment','housing','social_issues') NOT NULL DEFAULT 'none',
  audience JSON NOT NULL,
  daily_budget DECIMAL(10,2) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  primary_text TEXT NOT NULL,
  headline VARCHAR(255) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  cta VARCHAR(40) NOT NULL,
  url VARCHAR(2048) NULL,
  checks JSON NULL,
  review JSON NULL,
  review_job_id BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  status ENUM('draft','ready','live','ended') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ad_plan_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ad_plan_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_ad_plan_content FOREIGN KEY (content_item_id) REFERENCES content_items(id) ON DELETE SET NULL,
  KEY idx_ad_plan_org_brand (organization_id, brand_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
