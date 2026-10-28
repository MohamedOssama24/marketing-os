-- Confronto con i concorrenti e proposta di stile, una per cliente.
CREATE TABLE competition_studies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  client_site JSON NULL,
  market TEXT NULL,
  gaps JSON NULL,
  proposal JSON NULL,
  previous_style JSON NULL,
  applied_at DATETIME NULL,
  job_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NULL,
  CONSTRAINT fk_competition_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_competition_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  UNIQUE KEY uq_competition_brand (organization_id, brand_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
