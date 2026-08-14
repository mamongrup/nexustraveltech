-- Sensitive supplier verification documents: accessible only through authenticated admin routes.
CREATE TABLE IF NOT EXISTS supplier_verification_documents (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
  document_type VARCHAR(50) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INTEGER NOT NULL,
  uploaded_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (supplier_id, document_type)
);
CREATE INDEX IF NOT EXISTS idx_supplier_verification_documents_supplier ON supplier_verification_documents(supplier_id);
