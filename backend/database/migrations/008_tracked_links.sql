-- Link tracciati: un indirizzo breve /r/{codice} che conta i clic e porta al sito del cliente con i parametri UTM.

CREATE TABLE tracked_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  brand_profile_id BIGINT UNSIGNED NULL,
  content_item_id BIGINT UNSIGNED NULL,
  code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(160) NOT NULL,
  target_url VARCHAR(2048) NOT NULL,
  utm_source VARCHAR(60) NULL,
  utm_medium VARCHAR(60) NULL,
  utm_campaign VARCHAR(120) NULL,
  utm_content VARCHAR(120) NULL,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  unique_clicks INT UNSIGNED NOT NULL DEFAULT 0,
  last_click_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_links_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  -- Se si elimina il cliente o il post il link continua a funzionare: è già stato pubblicato.
  CONSTRAINT fk_links_brand FOREIGN KEY (brand_profile_id) REFERENCES brand_profiles(id) ON DELETE SET NULL,
  CONSTRAINT fk_links_content FOREIGN KEY (content_item_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_links_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_links_code (code),
  INDEX idx_links_org (organization_id, created_at),
  INDEX idx_links_content (content_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Nessun dato personale: l'indirizzo IP non si salva, serve solo un'impronta giornaliera per contare i visitatori unici.
CREATE TABLE link_clicks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  link_id BIGINT UNSIGNED NOT NULL,
  clicked_at DATETIME NOT NULL,
  visitor_hash CHAR(32) CHARACTER SET ascii NOT NULL,
  is_unique TINYINT(1) NOT NULL,
  source VARCHAR(20) NOT NULL,
  device ENUM('mobile','tablet','desktop') NOT NULL,
  CONSTRAINT fk_clicks_link FOREIGN KEY (link_id) REFERENCES tracked_links(id) ON DELETE CASCADE,
  INDEX idx_clicks_link_time (link_id, clicked_at),
  INDEX idx_clicks_visitor (link_id, visitor_hash, clicked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
