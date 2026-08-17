CREATE TABLE IF NOT EXISTS product_type_catalog (
  code VARCHAR(40) PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  unit VARCHAR(120) NOT NULL,
  steps JSONB NOT NULL DEFAULT '[]'::jsonb,
  fields JSONB NOT NULL DEFAULT '[]'::jsonb,
  room_setup BOOLEAN NOT NULL DEFAULT false,
  hint TEXT NOT NULL DEFAULT '',
  is_active BOOLEAN NOT NULL DEFAULT true,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS product_verification_requirements (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  product_type_code VARCHAR(40) NOT NULL REFERENCES product_type_catalog(code) ON DELETE CASCADE,
  requirement_code VARCHAR(60) NOT NULL,
  label VARCHAR(190) NOT NULL,
  is_required BOOLEAN NOT NULL DEFAULT true,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  UNIQUE(product_type_code,requirement_code)
);

-- ============================================================
-- Sahiplikten bağımsız çalışma (GRANT + ALTER OWNER):
-- Migration ister postgres ister app kullanıcısıyla koşsun, dokunulan tablolar
-- app DB kullanıcısına devredilir. @APP_DB_USER@ yer tutucusu çalıştırıcı
-- tarafından secrets.php deki db_user ile değiştirilir (config/health.php ve
-- scripts/apply-migrations-postgres.sh); elle psql -f ile koşarsanız önce
-- sed "s/@APP_DB_USER@/<kullanici>/g" ile değiştirin.
GRANT ALL PRIVILEGES ON TABLE product_type_catalog, product_verification_requirements TO @APP_DB_USER@;
ALTER TABLE product_type_catalog OWNER TO @APP_DB_USER@;
ALTER TABLE product_verification_requirements OWNER TO @APP_DB_USER@;
