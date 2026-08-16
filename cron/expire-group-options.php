<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';

/**
 * Süresi dolan grup opsiyonlarını otomatik 'lost' durumuna geçirir ve tedarikçiye bildirir.
 * Opsiyon süresi boyunca gruptaki rezervasyonlar kontenjanı tutar; süre bitince
 * blokaj kalkar ve odalar yeniden satışa açılır.
 */
$pdo = db();
$q = $pdo->query(
    "UPDATE booking_groups
     SET status='lost'
     WHERE status='option'
       AND option_expires_at IS NOT NULL
       AND option_expires_at < now()
     RETURNING id, supplier_id, group_code"
);
$expired = $q->fetchAll();
$notified = 0;
foreach ($expired as $g) {
    notify_supplier_users(
        (int) $g['supplier_id'],
        'group.option_expired',
        'Grup opsiyonu süresi doldu: ' . $g['group_code'] . ' — blokaj kaldırıldı.',
        '/nexustraveltech/tedarikci/grup-rezervasyonlari'
    );
    $notified++;
}
echo json_encode(['expired' => count($expired), 'notified' => $notified], JSON_UNESCAPED_UNICODE) . PHP_EOL;
