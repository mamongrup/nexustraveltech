<?php
declare(strict_types=1);

/**
 * Suiistimal IP taraması — son 24 saatte hız sınırını aşan, aşırı soru gönderen veya
 * aynı soruyu tekrar tekrar soran IP'leri otomatik bayraklar; 7 gün içinde tekrar kötü
 * davranan bayraklı IP'leri TAM ENGELLEMEYE yükseltir ve sonuçları admin_alert_email'e bildirir.
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

const ABUSE_MSG_THRESHOLD = 40; // 24 saatte toplam soru
const ABUSE_DENY_THRESHOLD = 5; // 24 saatte hız sınırı reddi
const ABUSE_REPEAT_THRESHOLD = 10; // 24 saatte aynı sorunun tekrar sayısı
const ABUSE_BLOCKLIST_THRESHOLD = 3; // 24 saatte yasak kelime isabeti

$since = date('Y-m-d H:i:s', time() - 86400);
$pdo = db();

// 1) Hız sınırı reddi sayıları.
$denials = [];
$denyQ = $pdo->prepare("SELECT ip, COUNT(*) c FROM error_logs WHERE level='warning' AND message LIKE 'AI sohbet hız sınırı aşıldı%' AND created_at>=? GROUP BY ip");
$denyQ->execute([$since]);
foreach ($denyQ->fetchAll() as $r) {
    $denials[(string) $r['ip']] = (int) $r['c'];
}

// 1b) Yasak kelime isabetleri (endpoint reddederken error_logs'a yazar).
$blockHits = [];
$blkQ = $pdo->prepare("SELECT ip, COUNT(*) c FROM error_logs WHERE level='warning' AND message LIKE 'AI sohbet engellenen kelime%' AND created_at>=? GROUP BY ip");
$blkQ->execute([$since]);
foreach ($blkQ->fetchAll() as $r) {
    $blockHits[(string) $r['ip']] = (int) $r['c'];
}

// 2) Soru hacimleri.
$volumes = [];
$msgQ = $pdo->prepare('SELECT ip::text ip, COUNT(*) c FROM public_chat_messages WHERE created_at>=? GROUP BY ip');
$msgQ->execute([$since]);
foreach ($msgQ->fetchAll() as $r) {
    $volumes[(string) $r['ip']] = (int) $r['c'];
}

// 2b) Tekrarlanan aynı soru sayıları (24 saatte aynı metin).
$repeats = [];
$repQ = $pdo->prepare('SELECT ip::text ip, COUNT(*) c FROM public_chat_messages WHERE created_at>=? GROUP BY ip, LOWER(TRIM(user_message)) HAVING COUNT(*) >= ?');
$repQ->execute([$since, ABUSE_REPEAT_THRESHOLD]);
foreach ($repQ->fetchAll() as $r) {
    $ipKey = (string) $r['ip'];
    $repeats[$ipKey] = max((int) ($repeats[$ipKey] ?? 0), (int) $r['c']);
}

// 3) Şüphelileri birleştir: toplam soru, tekrarlanan soru veya hız sınırı reddi.
$suspects = [];
foreach ($volumes as $ip => $c) {
    $d = (int) ($denials[$ip] ?? 0);
    $rp = (int) ($repeats[$ip] ?? 0);
    $bl = (int) ($blockHits[$ip] ?? 0);
    if ($c >= ABUSE_MSG_THRESHOLD || $d >= ABUSE_DENY_THRESHOLD || $rp >= ABUSE_REPEAT_THRESHOLD || $bl >= ABUSE_BLOCKLIST_THRESHOLD) {
        $suspects[$ip] = ['msg' => $c, 'deny' => $d, 'repeat' => $rp, 'blk' => $bl];
    }
}
foreach ($denials as $ip => $d) {
    if (!isset($suspects[$ip]) && $d >= ABUSE_DENY_THRESHOLD) {
        $suspects[$ip] = ['msg' => (int) ($volumes[$ip] ?? 0), 'deny' => $d, 'repeat' => (int) ($repeats[$ip] ?? 0), 'blk' => (int) ($blockHits[$ip] ?? 0)];
    }
}
foreach ($repeats as $ip => $rp) {
    if (!isset($suspects[$ip]) && $rp >= ABUSE_REPEAT_THRESHOLD) {
        $suspects[$ip] = ['msg' => (int) ($volumes[$ip] ?? 0), 'deny' => (int) ($denials[$ip] ?? 0), 'repeat' => $rp, 'blk' => (int) ($blockHits[$ip] ?? 0)];
    }
}
foreach ($blockHits as $ip => $bl) {
    if (!isset($suspects[$ip]) && $bl >= ABUSE_BLOCKLIST_THRESHOLD) {
        $suspects[$ip] = ['msg' => (int) ($volumes[$ip] ?? 0), 'deny' => (int) ($denials[$ip] ?? 0), 'repeat' => (int) ($repeats[$ip] ?? 0), 'blk' => $bl];
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

$reasonFor = function (array $info): string {
    $parts = [];
    if ($info['msg'] > 0) $parts[] = $info['msg'] . ' soru';
    if ($info['repeat'] > 0) $parts[] = $info['repeat'] . ' kez aynı soru';
    if ($info['deny'] > 0) $parts[] = $info['deny'] . ' hız sınırı reddi';
    if (($info['blk'] ?? 0) > 0) $parts[] = $info['blk'] . ' kez yasak kelime';
    return 'Otomatik: 24s\'te ' . implode(', ', $parts);
};

foreach ($suspects as $ip => $info) {
    $infoReason = $reasonFor($info);
    $chk->execute([$ip]);
    $existing = $chk->fetch();
    if ($existing) {
        if ((string) $existing['action'] === 'flag') {
            $flaggedAt = strtotime((string) ($existing['created_at'] ?? ''));
            if ($flaggedAt !== false && ($now - $flaggedAt) <= $window) {
                // 7 gün içinde tekrar → tam engelle.
                $escReason = 'Otomatik yükseltme: 7 gün içinde tekrar kötü davranış (' . implode(', ', array_filter([$info['msg'] > 0 ? $info['msg'] . ' soru' : null, $info['repeat'] > 0 ? $info['repeat'] . ' kez aynı soru' : null, $info['deny'] > 0 ? $info['deny'] . ' hız sınırı reddi' : null, ($info['blk'] ?? 0) > 0 ? $info['blk'] . ' kez yasak kelime' : null])) . ')';
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
            . '<td style="padding:8px 12px;border-bottom:1px solid #e1e5de;text-align:center">' . $n['repeat'] . '</td>'
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
        . '<th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Tekrar</th>'
        . '<th style="padding:8px 12px;background:#f2f4ef;font-size:11px;text-transform:uppercase;color:#64716d">Red</th></tr>'
        . $rows
        . '</table>'
        . '<p style="margin-top:18px"><a href="https://nexustraveltech.com/admin/ziyaretci-sohbet" style="color:#0d7a4a">Kayıtları incele →</a></p>'
        . '</div>';
    queue_email($to, 'IP koruması: ' . $summary, $body, 'abuse_flag', (int) date('Ymd'));
}

echo count($new) . ' IP bayraklandı, ' . count($escalated) . ' IP engellendi (toplam şüpheli: ' . count($suspects) . ").\n";
