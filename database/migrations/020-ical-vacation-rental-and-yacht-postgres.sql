CREATE TABLE IF NOT EXISTS ical_connections (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
 property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
 direction VARCHAR(10) NOT NULL CHECK(direction IN ('import','export')),
 label VARCHAR(120) NOT NULL,
 access_token CHAR(64) NOT NULL UNIQUE,
 source_url TEXT,
 status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','paused','error')),
 last_sync_at TIMESTAMPTZ,
 last_error TEXT,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 CHECK((direction='import' AND source_url IS NOT NULL) OR direction='export')
);
CREATE INDEX IF NOT EXISTS idx_ical_connections_property ON ical_connections(property_id,status);

CREATE TABLE IF NOT EXISTS ical_events (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 ical_connection_id BIGINT NOT NULL REFERENCES ical_connections(id) ON DELETE CASCADE,
 property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
 external_uid VARCHAR(255) NOT NULL,
 starts_on DATE NOT NULL,
 ends_on DATE NOT NULL,
 summary VARCHAR(255),
 event_status VARCHAR(24) NOT NULL DEFAULT 'confirmed',
 raw_event TEXT,
 synced_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(ical_connection_id,external_uid),
 CHECK(ends_on>=starts_on)
);
CREATE INDEX IF NOT EXISTS idx_ical_events_property_dates ON ical_events(property_id,starts_on,ends_on);
