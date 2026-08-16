-- Kötü niyetli ziyaretçi trafiği için IP engelleme / bayraklama.
CREATE TABLE IF NOT EXISTS blocked_ips (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ip INET NOT NULL,
    action VARCHAR(10) NOT NULL DEFAULT 'block' CHECK (action IN ('block','flag')),
    reason TEXT,
    created_by VARCHAR(60),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_blocked_ips_ip ON blocked_ips (ip);
