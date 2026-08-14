USE nexus_traveltech;

CREATE TABLE IF NOT EXISTS hotel_taxonomies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  taxonomy_type ENUM('property_type','star_rating','theme') NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_hotel_taxonomy (taxonomy_type, name),
  INDEX idx_hotel_taxonomy_lookup (taxonomy_type, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO hotel_taxonomies (taxonomy_type,name,sort_order) VALUES
('property_type','Tatil köyü',10),('property_type','Resort otel',20),('property_type','Şehir oteli',30),('property_type','Butik otel',40),('property_type','Pansiyon',50),('property_type','Apart otel',60),('property_type','Bungalov tesisi',70),('property_type','Termal otel',80),('property_type','Kayak oteli',90),('property_type','İş oteli',100),('property_type','Motel',110),
('star_rating','1 yıldız',10),('star_rating','2 yıldız',20),('star_rating','3 yıldız',30),('star_rating','4 yıldız',40),('star_rating','5 yıldız',50),('star_rating','Özel belgeli / yıldızsız',60),
('theme','Denize sıfır',10),('theme','Özel plajlı',20),('theme','Mavi bayraklı plaj',30),('theme','Şehir oteli',40),('theme','Termal otel',50),('theme','Balayı',60),('theme','Aile dostu',70),('theme','Çocuk dostu',80),('theme','Yetişkin oteli',90),('theme','Spa oteli',100),('theme','Golf oteli',110),('theme','Kayak oteli',120),('theme','Butik otel',130),('theme','Evcil hayvan dostu',140),('theme','Engelli dostu',150),('theme','Muhafazakâr tatil',160);
