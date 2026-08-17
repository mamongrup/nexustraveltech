<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/platform_settings.php';
require_once __DIR__ . '/mailer.php';

/**
 * Bir panele bildirim ekler (best-effort; iş akışını asla bozmaz).
 */
function notify_user(string $userType, int $userId, string $type, string $message, ?string $link = null): void
{
    try {
        db()->prepare('INSERT INTO notifications(user_type,user_id,type,message,link) VALUES(?,?,?,?,?)')
            ->execute([
                $userType === 'agency' ? 'agency' : 'supplier',
                $userId,
                mb_substr($type, 0, 40),
                mb_substr($message, 0, 500),
                $link !== null ? mb_substr($link, 0, 500) : null,
            ]);
    } catch (Throwable $e) {
        // Bildirimler best-effort'tur.
    }
}

/**
 * Tedarikçiye bağlı tüm kullanıcılara bildirim gönderir.
 */
function notify_supplier_users(int $supplierId, string $type, string $message, ?string $link = null): void
{
    try {
        $q = db()->prepare('SELECT id FROM supplier_users WHERE supplier_id=?');
        $q->execute([$supplierId]);
        foreach ($q->fetchAll() as $row) {
            notify_user('supplier', (int) $row['id'], $type, $message, $link);
        }
    } catch (Throwable $e) {
        // Best-effort.
    }
}

/**
 * Tedarikçiye panel bildirimi gönderir; kontrol merkezindeki supplier_notify_email
 * ayarı açıksa aynı mesaj tedarikçi kullanıcılarının e-postalarına da kuyruklanır.
 * Best-effort — e-posta altyapısı/ayar hatası bildirimi asla bozmaz.
 */
function notify_supplier_users_with_email(int $supplierId, string $type, string $message, ?string $link = null, ?string $subject = null): void
{
    notify_supplier_users($supplierId, $type, $message, $link);
    try {
        if (!(bool) platform_setting('supplier_notify_email', false)) return;
        $q = db()->prepare('SELECT id, full_name, email FROM supplier_users WHERE supplier_id=? AND email<>?');
        $q->execute([$supplierId, '']);
        $linkAbs = $link !== null ? 'https://nexustraveltech.com' . $link : 'https://nexustraveltech.com/tedarikci';
        $subj = $subject !== null ? $subject : 'NEXUS bildirimi — ' . mb_substr(strip_tags($message), 0, 60);
        $body = '<p>Merhaba,</p><p>' . nl2br(htmlspecialchars($message)) . '</p>'
            . '<p><a href="' . htmlspecialchars($linkAbs) . '">Panele git →</a></p><p>NEXUS TravelTech</p>';
        foreach ($q->fetchAll() as $row) {
            queue_email((string) $row['email'], $subj, $body, 'supplier_notify', (int) $row['id']);
        }
    } catch (Throwable $e) {
        // Best-effort.
    }
}

function unread_notification_count(string $userType, int $userId): int
{
    $q = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_type=? AND user_id=? AND is_read=false');
    $q->execute([$userType, $userId]);
    return (int) $q->fetchColumn();
}

function recent_notifications(string $userType, int $userId, int $limit = 30): array
{
    $q = db()->prepare('SELECT * FROM notifications WHERE user_type=? AND user_id=? ORDER BY id DESC LIMIT ?');
    $q->bindValue(3, max(1, min(100, $limit)), PDO::PARAM_INT);
    $q->execute([$userType, $userId]);
    return $q->fetchAll();
}

function mark_notifications_read(string $userType, int $userId): void
{
    db()->prepare('UPDATE notifications SET is_read=true WHERE user_type=? AND user_id=? AND is_read=false')
        ->execute([$userType, $userId]);
}
