-- Richieste fatte all'AI (per i limiti giornalieri) e risposte salvate per non rifare le stesse generazioni.
CREATE TABLE ai_usage (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  brand_profile_id BIGINT UNSIGNED NULL,
  provider VARCHAR(20) NOT NULL,
  automatic TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_ai_usage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_usage_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE SET NULL,
  INDEX idx_ai_usage_day (created_at),
  INDEX idx_ai_usage_brand (brand_profile_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_cache (
  cache_key CHAR(64) NOT NULL PRIMARY KEY,
  response JSON NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_ai_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- I lavori automatici rimandati quando il limite del giorno è raggiunto.
ALTER TABLE jobs ADD COLUMN run_after DATETIME NULL AFTER attempts;
