-- Foto e file collegati a un cliente (ad esempio le foto lette dal suo sito).

ALTER TABLE assets
  ADD COLUMN brand_profile_id BIGINT UNSIGNED NULL AFTER uploaded_by,
  ADD CONSTRAINT fk_assets_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE SET NULL,
  ADD INDEX idx_assets_org_brand (organization_id, brand_profile_id);
