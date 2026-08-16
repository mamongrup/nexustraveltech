-- 026: B2B canlı rezervasyon, misafir değerlendirme ve kimlik bildirimi takibi

-- Acentelerden gelen canlı rezervasyon talepleri; tedarikçi onayıyla gerçek rezervasyona dönüşür.
CREATE TABLE IF NOT EXISTS agency_booking_requests (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  agency_id BIGINT NOT NULL REFERENCES agencies(id) ON DELETE CASCADE,
  supplier_id BIGINT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  room_type_id BIGINT REFERENCES room_types(id) ON DELETE SET NULL,
  rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL,
  check_in DATE NOT NULL,
  check_out DATE NOT NULL,
  nights INTEGER NOT NULL DEFAULT 1,
  adults SMALLINT NOT NULL DEFAULT 2,
  children SMALLINT NOT NULL DEFAULT 0,
  total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','cancelled','expired')),
  guest_first_name VARCHAR(100) NOT NULL,
  guest_last_name VARCHAR(100) NOT NULL,
  guest_email VARCHAR(190),
  guest_phone VARCHAR(40),
  agency_reference VARCHAR(80),
  note TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  responded_at TIMESTAMPTZ,
  responded_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL,
  response_note TEXT
);
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_status ON agency_booking_requests(status,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_agency ON agency_booking_requests(agency_id,status);
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_supplier ON agency_booking_requests(supplier_id,status,created_at DESC);

-- Konaklama sonrası misafir değerlendirmeleri (itibar yönetimi).
CREATE TABLE IF NOT EXISTS guest_reviews (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  property_id BIGINT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
  booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  guest_name VARCHAR(190),
  rating SMALLINT CHECK(rating BETWEEN 1 AND 5),
  title VARCHAR(190),
  body TEXT,
  response TEXT,
  responded_by BIGINT REFERENCES supplier_users(id) ON DELETE SET NULL,
  response_at TIMESTAMPTZ,
  status VARCHAR(16) NOT NULL DEFAULT 'invited' CHECK(status IN ('invited','pending','published','hidden')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  submitted_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_guest_reviews_property_status ON guest_reviews(property_id,status,created_at DESC);

-- Kimlik bildirimi raporu için bildirim durumu.
ALTER TABLE guest_document_records ADD COLUMN IF NOT EXISTS reported_at TIMESTAMPTZ;
