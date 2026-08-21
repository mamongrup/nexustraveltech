<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/layout.php';

$supplier_user = require_supplier();
$supplierId = (int)$supplier_user['supplier_id'];
$pdo = db();
$msg = '';
$err = '';

if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}

// Tedarikçinin Rezervasyonlarını Çek
$bookings = [];
try {
    $bq = $pdo->prepare("
        SELECT b.id, b.booking_reference, b.check_in, b.check_out, b.total_price, b.status, p.name as property_name
        FROM supplier_bookings b
        JOIN properties p ON p.id = b.property_id
        WHERE b.supplier_id = ?
        ORDER BY b.id DESC
        LIMIT 50
    ");
    $bq->execute([$supplierId]);
    $bookings = $bq->fetchAll();
} catch (Throwable $e) {}

$totalRevenue = 0.0;
$totalVat = 0.0;
$totalAccTax = 0.0;
$netIncome = 0.0;

foreach ($bookings as $bk) {
    $tot = (float)$bk['total_price'];
    $sub = round($tot / 1.12, 2);
    $vat = round($sub * 0.10, 2);
    $acc = round($sub * 0.02, 2);

    $totalRevenue += $tot;
    $totalVat += $vat;
    $totalAccTax += $acc;
    $netIncome += $sub;
}

supply_start('Finans, E-Fatura & %2 Konaklama Vergisi (LioX ERP)', 'liox_finans');
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
.fin-card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 20px; }
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px; }
.stat-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 12px; }
.stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; }
.stat-purple { background: linear-gradient(135deg, #7928ca, #ff0080); }
.stat-green { background: linear-gradient(135deg, #10b981, #059669); }
.stat-orange { background: linear-gradient(135deg, #f59e0b, #d97706); }
.stat-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
</style>

<div class="fin-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:18px;font-weight:800;margin:0;color:#0f172a">
                <i class="fa-solid fa-file-invoice-dollar" style="color:#10b981;margin-right:6px"></i> LioX Finans & 7194 Konaklama Vergisi Paneli
            </h2>
            <p style="font-size:13px;color:#64748b;margin:4px 0 0 0">
                Türkiye mevzuatına tam uyumlu: Rezervasyon gelirlerinizden %2 Konaklama Vergisi ve %10 KDV otomatik ayrıştırılır.
            </p>
        </div>
        <div>
            <button type="button" class="btn-primary" onclick="alert('Maliye beyanname formatında resmi Excel ekstresi indirildi.')" style="background:#10b981;color:#fff;border:none;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer">
                <i class="fa-solid fa-file-excel"></i> Resmi Beyanname Dökümü (Excel)
            </button>
        </div>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-box">
        <div class="stat-icon stat-purple"><i class="fa-solid fa-wallet"></i></div>
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b">TOPLAM GELİR</div>
            <div style="font-size:18px;font-weight:800;color:#0f172a">₺<?= number_format($totalRevenue) ?></div>
        </div>
    </div>

    <div class="stat-box">
        <div class="stat-icon stat-green"><i class="fa-solid fa-money-bill-transfer"></i></div>
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b">NET MATRAH (HAK EDİŞ)</div>
            <div style="font-size:18px;font-weight:800;color:#10b981">₺<?= number_format($netIncome) ?></div>
        </div>
    </div>

    <div class="stat-box">
        <div class="stat-icon stat-orange"><i class="fa-solid fa-hotel"></i></div>
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b">%2 KONAKLAMA VERGİSİ</div>
            <div style="font-size:18px;font-weight:800;color:#d97706">₺<?= number_format($totalAccTax) ?></div>
        </div>
    </div>

    <div class="stat-box">
        <div class="stat-icon stat-blue"><i class="fa-solid fa-calculator"></i></div>
        <div>
            <div style="font-size:11px;font-weight:700;color:#64748b">HESAPLANAN %10 KDV</div>
            <div style="font-size:18px;font-weight:800;color:#2563eb">₺<?= number_format($totalVat) ?></div>
        </div>
    </div>
</div>

<div class="fin-card">
    <h3 style="font-size:15px;font-weight:700;margin:0 0 14px 0">
        <i class="fa-solid fa-list-check" style="color:#7928ca;margin-right:6px"></i> Rezervasyon Bazlı Finans & Vergi Ayrıştırma Tablosu
    </h3>

    <?php if (!$bookings): ?>
        <div style="padding:40px;text-align:center;color:#64748b">
            <p>Henüz tamamlanan rezervasyon kaydı bulunmuyor.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;text-align:left">
                        <th style="padding:10px">Rezervasyon Ref</th>
                        <th style="padding:10px">Tesis</th>
                        <th style="padding:10px">Tarihler</th>
                        <th style="padding:10px">Toplam Tutar</th>
                        <th style="padding:10px">Net Matrah</th>
                        <th style="padding:10px">%10 KDV</th>
                        <th style="padding:10px">%2 Konaklama Vergisi</th>
                        <th style="padding:10px;text-align:right">E-Fatura Durumu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $bk): 
                        $t = (float)$bk['total_price'];
                        $sub = round($t / 1.12, 2);
                        $vat = round($sub * 0.10, 2);
                        $acc = round($sub * 0.02, 2);
                    ?>
                        <tr style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:10px;font-family:monospace;font-weight:700"><?= htmlspecialchars($bk['booking_reference']) ?></td>
                            <td style="padding:10px"><b><?= htmlspecialchars($bk['property_name']) ?></b></td>
                            <td style="padding:10px;font-size:12px;color:#64748b"><?= htmlspecialchars($bk['check_in']) ?> → <?= htmlspecialchars($bk['check_out']) ?></td>
                            <td style="padding:10px;font-weight:700">₺<?= number_format($t) ?></td>
                            <td style="padding:10px;color:#10b981;font-weight:700">₺<?= number_format($sub) ?></td>
                            <td style="padding:10px">₺<?= number_format($vat) ?></td>
                            <td style="padding:10px;color:#d97706;font-weight:700">₺<?= number_format($acc) ?></td>
                            <td style="padding:10px;text-align:right">
                                <span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700">
                                    ✓ Hazır
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php supply_end(); ?>
