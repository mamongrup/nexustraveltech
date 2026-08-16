-- 033: Performans denetiminin (scripts/audit-performance.php) tespit ettiği eksik indeksler
CREATE INDEX IF NOT EXISTS idx_supplier_bookings_property_checkin ON supplier_bookings(property_id, check_in);
CREATE INDEX IF NOT EXISTS idx_supplier_bookings_property_status ON supplier_bookings(property_id, booking_status);
CREATE INDEX IF NOT EXISTS idx_loyalty_ledger_account ON loyalty_ledger(account_id, created_at);
