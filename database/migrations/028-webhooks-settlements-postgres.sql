-- 028: Webhook abonelikleri ve mutabakat benzersizliği

CREATE TABLE IF NOT EXISTS webhook_subscriptions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,
  url VARCHAR(500) NOT NULL,
  secret VARCHAR(120),
  events JSONB NOT NULL DEFAULT '[]'::jsonb,
  status VARCHAR(16) NOT NULL DEFAULT 'active' CHECK(status IN ('active','paused')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_sent_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_webhook_subscriptions_agency ON webhook_subscriptions(agency_id);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  subscription_id BIGINT NOT NULL REFERENCES webhook_subscriptions(id) ON DELETE CASCADE,
  event VARCHAR(60) NOT NULL,
  payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  status VARCHAR(16) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sending','sent','failed')),
  http_status SMALLINT,
  attempts SMALLINT NOT NULL DEFAULT 0,
  error_message TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  sent_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_status ON webhook_deliveries(status,created_at);

-- Bir rezervasyon için tek mutabakat kaydı.
-- Önce olası mükerrer kayıtları temizle (en son kayıt kalır), sonra benzersiz indeksi kur;
-- aksi halde mevcut verideki aynı booking_id'li satırlar indeks oluşumunu engeller.
DELETE FROM supplier_settlements a USING supplier_settlements b
WHERE a.booking_id IS NOT NULL AND a.id < b.id AND a.booking_id = b.booking_id;
CREATE UNIQUE INDEX IF NOT EXISTS uq_settlements_booking ON supplier_settlements(booking_id) WHERE booking_id IS NOT NULL;
