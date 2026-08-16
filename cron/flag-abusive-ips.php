<?php
declare(strict_types=1);

/**
 * Suiistimal IP taraması — son 24 saatte hız sınırını aşan veya aşırı soru gönderen
 * IP'leri otomatik bayraklar ve yeni bayrakları admin_alert_email adresine bildirir.
 *
 * Sinyaller:
 *  - error_logs'taki "AI sohbet hız sınırı aşıldı" uyarıları (endpoint 429 dönerken yazar)
 *  - public_chat_messages'taki soru hacmi
 * Eşikler: >= ABUSE_DENY_THRESHOLD red veya >= ABUSE_MSG_THRESHOLD soru (24 saat).
 * Zaten engelli/bayraklı IP'ler atlanır; bayrak e-postası yalnızca yeni işaretlerde gider.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/platform_settings.php';

const ABUSE_MSG_THRESHOLD = 40; // 24 saatte soru
const ABUSE_DENY_THRESHOLD = 5; // 24 saatte hız sınırı reddi

$since = date('Y-m-d H:i:s', time() - 86400);
$pdo = db();

// 1) Hız sınırı reddi sayıları.
$denials = [];
$denyQ = $pdo->prepare("SELECT ip, COUNT(*) c FROM error_logs WHERE level='warning' AND message LIKE 'AI sohbet hız sınırı aşıldı%' AND created_at>=? GROUP BY ip");
$denyQ->execute([$since]);
foreach ($denyQ->fetchAll() as $r) {
    $denials[(string) $r['ip']] = (int) $r['c'];
}

// 2) Soru hacimleri.
$volumes = [];
$msgQ = $pdo->prepare('SELECT ip::text ip, COUNT(*) c FROM public_chat_messages WHERE created_at>=? GROUP BY ip');
$msgQ->execute([$since]);
foreach ($msgQ->fetchAll() as $r) {
    $volumes[(string) $r['ip']] = (int) $r['c'];
}

// 3) Şüphelileri birleştir.
$suspects = [];
foreach ($volumes as $ip => $c) {
    $d = (int) ($denials[$ip] ?? 0);
    if ($c >= ABUSE_MSG_THRESHOLD || $d >= ABUSE_DENY_THRESHOLD) {
        $suspects[$ip] = ['msg' => $c, 'deny' => $d];
    }
}
foreach ($denials as $ip => $d) {
    if (!isset($suspects[$ip]) && $d >= ABUSE_DENY_THRESHOLD) {
        $suspects[$ip] = ['msg' => (int) ($volumes[$ip] ?? 0), 'deny' => $d];
    }
}

if (!$suspects) {
    echo "Son 24 saatte şüpheli IP yok.\n";
    exit(0);
}

// 4) Zaten işaretli olmayanları bayrakla.
$ins = $pdo->prepare("INSERT INTO blocked_ips(ip,action,reason,created_by) VALUES(?::inet,'flag',?,?) ON CONFLICT(ip) DO NOTHING");
$chk = $pdo->prepare('SELECT 1 FROM blocked_ips WHERE ip=?::inet');
$new = [];
foreach ($suspects as $ip => $info) {
    $chk->execute([$ip]);
    if ($chk->fetch()) continue;
    $reason = 'Otomatik: 24s\'te ' . $info['msg'] . ' soru, ' . $info['deny'] . ' hız sınırı reddi';
    $ins->execute([$ip, $reason, 'nexus-flag-abusive-ips']);
    $new[] = ['ip' => $ip, 'msg' => $info['msg'], 'deny' => $info['deny']];
}

if (!$new) {
    echo count($suspects) . " şüpheli bulundu ancak hepsi zaten işaretli; e-posta gönderilmedi.\n";
    exit(0);
}

// 5) Yeni bayrakları bildir.
$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $rows = '';
    foreach ($new as $n) {
        $rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #e1e5de;font-family:monospace">' . htmlspecialchars($n['ip']) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $n['msg'] . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $n['deny'] . '</td></tr>';
    }
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">Bayraklanan IP' . (count($new) > 1 ? 'ler' : '') . '</h2>'
        . '<p style="color:#64716d;margin:0 0 16px">Son 24 saatlik otomatik tarama, ' . count($new) . ' IP' . (count($new) > 1 ? 'yi' : 'yi') . ' şüpheli işaretledi.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:560px">'
        . '<tr><th style="text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">IP</th>'
        . '<th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Soru</th>'
        . '<th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Red</th></tr>'
        . $rows
        . '</table>'
        . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/ziyaretci-sohbet" style="color:#0d7a4a">Kayıtları incele ve gerekirse engelle →</a></p>'
        . '</div>';
    queue_email($to, 'Otomatik bayraklanan IP' . (count($new) > 1 ? 'ler' : '') . ' (' . count($new) . ')', $body, 'abuse_flag', (int) date('Ymd'));
}

echo count($new) . " IP otomatik bayraklandı (toplam şüpheli: " . count($suspects) . ").\n";
