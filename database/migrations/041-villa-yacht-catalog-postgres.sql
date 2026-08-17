-- 041: Villa ve yat ürün şablonlarını genişletilmiş alanlar + birim kurulumuyla güncelle.
-- default_product_types() ile aynı içerik; mevcut DB kataloğuna uygulanır.

UPDATE product_type_catalog
SET fields = '[
  {"key":"bedrooms","label":"Yatak odası","type":"number","min":0,"max":50,"placeholder":"Örn. 4"},
  {"key":"max_guests","label":"Maksimum misafir","type":"number","min":1,"max":100,"required":true,"placeholder":"Örn. 8"},
  {"key":"pool","label":"Havuz tipi","type":"select","options":["Özel havuz","Ortak havuz","Havuz yok"]},
  {"key":"area_m2","label":"Alan (m²)","type":"number","min":0,"max":100000,"placeholder":"Örn. 180"},
  {"key":"floors","label":"Kat sayısı","type":"number","min":0,"max":50,"placeholder":"Örn. 2"},
  {"key":"building_type","label":"Yapı tipi","type":"select","options":["Müstakil","Yarı müstakil","Dubleks","Rezidans"]}
]'::jsonb,
    steps = '["Villa bilgisi","Birim & kapasite","Müsaitlik takvimi","Fiyat & kurallar"]'::jsonb,
    room_setup = true,
    hint = 'Kapasite, giriş-çıkış günleri ve villa takvimi ile satışa açılır.',
    updated_at = now()
WHERE code = 'villa';

UPDATE product_type_catalog
SET fields = '[
  {"key":"cabins","label":"Kabin sayısı","type":"number","min":0,"max":50,"placeholder":"Örn. 4"},
  {"key":"guest_capacity","label":"Misafir kapasitesi","type":"number","min":1,"max":200,"required":true,"placeholder":"Örn. 8"},
  {"key":"length","label":"Yat uzunluğu (m)","type":"number","min":0,"max":500,"step":0.1,"required":true,"placeholder":"Örn. 22"},
  {"key":"home_port","label":"Bağlama limanı","type":"text","placeholder":"Örn. Göcek Marina"},
  {"key":"crew","label":"Mürettebat","type":"text","placeholder":"Örn. Kaptan + 2 personel"},
  {"key":"year_built","label":"Yapım yılı","type":"number","min":1900,"max":2100,"placeholder":"Örn. 2021"}
]'::jsonb,
    steps = '["Yat bilgisi","Kabin & kapasite","Rota & müsaitlik","Kiralama kuralları"]'::jsonb,
    room_setup = true,
    hint = 'Kabin yapısı, rota, liman ve kiralama periyotları ile devam eder.',
    updated_at = now()
WHERE code = 'yacht';
