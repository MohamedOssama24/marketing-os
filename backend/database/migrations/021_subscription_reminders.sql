-- Promemoria via email sulla scadenza della prova o dell'abbonamento: uno per tipo e per data di scadenza,
-- così non parte due volte (e riparte se la prova viene prolungata).
CREATE TABLE subscription_reminders (
  organization_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(30) NOT NULL,
  due_at DATETIME NOT NULL,
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (organization_id, kind, due_at),
  CONSTRAINT fk_sub_reminder_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
