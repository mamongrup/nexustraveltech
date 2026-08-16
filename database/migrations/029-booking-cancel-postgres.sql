-- 029: İptal akışı için rezervasyon-fiyat planı ve talep-rezervasyon bağları

ALTER TABLE supplier_bookings ADD COLUMN IF NOT EXISTS rate_plan_id BIGINT REFERENCES rate_plans(id) ON DELETE SET NULL;
ALTER TABLE agency_booking_requests ADD COLUMN IF NOT EXISTS booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_agency_booking_requests_booking ON agency_booking_requests(booking_id);
