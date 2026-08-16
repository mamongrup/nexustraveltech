<?php
declare(strict_types=1);
// Plesk cron: 0 8 * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/send-welcome-emails.php
// Yarın gelen misafirlere otel bilgisi içeren hoş geldiniz e-postasını kuyruğa ekler (idempotent).
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';

$q = db()->prepare(
    "SELECT b.id,b.booking_reference,b.check_in,b.check_out,p.name property_name,p.city,p.product_details,
            gp.first_name,gp.last_name,gp.email
     FROM supplier_bookings b
     JOIN properties p ON p.id=b.property_id
     JOIN booking_guests bg ON bg.booking_id=b.id AND bg.is_primary=true
     JOIN guest_profiles gp ON gp.id=bg.guest_id
     WHERE b.check_in=CURRENT_DATE + 1
       AND b.status IN ('confirmed','reserved')
       AND b.booking_status NOT IN ('cancelled','no_show','checked_out')
       AND gp.email IS NOT NULL AND gp.email<>''
       AND NOT EXISTS (SELECT 1 FROM email_outbox e WHERE e.related_type='welcome' AND e.related_id=b.id)"
);
$q->execute();
$sent = 0;
foreach ($q->fetchAll() as $b) {
    $details = json_decode((string) ($b['product_details'] ?? '{}'), true) ?: [];
    $address = (string) ($details['address'] ?? '');
    $body = '<p>Sayın ' . htmlspecialchars(trim((string) $b['first_name'] . ' ' . $b['last_name'])) . ',</p>'
        . '<p>Yarın konaklamanız başlıyor. Size iyi bir başlangıç olması için bilgiler:</p>'
        . '<p><b>' . htmlspecialchars((string) $b['property_name']) . '</b> · ' . htmlspecialchars((string) $b['city'])
        . ($address !== '' ? ' · ' . htmlspecialchars($address) : '') . '<br>'
        . 'Giriş: <b>' . $b['check_in'] . '</b> (14:00 sonrası) · Çıkış: <b>' . $b['check_out'] . '</b> (12:00 öncesi)<br>'
        . 'Rezervasyon referansı: <b>' . htmlspecialchars((string) $b['booking_reference']) . '</b></p>'
        . '<p>Wifi ve diğer konaklama bilgileri check-in sırasında verilecektir.</p>'
        . '<p>NEXUS TravelTech</p>';
    queue_email_with_template(
        (string) $b['email'],
        'welcome',
        ['misafir_adi' => trim((string) $b['first_name'] . ' ' . $b['last_name']), 'referans' => (string) $b['booking_reference']],
        'Hoş geldiniz — ' . $b['property_name'],
        $body,
        'welcome',
        (int) $b['id']
    );
    $sent++;
}
echo json_encode(['sent' => $sent, 'date' => date('Y-m-d')], JSON_UNESCAPED_UNICODE) . PHP_EOL;
