-- Kit di pubblicazione: stato "pubblicato" con data e link, profili social del cliente.

ALTER TABLE content_items
  ADD COLUMN published_at DATETIME NULL AFTER scheduled_at,
  ADD COLUMN published_url VARCHAR(2048) NULL AFTER published_at;

ALTER TABLE brand_profiles
  ADD COLUMN instagram_url VARCHAR(2048) NULL AFTER website_url,
  ADD COLUMN facebook_url VARCHAR(2048) NULL AFTER instagram_url,
  ADD COLUMN linkedin_url VARCHAR(2048) NULL AFTER facebook_url;
