-- 052: Eşleştirme önerisi benzerlik skoru
-- Webhook'ta tanınmayan dış oda kodu için "ilk aktif tip" yerine isim benzerliğine
-- göre en iyi eşleşen oda tipi seçilir; seçim skoru bu kolonda saklanır (0-100 arası).
ALTER TABLE channel_room_mappings ADD COLUMN IF NOT EXISTS suggestion_score SMALLINT;
