<?php
declare(strict_types=1);

/**
 * Suiistimal IP taraması — son 24 saatte hız sınırını aşan veya aşırı soru gönderen
 * IP'leri otomatik bayraklar; 7 gün içinde tekrar kötü davranan bayraklı IP'leri TAM
 * ENGELLEMEYE yükseltir ve sonuçları admin_alert_email adresine bildirir.
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

// 4) İşaretle / yükselt: yeni şüpheliler bayraklanır; 7 gün içinde tekrar kötü
//    davranan bayraklı IP'ler TAM ENGELLEMEYE yükseltilir; eski bayraklar yenilenir.
$chk = $pdo->prepare('SELECT action, created_at FROM blocked_ips WHERE ip=?::inet');
$flagIns = $pdo->prepare("INSERT INTO blocked_ips(ip,action,reason,created_by) VALUES(?::inet,'flag',?,?) ON CONFLICT(ip) DO NOTHING");
$escalate = $pdo->prepare("UPDATE blocked_ips SET action='block', reason=?, created_at=now() WHERE ip=?::inet AND action='flag'");
$refresh = $pdo->prepare("UPDATE blocked_ips SET created_at=now(), reason=? WHERE ip=?::inet AND action='flag'");

$now = time();
$window = 7 * 86400;
$new = [];
$escalated = [];

foreach ($suspects as $ip => $info) {
    $infoReason = 'Otomatik: 24s\'te ' . $info['msg'] . ' soru, ' . $info['deny'] . ' hız sınırı reddi';
    $chk->execute([$ip]);
    $existing = $chk->fetch();
    if ($existing) {
        if ((string) $existing['action'] === 'flag') {
            $flaggedAt = strtotime((string) ($existing['created_at'] ?? ''));
            if ($flaggedAt !== false && ($now - $flaggedAt) <= $window) {
                // 7 gün içinde tekrar → tam engelle.
                $escReason = 'Otomatik yükseltme: 7 gün içinde tekrar kötü davranış (' . $info['msg'] . ' soru, ' . $info['deny'] . ' red)';
                $escalate->execute([$escReason, $ip]);
                $escalated[] = ['ip' => $ip, 'msg' => $info['msg'], 'deny' => $info['deny']];
            } else {
                // Bayrak eski: pencere yeniden başlar (yeni ihlal = yeni bayrak).
                $refresh->execute([$infoReason, $ip]);
            }
        }
        continue; // zaten tam engelli: dokunma.
    }
    $flagIns->execute([$ip, $infoReason, 'nexus-flag-abusive-ips']);
    $new[] = ['ip' => $ip, 'msg' => $info['msg'], 'deny' => $info['deny']];
}

if (!$new && !$escalated) {
    echo count($suspects) . " şüpheli bulundu; yeni bayrak/yükseltme yok, e-posta gönderilmedi.\n";
    exit(0);
}

// 5) Bildirim: yeni bayraklar + yükseltmeler birlikte.
$to = trim((string) platform_setting('admin_alert_email', ''));
if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $flagRow = function (string $label, array $n): string {
        $color = $label === '🚫 Engellendi' ? '#ffe2de' : '#fdf0d8';
        return '<tr><td style="padding:8px 12px;border-bottom:1px solid #e1e5de"><span style="background:' . $color . ';padding:2px 7px;font-size:11px;font-weight:700;border-radius:3px">' . $label . '</span></td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e1e5de;font-family:monospace">' . htmlspecialchars($n['ip']) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $n['msg'] . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $n['deny'] . '</td></tr>';
    };
    $rows = '';
    foreach ($new as $n) $rows .= $flagRow('⚠ Yeni bayrak', $n);
    foreach ($escalated as $n) $rows .= $flagRow('🚫 Engellendi', $n);
    $summary = count($new) . ' yeni bayrak, ' . count($escalated) . ' yükseltme';
    $body = '<div style="font-family:Arial,sans-serif;color:#10211f">'
        . '<h2 style="margin:0 0 6px">Otomatik IP koruması raporu</h2>'
        . '<p style="color:#64716d;margin:0 0 16px">Son 24 saatlik tarama: ' . $summary . '.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:640px">'
        . '<tr><th style="text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Durum</th>'
        . '<th style="text-align:left;padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">IP</th>'
        . '<th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Soru</th>'
        . '<th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Red</th></tr>'
        . $rows
        . '</table>'
        . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/ziyaretci-sohbet" style="color:#0d7a4a">Kayıtları incele →</a></p>'
        . '</div>';
    queue_email($to, 'IP koruması: ' . $summary, $body, 'abuse_flag', (int) date('Ymd'));
}

echo count($new) . ' IP bayraklandı, ' . count($escalated) . ' IP engellendi (toplam şüpheli: ' . count($suspects) . ").\n";
