-- Channel mapping, ARI scopes, advanced restrictions and contract/rate rules.
ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS sync_scopes JSONB NOT NULL DEFAULT '{"availability":true,"rates":true,"restrictions":true,"reservations":true}'::jsonb;
ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS property_code VARCHAR(120);
ALTER TABLE channel_connections ADD COLUMN IF NOT EXISTS last_sync_status VARCHAR(16) NOT NULL DEFAULT 'never';

CREATE TABLE IF NOT EXISTS channel_property_mappings (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,external_property_id VARCHAR(120) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(channel_connection_id,property_id),UNIQUE(channel_connection_id,external_property_id)
);
CREATE TABLE IF NOT EXISTS channel_room_mappings (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,channel_property_mapping_id BIGINT NOT NULL REFERENCES channel_property_mappings(id) ON DELETE CASCADE,room_type_id BIGINT NOT NULL REFERENCES room_types(id) ON DELETE CASCADE,external_room_id VARCHAR(120) NOT NULL,inventory_mode VARCHAR(16) NOT NULL DEFAULT 'pooled' CHECK(inventory_mode IN ('pooled','allocated','virtual')),availability_formula JSONB NOT NULL DEFAULT '{}'::jsonb,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(channel_property_mapping_id,room_type_id),UNIQUE(channel_property_mapping_id,external_room_id)
);
CREATE TABLE IF NOT EXISTS channel_rate_plan_mappings (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,channel_room_mapping_id BIGINT NOT NULL REFERENCES channel_room_mappings(id) ON DELETE CASCADE,rate_plan_id BIGINT NOT NULL REFERENCES rate_plans(id) ON DELETE CASCADE,external_rate_plan_id VARCHAR(120) NOT NULL,send_mode VARCHAR(16) NOT NULL DEFAULT 'direct' CHECK(send_mode IN ('direct','derived','readonly')),adjustment_type VARCHAR(12) NOT NULL DEFAULT 'none' CHECK(adjustment_type IN ('none','percent','fixed')),adjustment_value NUMERIC(12,2) NOT NULL DEFAULT 0,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),UNIQUE(channel_room_mapping_id,rate_plan_id),UNIQUE(channel_room_mapping_id,external_rate_plan_id)
);
CREATE TABLE IF NOT EXISTS channel_sync_logs (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,channel_connection_id BIGINT NOT NULL REFERENCES channel_connections(id) ON DELETE CASCADE,property_id BIGINT REFERENCES properties(id) ON DELETE SET NULL,direction VARCHAR(8) NOT NULL CHECK(direction IN ('push','pull')),scope VARCHAR(20) NOT NULL CHECK(scope IN ('availability','rates','restrictions','reservations','content')),status VARCHAR(16) NOT NULL CHECK(status IN ('queued','running','success','failed','skipped')),request_payload JSONB NOT NULL DEFAULT '{}'::jsonb,response_payload JSONB NOT NULL DEFAULT '{}'::jsonb,error_message TEXT,attempt_count SMALLINT NOT NULL DEFAULT 0,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),completed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_channel_sync_logs_connection ON channel_sync_logs(channel_connection_id,created_at DESC);

ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS max_stay SMALLINT;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS closed_to_arrival BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS closed_to_departure BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS min_advance_days SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE inventory_calendar ADD COLUMN IF NOT EXISTS max_advance_days SMALLINT;

CREATE TABLE IF NOT EXISTS rate_rules (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,name VARCHAR(190) NOT NULL,rule_type VARCHAR(20) NOT NULL CHECK(rule_type IN ('percent','fixed','derived','promo_code','free_night')),value NUMERIC(12,2) NOT NULL DEFAULT 0,currency CHAR(3),booking_start DATE,booking_end DATE,stay_start DATE,stay_end DATE,min_advance_days SMALLINT NOT NULL DEFAULT 0,markets JSONB NOT NULL DEFAULT '[]'::jsonb,nationalities JSONB NOT NULL DEFAULT '[]'::jsonb,channels JSONB NOT NULL DEFAULT '[]'::jsonb,occupancy_rules JSONB NOT NULL DEFAULT '{}'::jsonb,promo_code VARCHAR(60),priority SMALLINT NOT NULL DEFAULT 100,stackable BOOLEAN NOT NULL DEFAULT false,status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_rate_rules_property_dates ON rate_rules(property_id,status,stay_start,stay_end);
