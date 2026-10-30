-- Collegamento diretto a Meta (Marketing API) per cliente: chi ha fatto l'accesso, codice di accesso cifrato,
-- pagina, Instagram e account pubblicitario scelti. Le sponsorizzate pubblicate tengono gli ID creati su Meta
-- e la spesa letta da Meta finisce in ad_spend, collegata alla sponsorizzata.

CREATE TABLE meta_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  connected_by BIGINT UNSIGNED NULL,
  sample TINYINT(1) NOT NULL DEFAULT 0,
  meta_user_id VARCHAR(40) NOT NULL,
  meta_user_name VARCHAR(190) NOT NULL DEFAULT '',
  access_token TEXT NOT NULL,
  token_expires_at DATETIME NULL,
  scopes JSON NULL,
  ad_account_id VARCHAR(30) NULL,
  ad_account_name VARCHAR(190) NULL,
  currency CHAR(3) NULL,
  page_id VARCHAR(40) NULL,
  page_name VARCHAR(190) NULL,
  instagram_id VARCHAR(40) NULL,
  instagram_username VARCHAR(190) NULL,
  status ENUM('active','expired','error') NOT NULL DEFAULT 'active',
  last_error VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_meta_conn_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_meta_conn_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_meta_conn_user FOREIGN KEY (connected_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_meta_conn_brand (brand_profile_id),
  KEY idx_meta_conn_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Accessi a Facebook in corso: il codice "state" torna da Meta e dice per quale cliente era. Monouso, scade in 15 minuti.
CREATE TABLE meta_oauth_states (
  state CHAR(64) NOT NULL PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  CONSTRAINT fk_meta_state_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_meta_state_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE ad_plans
  ADD COLUMN image_asset_id BIGINT UNSIGNED NULL AFTER content_item_id,
  ADD COLUMN meta_campaign_id VARCHAR(40) NULL AFTER status,
  ADD COLUMN meta_adset_id VARCHAR(40) NULL AFTER meta_campaign_id,
  ADD COLUMN meta_image_hash VARCHAR(64) NULL AFTER meta_adset_id,
  ADD COLUMN meta_creative_id VARCHAR(40) NULL AFTER meta_image_hash,
  ADD COLUMN meta_ad_id VARCHAR(40) NULL AFTER meta_creative_id,
  ADD COLUMN meta_status VARCHAR(40) NULL AFTER meta_ad_id,
  ADD COLUMN meta_feedback JSON NULL AFTER meta_status,
  ADD COLUMN meta_job_id BIGINT UNSIGNED NULL AFTER meta_feedback,
  ADD COLUMN meta_sample TINYINT(1) NOT NULL DEFAULT 0 AFTER meta_job_id,
  ADD COLUMN published_at DATETIME NULL AFTER meta_sample,
  ADD COLUMN activated_at DATETIME NULL AFTER published_at,
  ADD COLUMN meta_synced_at DATETIME NULL AFTER activated_at,
  ADD CONSTRAINT fk_ad_plan_image FOREIGN KEY (image_asset_id) REFERENCES assets(id) ON DELETE SET NULL;

ALTER TABLE ad_spend
  MODIFY COLUMN source ENUM('manual','csv','meta') NOT NULL DEFAULT 'manual',
  ADD COLUMN ad_plan_id BIGINT UNSIGNED NULL AFTER project_id,
  ADD CONSTRAINT fk_adspend_plan FOREIGN KEY (ad_plan_id) REFERENCES ad_plans(id) ON DELETE SET NULL,
  -- Spesa letta da Meta: una riga per sponsorizzata e mese, aggiornata a ogni lettura.
  ADD UNIQUE KEY uq_adspend_plan_month (ad_plan_id, period_start);
