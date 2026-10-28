-- Sprint 2: brand dal sito, strategia di campagna, grafiche e calendario dei post.

ALTER TABLE brand_profiles
  ADD COLUMN summary VARCHAR(500) NULL AFTER name;

ALTER TABLE projects
  ADD COLUMN strategy JSON NULL AFTER status;

ALTER TABLE content_items
  ADD COLUMN visual JSON NULL AFTER body,
  ADD INDEX idx_content_org_scheduled (organization_id, scheduled_at);
