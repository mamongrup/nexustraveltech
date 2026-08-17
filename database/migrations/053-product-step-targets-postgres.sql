-- 053: Ürün türü kurulum adımlarının hedef bölüm eşlemesi.
-- steps JSON dizisindeki her adımın karşılık geldiği bölüm çapası
-- (örn. sec-01, sec-02) admin panelinden yönetilebilir.
-- Yeni ürün türlerinde adım başına hedef bölüm tanımlanmazsa tesis-ekle
-- eski varsayılan eşlemeyi kullanır (adım 0 -> sec-01, adım 1 -> sec-02).

ALTER TABLE product_type_catalog ADD COLUMN IF NOT EXISTS step_targets JSONB NOT NULL DEFAULT '[]'::jsonb;
