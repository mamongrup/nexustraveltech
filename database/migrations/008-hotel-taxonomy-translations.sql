USE nexus_traveltech;

CREATE TABLE IF NOT EXISTS hotel_taxonomy_translations (
  taxonomy_id BIGINT UNSIGNED NOT NULL,
  locale CHAR(2) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (taxonomy_id, locale),
  CONSTRAINT fk_hotel_taxonomy_translation FOREIGN KEY (taxonomy_id) REFERENCES hotel_taxonomies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO hotel_taxonomy_translations (taxonomy_id,locale,name)
SELECT id,'en',CASE name
 WHEN 'Tatil köyü' THEN 'Holiday village' WHEN 'Resort otel' THEN 'Resort hotel' WHEN 'Şehir oteli' THEN 'City hotel' WHEN 'Butik otel' THEN 'Boutique hotel' WHEN 'Pansiyon' THEN 'Guesthouse' WHEN 'Apart otel' THEN 'Aparthotel' WHEN 'Bungalov tesisi' THEN 'Bungalow resort' WHEN 'Termal otel' THEN 'Thermal hotel' WHEN 'Kayak oteli' THEN 'Ski hotel' WHEN 'İş oteli' THEN 'Business hotel' WHEN 'Motel' THEN 'Motel'
 WHEN 'Özel belgeli / yıldızsız' THEN 'Special certificate / unclassified' WHEN 'Denize sıfır' THEN 'Beachfront' WHEN 'Özel plajlı' THEN 'Private beach' WHEN 'Mavi bayraklı plaj' THEN 'Blue Flag beach' WHEN 'Şehir oteli' THEN 'City hotel' WHEN 'Termal otel' THEN 'Thermal hotel' WHEN 'Balayı' THEN 'Honeymoon' WHEN 'Aile dostu' THEN 'Family friendly' WHEN 'Çocuk dostu' THEN 'Child friendly' WHEN 'Yetişkin oteli' THEN 'Adults only' WHEN 'Spa oteli' THEN 'Spa hotel' WHEN 'Golf oteli' THEN 'Golf hotel' WHEN 'Kayak oteli' THEN 'Ski hotel' WHEN 'Butik otel' THEN 'Boutique hotel' WHEN 'Evcil hayvan dostu' THEN 'Pet friendly' WHEN 'Engelli dostu' THEN 'Accessible' WHEN 'Muhafazakâr tatil' THEN 'Conservative holiday'
 ELSE name END
FROM hotel_taxonomies;
