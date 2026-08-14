-- Exact product identity prevents different suppliers from creating the same listing.
ALTER TABLE properties ADD COLUMN IF NOT EXISTS duplicate_key CHAR(64);
CREATE UNIQUE INDEX IF NOT EXISTS uq_properties_duplicate_key ON properties(duplicate_key) WHERE duplicate_key IS NOT NULL;

ALTER TABLE property_media ADD COLUMN IF NOT EXISTS content_hash CHAR(64);
CREATE INDEX IF NOT EXISTS idx_property_media_content_hash ON property_media(content_hash) WHERE content_hash IS NOT NULL;

CREATE TABLE IF NOT EXISTS duplicate_listing_signals (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  matched_property_id BIGINT REFERENCES properties(id) ON DELETE SET NULL,
  signal_type VARCHAR(40) NOT NULL,
  confidence NUMERIC(5,2) NOT NULL DEFAULT 100,
  details JSONB NOT NULL DEFAULT '{}'::jsonb,
  status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK (status IN ('open','confirmed','dismissed')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_duplicate_listing_signals_status ON duplicate_listing_signals(status,created_at DESC);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  actor_type VARCHAR(30) NOT NULL,
  actor_id BIGINT,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT,
  meta JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity_type,entity_id,created_at DESC);
