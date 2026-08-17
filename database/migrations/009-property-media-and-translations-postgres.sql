-- PostgreSQL only: run after the initial NEXUS schema on production.
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS media_scope VARCHAR(20) NOT NULL DEFAULT 'property';
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS room_type_id BIGINT NULL REFERENCES room_types(id) ON DELETE SET NULL;
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS title VARCHAR(190) NULL;
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS description TEXT NULL;
ALTER TABLE property_media ADD COLUMN IF NOT EXISTS alt_text VARCHAR(255) NULL;
CREATE INDEX IF NOT EXISTS idx_property_media_scope ON property_media(property_id, media_scope, room_type_id);

CREATE TABLE IF NOT EXISTS property_content_translations (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  entity_type VARCHAR(20) NOT NULL,
  entity_id BIGINT NOT NULL DEFAULT 0,
  locale CHAR(2) NOT NULL,
  field_key VARCHAR(60) NOT NULL,
  value TEXT NOT NULL,
  source_locale CHAR(2) NOT NULL DEFAULT 'tr',
  translation_source VARCHAR(20) NOT NULL DEFAULT 'manual',
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(property_id, entity_type, entity_id, locale, field_key)
);
CREATE INDEX IF NOT EXISTS idx_content_translation_lookup ON property_content_translations(property_id, entity_type, entity_id, locale);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE property_content_translations, property_media TO @APP_DB_USER@;
ALTER TABLE property_content_translations OWNER TO @APP_DB_USER@;
ALTER TABLE property_media OWNER TO @APP_DB_USER@;
