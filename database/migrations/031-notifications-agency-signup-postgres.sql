-- 031: Panel içi bildirimler ve acente self-servis kayıt doğrulaması

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_type VARCHAR(12) NOT NULL CHECK(user_type IN ('supplier','agency')),
    user_id BIGINT NOT NULL,
    type VARCHAR(40) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(500),
    is_read BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_type,user_id,is_read,created_at DESC);

ALTER TABLE agencies ADD COLUMN IF NOT EXISTS verify_token VARCHAR(64);
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ;
ALTER TABLE agencies ADD COLUMN IF NOT EXISTS self_registered BOOLEAN NOT NULL DEFAULT false;
