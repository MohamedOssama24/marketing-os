-- Abbonamento dell'agenzia a Marketing OS: prova gratuita alla registrazione, poi abbonamento mensile o annuale pagato con Stripe.
-- plan: trial = in prova, monthly/yearly = abbonata, free = accesso regalato (nessun pagamento richiesto).

ALTER TABLE organizations
  ADD COLUMN plan ENUM('trial','monthly','yearly','free') NOT NULL DEFAULT 'trial' AFTER admin_notes,
  ADD COLUMN trial_ends_at DATETIME NULL AFTER plan,
  ADD COLUMN subscription_status VARCHAR(30) NULL AFTER trial_ends_at,
  ADD COLUMN current_period_end DATETIME NULL AFTER subscription_status,
  ADD COLUMN cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0 AFTER current_period_end,
  ADD COLUMN stripe_customer_id VARCHAR(60) NULL AFTER cancel_at_period_end,
  ADD COLUMN stripe_subscription_id VARCHAR(60) NULL AFTER stripe_customer_id,
  ADD KEY idx_org_stripe_sub (stripe_subscription_id),
  ADD KEY idx_org_stripe_customer (stripe_customer_id);

-- Le organizzazioni che esistevano già prima degli abbonamenti non vengono bloccate.
UPDATE organizations SET plan='free';

-- Eventi di Stripe già elaborati: Stripe può mandare lo stesso evento più volte.
CREATE TABLE stripe_events (
  id VARCHAR(80) NOT NULL PRIMARY KEY,
  type VARCHAR(80) NOT NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
