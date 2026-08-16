-- Kamuya açık (önyüz) AI sohbet: ziyaretçi soruları kaydı + IP hız sınırlama verisi.
CREATE TABLE IF NOT EXISTS public_chat_messages (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ip INET,
    user_message TEXT,
    ai_reply TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_public_chat_ip_time ON public_chat_messages (ip, created_at);
