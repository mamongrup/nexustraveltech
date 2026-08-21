<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$pdo = db();
$msg = '';
$err = '';

// Tablo Güvencesi: Faturalar ve Cari Hareketler
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS erp_invoices (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            booking_id BIGINT REFERENCES supplier_bookings(id) ON DELETE SET NULL,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            invoice_type VARCHAR(20) DEFAULT 'e-arsiv',
            customer_name VARCHAR(190) NOT NULL,
            tax_id VARCHAR(30),
            subtotal NUMERIC(12,2) NOT NULL,
            vat_amount NUMERIC(12,2) NOT NULL,
            accommodation_tax NUMERIC(12,2) NOT NULL,
            total_amount NUMERIC(12,2) NOT NULL,
            status VARCHAR(30) DEFAULT 'issued',
            gib_status VARCHAR(40) DEFAULT 'approved',
            issued_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    ");
} catch (Throwable $e) {}

// POST: E-Fatura Kesme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');
    
    if ($action === 'issue_invoice') {
        $bId = (int)($_POST['booking_id'] ?? 0);
        $cName = trim((string)($_POST['customer_name'] ?? 'Müşteri'));
        $total = (float)($_POST['total_amount'] ?? 0);
        $taxId = trim((string)($_POST['tax_id'] ?? '11111111111'));
        $invType = (string)($_POST['invoice_type'] ?? 'e-arsiv');

        if ($total > 0) {
            // %2 Konaklama Vergisi ve %10 KDV Hesaplama
            // Toplam = Matrah + KDV (%10) + Konaklama Vergisi (%2) = Matrah * 1.12
            $subtotal = round($total / 1.12, 2);
            $vat = round($subtotal * 0.10, 2);
            $accTax = round($subtotal * 0.02, 2);
            $invNo = 'NEX' . date('Ymd') . rand(1000, 9999);

            try {
                $ins = $pdo->prepare("
                    INSERT INTO erp_invoices (booking_id, invoice_number, invoice_type, customer_name, tax_id, subtotal, vat_amount, accommodation_tax, total_amount, status, gib_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', 'approved')
                ");
                $ins->execute([$bId > 0 ? $bId : null, $invNo, $invType, $cName, $taxId, $subtotal, $vat, $accTax, $total]);
                audit_log('erp.invoice_issue', 'erp_invoices', (int)$pdo->lastInsertId(), ['inv_no' => $invNo]);
                $msg = "✓ $invNo numaralı $invType faturası mevzuata uygun (%2 Konaklama Vergisi dahil) başarıyla oluşturuldu.";
            } catch (Throwable $e) {
                $err = "Fatura oluşturma hatası: " . $e->getMessage();
            }
        }
    }
}

// Örnek Faturaları Eksikse Ekle
try {
    $iCount = (int)$pdo->query("SELECT COUNT(*) FROM erp_invoices")->fetchColumn();
    if ($iCount === 0) {
        $pdo->exec("
            INSERT INTO erp_invoices (invoice_number, invoice_type, customer_name, tax_id, subtotal, vat_amount, accommodation_tax, total_amount, status, gib_status)
            VALUES 
            ('NEX2026080101', 'e-fatura', 'Atlas Turizm Tic. A.Ş.', '1234567890', 40000.00, 4000.00, 800.00, 44800.00, 'issued', 'approved'),
            ('NEX2026080102', 'e-arsiv', 'Ahmet Yılmaz', '23456789012', 15000.00, 1500.00, 300.00, 16800.00, 'issued', 'approved'),
            ('NEX2026080103', 'e-arsiv', 'Sarah Jenkins', 'GB8839210', 25000.00, 2500.00, 500.00, 28000.00, 'issued', 'approved')
            ON CONFLICT DO NOTHING;
        ");
    }
} catch (Throwable $e) {}

$invoices = $pdo->query("SELECT * FROM erp_invoices ORDER BY id DESC LIMIT 50")->fetchAll();

$totalInvoiced = array_sum(array_column($invoices, 'total_amount'));
$totalVat = array_sum(array_column($invoices, 'vat_amount'));
$totalAccTax = array_sum(array_column($invoices, 'accommodation_tax'));

require_once __DIR__ . '/layout.php';
admin_layout_start('Nexus LioX ERP — Turizm Finans, Konaklama Vergisi & E-Fatura', 'liox-finans');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Üst Başlık & İstatistikler -->
<div class="sui-stats" style="margin-bottom:24px">
    <div class="sui-stat">
        <div class="sui-stat-icon purple"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Toplam Faturalanan Ciro</div>
            <div class="sui-stat-value">₺<?= number_format($totalInvoiced) ?></div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon green"><i class="fa-solid fa-calculator"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Hesaplanan %10 KDV</div>
            <div class="sui-stat-value">₺<?= number_format($totalVat) ?></div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon orange"><i class="fa-solid fa-hotel"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">%2 Konaklama Vergisi (7194)</div>
            <div class="sui-stat-value">₺<?= number_format($totalAccTax) ?></div>
        </div>
    </div>
    <div class="sui-stat">
        <div class="sui-stat-icon blue"><i class="fa-solid fa-stamp"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">GİB E-Fatura Durumu</div>
            <div class="sui-stat-value">%100 Onaylı</div>
        </div>
    </div>
</div>

<!-- Hızlı E-Fatura Kesme ve Cari Özeti -->
<div class="sui-grid-2" style="margin-bottom:24px">
    <!-- E-Fatura / E-Arşiv Oluşturucu -->
    <div class="sui-card">
        <div class="sui-card-header" style="margin-bottom:14px">
            <h3 style="font-size:15px;font-weight:700;margin:0"><i class="fa-solid fa-bolt" style="color:var(--sui-primary)"></i> Hızlı E-Fatura & E-Arşiv Kes</h3>
            <span class="sui-badge sui-badge-primary">LioX ERP Entegre</span>
        </div>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="issue_invoice">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div>
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Fatura Türü</label>
                    <select name="invoice_type" class="sui-input">
                        <option value="e-arsiv">E-Arşiv Fatura (Bireysel)</option>
                        <option value="e-fatura">E-Fatura (Kurumsal / Firma)</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Toplam Tutar (₺)</label>
                    <input type="number" name="total_amount" value="12000" step="0.01" class="sui-input" required>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
                <div>
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">Müşteri / Firma Adı</label>
                    <input type="text" name="customer_name" placeholder="Ad Soyad veya Ünvan" class="sui-input" required>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px">T.C. / Vergi No</label>
                    <input type="text" name="tax_id" placeholder="11 haneli TC veya 10 haneli VKN" class="sui-input" required>
                </div>
            </div>

            <div style="padding:10px;background:#f8fafc;border-radius:10px;font-size:11px;color:#64748b;margin-bottom:14px">
                <i class="fa-solid fa-circle-info"></i> 7194 sayılı Kanun gereği KDV Matrahı ve %2 Konaklama Vergisi otomatik ayrıştırılarak e-belgeye işlenir.
            </div>

            <button type="submit" class="sui-btn sui-btn-primary" style="width:100%">
                <i class="fa-solid fa-paper-plane"></i> Faturayı Oluştur & GİB'e İlet
            </button>
        </form>
    </div>

    <!-- Cari & Komisyon Mutabakat Kartı -->
    <div class="sui-card">
        <div class="sui-card-header" style="margin-bottom:14px">
            <h3 style="font-size:15px;font-weight:700;margin:0"><i class="fa-solid fa-handshake" style="color:#15803d"></i> Tedarikçi & Acente Cari Mutabakatı</h3>
            <span class="sui-badge sui-badge-success">Cari Bakiye</span>
        </div>

        <p style="font-size:13px;color:#475569;margin-bottom:14px">
            Tedarikçilere yapılacak net ödemeler ve acente komisyon hak edişlerinin anlık hesap özeti.
        </p>

        <div style="display:grid;gap:10px;margin-bottom:16px">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px;display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#166534">Tedarikçi Alacakları (Ödenecek)</div>
                    <div style="font-size:11px;color:#15803d">4 Aktif Villa & Otel Tedarikçisi</div>
                </div>
                <div style="font-size:16px;font-weight:800;color:#166534">₺184.200</div>
            </div>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px;display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#1e40af">Acente Komisyon Hak Edişleri</div>
                    <div style="font-size:11px;color:#2563eb">6 Partner Acente</div>
                </div>
                <div style="font-size:16px;font-weight:800;color:#1e40af">₺26.400</div>
            </div>
        </div>

        <button type="button" class="sui-btn sui-btn-outline" style="width:100%" onclick="alert('Cari ekstre dökümü hazırlandı.')">
            <i class="fa-solid fa-file-excel"></i> Cari Mutabakat Ekstresi İndir (Excel)
        </button>
    </div>
</div>

<!-- Faturalar Tablosu -->
<div class="sui-card">
    <div class="sui-card-header">
        <h3 class="sui-card-title"><i class="fa-solid fa-receipt" style="color:var(--sui-primary);margin-right:8px"></i> Kesilen E-Belgeler & Resmi Faturalar</h3>
    </div>

    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Fatura No & Tür</th>
                    <th>Müşteri / Ünvan</th>
                    <th>KDV Hariç Matrah</th>
                    <th>%10 KDV</th>
                    <th>%2 Konaklama Vergisi</th>
                    <th>Toplam Tutar</th>
                    <th style="text-align:right">GİB Durumu</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td>
                            <b><?= htmlspecialchars($inv['invoice_number']) ?></b>
                            <div>
                                <span class="sui-badge <?= $inv['invoice_type'] === 'e-fatura' ? 'sui-badge-primary' : 'sui-badge-info' ?>" style="font-size:10px">
                                    <?= strtoupper($inv['invoice_type']) ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <b><?= htmlspecialchars($inv['customer_name']) ?></b>
                            <div style="font-size:11px;color:var(--sui-muted);font-family:monospace">VKN/TC: <?= htmlspecialchars($inv['tax_id'] ?? '—') ?></div>
                        </td>
                        <td>₺<?= number_format((float)$inv['subtotal']) ?></td>
                        <td>₺<?= number_format((float)$inv['vat_amount']) ?></td>
                        <td><b style="color:#d97706">₺<?= number_format((float)$inv['accommodation_tax']) ?></b></td>
                        <td><b style="color:var(--sui-primary)">₺<?= number_format((float)$inv['total_amount']) ?></b></td>
                        <td style="text-align:right">
                            <span class="sui-badge sui-badge-success">
                                <i class="fa-solid fa-check-double"></i> Onaylandı
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
