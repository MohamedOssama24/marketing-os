-- Registro delle sponsorizzate per cliente e fatturazione mensile (canone, spese pubblicitarie rifatturate, commissione).

ALTER TABLE brand_profiles
  ADD COLUMN monthly_fee DECIMAL(10,2) NULL AFTER words_to_avoid,
  ADD COLUMN ad_fee_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER monthly_fee,
  ADD COLUMN billing_name VARCHAR(190) NULL AFTER ad_fee_percent,
  ADD COLUMN vat_number VARCHAR(30) NULL AFTER billing_name,
  ADD COLUMN tax_code VARCHAR(30) NULL AFTER vat_number,
  ADD COLUMN sdi_code VARCHAR(7) NULL AFTER tax_code,
  ADD COLUMN pec VARCHAR(190) NULL AFTER sdi_code,
  ADD COLUMN billing_address VARCHAR(500) NULL AFTER pec,
  ADD COLUMN billing_email VARCHAR(190) NULL AFTER billing_address;

CREATE TABLE invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  status ENUM('to_invoice','invoiced','paid') NOT NULL DEFAULT 'to_invoice',
  number VARCHAR(40) NULL,
  issued_on DATE NULL,
  due_on DATE NULL,
  paid_on DATE NULL,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 22,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoices_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_invoices_brand_period (organization_id, brand_profile_id, period),
  INDEX idx_invoices_org_period (organization_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ad_spend (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  invoice_id BIGINT UNSIGNED NULL,
  platform ENUM('meta','google','tiktok','linkedin','other') NOT NULL DEFAULT 'meta',
  campaign_name VARCHAR(190) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  budget DECIMAL(12,2) NULL,
  spent DECIMAL(12,2) NOT NULL,
  impressions INT UNSIGNED NULL,
  reach INT UNSIGNED NULL,
  clicks INT UNSIGNED NULL,
  results INT UNSIGNED NULL,
  source ENUM('manual','csv') NOT NULL DEFAULT 'manual',
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_adspend_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_adspend_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_adspend_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_adspend_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_adspend_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  -- Reimportare lo stesso CSV aggiorna le righe invece di duplicarle.
  UNIQUE KEY uq_adspend_row (organization_id, brand_profile_id, platform, campaign_name, period_start, period_end),
  INDEX idx_adspend_org_period (organization_id, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE invoice_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('fee','ad_spend','ad_fee','other') NOT NULL,
  description VARCHAR(300) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  ad_spend_id BIGINT UNSIGNED NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_lines_spend FOREIGN KEY (ad_spend_id) REFERENCES ad_spend(id) ON DELETE SET NULL,
  INDEX idx_lines_invoice (invoice_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
