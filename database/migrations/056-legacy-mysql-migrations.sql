-- 056: MySQL miras migration'larını atla.
-- 002-008 dosyaları MySQL sözdizimiyle yazılmış (USE, MODIFY ENUM, AFTER, UNSIGNED)
-- ve PostgreSQL'de uygulanamaz. Bu migration onları schema_migrations'a kaydederek
-- apply-migrations-postgres.sh'nin bir daha denememesini sağlar.

-- Eşzamanlı: tablolar zaten PostgreSQL versiyonlarıyla kurulmuş durumda
-- (009-020 arası PostgreSQL migration'ları aynı tabloları doğru şemayla yaratır).

INSERT INTO schema_migrations (file) VALUES
    ('002-expand-product-types.sql'),
    ('003-add-product-details.sql'),
    ('004-add-room-details.sql'),
    ('005-add-property-media.sql'),
    ('006-add-payments-and-invoicing.sql'),
    ('007-hotel-taxonomies.sql'),
    ('008-hotel-taxonomy-translations.sql')
ON CONFLICT (file) DO NOTHING;
