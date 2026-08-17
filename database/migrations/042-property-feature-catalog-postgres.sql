-- 042: Villa/yat özellik kataloğu — admin panelinden yönetilebilir listeler.
-- villa-detay sayfasındaki özellik listeleri bu tablodan okunur.

CREATE TABLE IF NOT EXISTS property_feature_catalog (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(20) NOT NULL CHECK (code IN ('villa','yacht')),
  label VARCHAR(120) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 100,
  is_active BOOLEAN NOT NULL DEFAULT true,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (code, label)
);

CREATE INDEX IF NOT EXISTS idx_feature_catalog_code ON property_feature_catalog(code, is_active, sort_order);

-- Varsayılan listeler (idempotent — tekrar çalıştırmada çakışma yok).
INSERT INTO property_feature_catalog (code, label, sort_order) VALUES
  ('villa','Özel havuz',10),
  ('villa','Jakuzi',20),
  ('villa','Klima',30),
  ('villa','Wi-Fi',40),
  ('villa','Televizyon',50),
  ('villa','Mutfak',60),
  ('villa','Bulaşık makinesi',70),
  ('villa','Çamaşır makinesi',80),
  ('villa','Bahçe',90),
  ('villa','Teras',100),
  ('villa','Mangal',110),
  ('villa','Otopark',120),
  ('villa','Güvenlik',130),
  ('villa','Özel giriş',140),
  ('villa','Deniz manzarası',150),
  ('villa','Ebeveyn banyosu',160),
  ('villa','Şömine',170),
  ('villa','Isıtmalı havuz',180),
  ('yacht','Güverte',10),
  ('yacht','Şezlong',20),
  ('yacht','Kabin TV',30),
  ('yacht','Klima',40),
  ('yacht','Müzik sistemi',50),
  ('yacht','Su sporları ekipmanı',60),
  ('yacht','Balıkçılık ekipmanı',70),
  ('yacht','Şnorkel',80),
  ('yacht','Dalış ekipmanı',90),
  ('yacht','Mutfak',100),
  ('yacht','Buzdolabı',110),
  ('yacht','Barbekü',120),
  ('yacht','Mürettebat',130),
  ('yacht','Yüzme merdiveni',140),
  ('yacht','Güneşlenme alanı',150),
  ('yacht','Wi-Fi',160)
ON CONFLICT (code, label) DO NOTHING;
