-- Inserzioni dei concorrenti lette dall'archivio inserzioni di Meta: chi raggiungono, dove, da quanto tempo,
-- con la spesa stimata dalla copertura (Meta pubblica la spesa solo per le inserzioni politiche).

ALTER TABLE competitors
  ADD COLUMN meta_page_id VARCHAR(40) NULL,
  ADD COLUMN ads_job_id BIGINT UNSIGNED NULL,
  ADD COLUMN ads_checked_at DATETIME NULL,
  ADD COLUMN ads_error VARCHAR(300) NULL,
  ADD COLUMN ads_summary JSON NULL,
  ADD COLUMN ads_new_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN ads_new_at DATETIME NULL;

CREATE TABLE competitor_ads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  competitor_id BIGINT UNSIGNED NOT NULL,
  archive_id VARCHAR(40) NOT NULL,
  page_id VARCHAR(40) NOT NULL,
  page_name VARCHAR(190) NOT NULL DEFAULT '',
  bodies JSON NOT NULL,
  titles JSON NOT NULL,
  captions JSON NOT NULL,
  platforms JSON NOT NULL,
  started_on DATE NOT NULL,
  stopped_on DATE NULL,
  eu_reach INT UNSIGNED NULL,
  demographics JSON NULL,
  target_locations JSON NULL,
  target_ages JSON NULL,
  target_gender VARCHAR(20) NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  CONSTRAINT fk_competitor_ad_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_competitor_ad_competitor FOREIGN KEY (competitor_id) REFERENCES competitors(id) ON DELETE CASCADE,
  UNIQUE KEY uq_competitor_ad (competitor_id, archive_id),
  KEY idx_competitor_ad_started (competitor_id, started_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Costo medio per 1.000 visualizzazioni usato per stimare la spesa dei concorrenti (NULL = valore predefinito).
ALTER TABLE organizations ADD COLUMN ads_cpm DECIMAL(6,2) NULL AFTER hourly_cost;
