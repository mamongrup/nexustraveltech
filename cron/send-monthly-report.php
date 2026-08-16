<?php
declare(strict_types=1);

/**
 * Aylık sohbet raporu e-postası — her ayın 1'inde bir önceki ayın raporunu
 * admin_alert_email adresine gönderir (TCPDF kuruluysa PDF ekiyle).
 *
 * Zamanlayıcı: nexus-monthly-report (varsayılan 0 7 1 * *) — admin → Zamanlayıcılar.
 * İdempotent: aynı ay için yalnızca bir kez kuyruğa eklenir; kayıt yoksa atlanır.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/pdf.php';
require_once __DIR__ . '/../config/chat_report.php';

$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Aylık rapor gönderilmedi: admin_alert_email tanımlı değil (admin → Kontrol merkezi).\n");
    exit(0);
}

$prevAy = date('Y-m', strtotime('first day of last month'));
$key = (int) str_replace('-', '', $prevAy);

$exists = db()->prepare("SELECT COUNT(*) FROM email_outbox WHERE related_type='monthly_report' AND related_id=?");
$exists->execute([$key]);
if ((int) $exists->fetchColumn() > 0) {
    echo "Rapor zaten kuyrukta; atlandı.\n";
    exit(0);
}

$d = chat_report_data($prevAy);
if ($d['totalRows'] === 0) {
    echo $prevAy . ' için kayıt yok; rapor gönderilmedi.' . "\n";
    exit(0);
}

$html = chat_report_html($d);
$attName = null;
$attBase64 = null;
$pdf = pdf_build($html);
if ($pdf !== null) {
    $attName = 'sohbet-raporu-' . $prevAy . '.pdf';
    $attBase64 = base64_encode($pdf);
}

queue_email($to, 'Aylık sohbet raporu — ' . $d['monthLabel'], $html, 'monthly_report', $key, $attName, $attBase64);
echo 'Rapor kuyruğa eklendi: ' . $to . ' (' . $prevAy . ($attName ? ', PDF ekli' : ', PDF yok — TCPDF kurulu değil, HTML gövde gönderildi') . ").\n";
