-- Strumenti per far crescere l'agenzia: audit dei potenziali clienti, stile appreso dall'AI, ore e redditività,
-- risposte alle recensioni, portale del cliente e monitoraggio dei concorrenti.

-- Audit del sito di un potenziale cliente, con proposta commerciale e link pubblico.
CREATE TABLE prospects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  url VARCHAR(2048) NOT NULL,
  name VARCHAR(160) NULL,
  status ENUM('new','sent','won','lost') NOT NULL DEFAULT 'new',
  audit JSON NULL,
  job_id BIGINT UNSIGNED NULL,
  monthly_fee DECIMAL(10,2) NULL,
  agency_note TEXT NULL,
  brand_profile_id BIGINT UNSIGNED NULL,
  share_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  shared_at DATETIME NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  last_viewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prospects_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_prospects_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_prospects_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE SET NULL,
  UNIQUE KEY uq_prospects_token (share_token),
  INDEX idx_prospects_org (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Testo scritto dall'AI, per confrontarlo con la versione approvata e imparare lo stile del cliente.
ALTER TABLE content_items
  ADD COLUMN ai_body MEDIUMTEXT NULL AFTER body,
  ADD COLUMN reviewed_via ENUM('app','portal') NULL AFTER reviewed_at;

CREATE TABLE style_examples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  content_item_id BIGINT UNSIGNED NULL,
  channel VARCHAR(20) NOT NULL,
  kind ENUM('edit','feedback') NOT NULL,
  before_text MEDIUMTEXT NULL,
  after_text MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_style_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_style_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_style_content FOREIGN KEY (content_item_id) REFERENCES content_items(id) ON DELETE SET NULL,
  INDEX idx_style_brand (organization_id, brand_profile_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Ore lavorate per cliente e costo orario dell'agenzia, per calcolare il margine.
ALTER TABLE organizations ADD COLUMN hourly_cost DECIMAL(8,2) NULL AFTER slug;

CREATE TABLE time_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  worked_on DATE NOT NULL,
  minutes INT UNSIGNED NOT NULL,
  note VARCHAR(300) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_time_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_time_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_time_org_date (organization_id, worked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Risposte alle recensioni salvate.
CREATE TABLE review_replies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  platform VARCHAR(20) NOT NULL,
  author VARCHAR(120) NULL,
  rating TINYINT UNSIGNED NOT NULL,
  review_text TEXT NOT NULL,
  reply TEXT NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reviews_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_reviews_org (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Portale del cliente: un link senza account per approvare i post, vedere i report e fare richieste.
ALTER TABLE brand_profiles
  ADD COLUMN portal_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER ads_setup_steps,
  ADD UNIQUE KEY uq_brands_portal (portal_token);

CREATE TABLE client_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NULL,
  message TEXT NOT NULL,
  status ENUM('open','done') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_requests_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_requests_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  INDEX idx_requests_org (organization_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Concorrenti di un cliente, con l'ultima analisi del loro sito.
CREATE TABLE competitors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  url VARCHAR(2048) NOT NULL,
  analysis JSON NULL,
  job_id BIGINT UNSIGNED NULL,
  last_checked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_competitors_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_competitors_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_competitors_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_competitors_brand (organization_id, brand_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
