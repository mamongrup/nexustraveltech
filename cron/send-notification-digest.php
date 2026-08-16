<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';

/**
 * Günlük bildirim özeti: okunmamış panel bildirimi olan her tedarikçi ve acente
 * kullanıcısına son bildirimlerin özeti e-postayla gider. Bildirimler okunmuş
 * sayılmaz; yalnızca bilgilendirme.
 */
$pdo = db();
$emailed = 0;

$users = $pdo->query(
    "SELECT 'supplier' AS user_type, u.id, u.full_name, u.email
     FROM supplier_users u
     UNION ALL
     SELECT 'agency' AS user_type, u.id, u.full_name, u.email
     FROM agency_users u"
)->fetchAll();

foreach ($users as $u) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_type=? AND user_id=? AND is_read=false');
    $q->execute([$u['user_type'], $u['id']]);
    $count = (int) $q->fetchColumn();
    if ($count < 1) continue;

    $list = $pdo->prepare('SELECT message,created_at FROM notifications WHERE user_type=? AND user_id=? AND is_read=false ORDER BY id DESC LIMIT 10');
    $list->execute([$u['user_type'], $u['id']]);
    $rows = $list->fetchAll();

    $items = '';
    foreach ($rows as $n) {
        $items .= '<li>' . htmlspecialchars((string) $n['message']) . ' <small>(' . htmlspecialchars((string) $n['created_at']) . ')</small></li>';
    }
    $base = $u['user_type'] === 'supplier' ? 'tedarikci' : 'acente';
    $body = '<p>Sayın ' . htmlspecialchars((string) $u['full_name']) . ',</p>'
        . '<p>Panelinizde <b>' . $count . ' okunmamış bildirim</b> var:</p><ul>' . $items . '</ul>'
        . '<p><a href="https://nexustraveltech.com/' . $base . '/bildirimler">Bildirimleri görüntüle →</a></p>'
        . '<p>NEXUS TravelTech</p>';

    queue_email((string) $u['email'], 'NEXUS bildirim özeti — ' . $count . ' yeni', $body, 'notification_digest', $u['id']);
    $emailed++;
}

echo json_encode(['emailed' => $emailed], JSON_UNESCAPED_UNICODE) . PHP_EOL;
