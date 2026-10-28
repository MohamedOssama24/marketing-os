-- Configurazione delle sponsorizzate Meta per cliente: chi paga, account pubblicitario e passaggi completati.

ALTER TABLE brand_profiles
  ADD COLUMN ads_payer ENUM('client','agency') NULL AFTER billing_email,
  ADD COLUMN meta_ad_account_id VARCHAR(30) NULL AFTER ads_payer,
  ADD COLUMN meta_business_id VARCHAR(30) NULL AFTER meta_ad_account_id,
  ADD COLUMN ads_setup_steps JSON NULL AFTER meta_business_id;
