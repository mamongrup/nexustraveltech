-- 062: previous_purge_at — restore edilen özelliklerin eski purge_at değerini hatırlar.
-- Bir özellik geri yüklendiğinde eski purge_at bu kolona kaydedilir; yeniden silinirken
-- silme onay ekranında varsayılan tarih olarak önerilir.
ALTER TABLE property_feature_catalog ADD COLUMN IF NOT EXISTS previous_purge_at TIMESTAMPTZ;
