-- Report mensile per il cliente: i numeri si calcolano dai dati del mese, qui si salvano
-- il commento dell'agenzia e il link pubblico con cui il cliente lo apre senza accedere.
CREATE TABLE client_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  summary TEXT NULL,
  next_steps TEXT NULL,
  share_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  shared_at DATETIME NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  last_viewed_at DATETIME NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reports_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_reports_brand_period (organization_id, brand_profile_id, period),
  UNIQUE KEY uq_reports_token (share_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
