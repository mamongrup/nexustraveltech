-- 046: Özellik silme işlemini geri alınabilir yapar.
-- 1) property_feature_catalog'a soft-delete kolonu (deleted_at).
-- 2) feature_delete_backups: silinen özelliğin ve kaldırıldığı ilanların
--    (hangi bölümde olduğu dahil) yedeği — "geri al" akışı buradan beslenir.

ALTER TABLE property_feature_catalog ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS feature_delete_backups (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    feature_id BIGINT NOT NULL,
    code VARCHAR(20) NOT NULL,
    group_label VARCHAR(120) NOT NULL DEFAULT '',
    label VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active BOOLEAN NOT NULL DEFAULT true,
    deleted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_by VARCHAR(190),
    -- [{id, name, sections: ['service_pricing','amenities',...], price: 'free'|'paid'|null}]
    affected_properties JSONB NOT NULL DEFAULT '[]'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_feature_delete_backups_feature ON feature_delete_backups(feature_id);
