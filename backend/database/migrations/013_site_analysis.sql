-- Lettura approfondita del sito del cliente (dati, strumenti, controlli) e diagnosi strategica dell'AI, una per cliente.
CREATE TABLE site_analyses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  profile JSON NULL,
  analysis JSON NULL,
  job_id BIGINT UNSIGNED NULL,
  profile_at DATETIME NULL,
  analyzed_at DATETIME NULL,
  CONSTRAINT fk_site_analysis_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_site_analysis_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  UNIQUE KEY uq_site_analysis_brand (organization_id, brand_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
