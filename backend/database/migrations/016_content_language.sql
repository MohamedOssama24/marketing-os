-- Lingua in cui l'AI scrive i contenuti di ogni cliente (post, sponsorizzate, email), indipendente dalla lingua dell'interfaccia.
ALTER TABLE brand_profiles ADD COLUMN content_language CHAR(2) NOT NULL DEFAULT 'it' AFTER words_to_avoid;
