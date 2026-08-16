-- Panel AI sohbet kayıtları (admin/tedarikçi/acente asistanları) — panel bazlı aylık raporlar için.
CREATE TABLE IF NOT EXISTS panel_chat_messages (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  role VARCHAR(16) NOT NULL CHECK(role IN ('admin','supplier','agency')),
  supplier_id BIGINT,
  agency_id BIGINT,
  user_message TEXT NOT NULL,
  ai_reply TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_panel_chat_role_created ON panel_chat_messages(role,created_at);
CREATE INDEX IF NOT EXISTS idx_panel_chat_supplier ON panel_chat_messages(supplier_id,created_at);
CREATE INDEX IF NOT EXISTS idx_panel_chat_agency ON panel_chat_messages(agency_id,created_at);
