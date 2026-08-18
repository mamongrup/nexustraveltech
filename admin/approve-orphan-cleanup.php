<?php
declare(strict_types=1);

// Yetim eşleştirme temizliği onay sayfası — İKİ ADIMLI:
//   Adım 1 (GET): e-posta bağlantısı tıklanınca temizlenecek yetim eşleştirmeler özetlenir,
//                 hiçbir şey silinmez (e-posta önizleme botları linki tıklasa bile).
//   Adım 2 (POST): "Evet, temizle" butonu health_orphan_cleanup() ile satırları siler.
// Tek kullanımlık 64 hex token (platform ayarında 3 gün); kullanıldığında temizlenir.
// Bağlantıyı günlük sağlık e-postası (cron/health-check-alert.php) üretir.

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';
require_once __DIR__ . '/../config/health.php';
require_once __DIR__ . '/../config/audit.php';

$token = (string) ($_GET['token'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$out = '';
$ok = false;
$confirm = null; // ['token' => ..., 'items' => list<row>, 'total' => int, 'tables' => array]

if ($token === '') {
    http_response_code(404);
    $out = 'Geçersiz bağlantı.';
} elseif (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    $out = 'Geçersiz bağlantı.';
} else {
    $pdo = db();
    $stored = (array) platform_setting('orphan_cleanup_approve', []);
    $storedToken = (string) ($stored['token'] ?? '');
    $expires = (string) ($stored['expires_at'] ?? '');
    if ($storedToken === '' || !hash_equals($storedToken, $token)) {
        $out = 'Bu temizleme bağlantısı geçersiz veya zaten kullanıldı.';
    } elseif ($expires === '' || strtotime($expires) < time()) {
        $out = 'Bu temizleme bağlantısının süresi doldu — yeni sağlık taraması e-postayı yeniden gönderecek.';
    } else {
        // Şu anki yetim durumunu listele (token üretilirken kaydedilen değil — güncel tarama).
        $items = [];
        $tables = ['channel_room_mappings', 'channel_rate_plan_mappings', 'channel_property_mappings', 'ical_connections'];
        $labelMap = ['channel_room_mappings' => 'oda eşleştirmesi', 'channel_rate_plan_mappings' => 'fiyat planı eşleştirmesi', 'channel_property_mappings' => 'ürün eşleştirmesi', 'ical_connections' => 'iCal bağlantısı'];
        $codeMap = ['channel_room_mappings' => 'external_room_id', 'channel_rate_plan_mappings' => 'external_rate_plan_id', 'channel_property_mappings' => 'external_property_id', 'ical_connections' => 'label'];
        foreach ($tables as $t) {
            try {
                $tbl = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $t . "'")->fetchColumn();
                if (!$tbl) continue;
                $join = [
                    'channel_room_mappings' => 'LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id',
                    'channel_rate_plan_mappings' => 'LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id',
                    'channel_property_mappings' => 'LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id', 'ical_connections' => 'LEFT JOIN properties p ON p.id=m.property_id',
                ][$t];
                $where = [
                    'channel_room_mappings' => 'm.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))',
                    'channel_rate_plan_mappings' => '(m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL',
                    'channel_property_mappings' => 'p.id IS NULL OR c.id IS NULL', 'ical_connections' => 'p.id IS NULL',
                ][$t];
                $rows = $pdo->query('SELECT m.id, m.' . $codeMap[$t] . ' AS code, m.status FROM ' . $t . ' m ' . $join . ' WHERE ' . $where . ' ORDER BY m.id')->fetchAll();
                if ($rows) {
                    $items[$t] = ['label' => $labelMap[$t], 'rows' => $rows];
                }
            } catch (Throwable $e) {
                $out = 'Yetim taraması yapılamadı: ' . $e->getMessage();
                $items = [];
                break;
            }
        }
        if ($out === '' && $items === []) {
            $out = 'Şu an temizlenecek yetim eşleştirme yok (daha önce temizlenmiş olabilir).';
        } elseif ($out === '') {
            if ($method === 'POST') {
                // İsteğe bağlı parola doğrulaması — platform ayarı açıkken token tek başına yetmez.
                $pwRequired = (bool) platform_setting('orphan_cleanup_require_password', false);
                $pwOk = !$pwRequired; // Kapalıysa parola gerekmez
                if ($pwRequired) {
                    $adminPass = trim((string) ($_POST['admin_password'] ?? ''));
                    $creds = admin_credentials();
                    $pwOk = hash_equals($creds['password'], $adminPass);
                }
                if (!$pwOk) {
                    $out = '❌ Parola doğrulaması başarısız — temizleme iptal edildi.';
                    $confirm = ['token' => $token, 'items' => $items, 'total' => $total, 'pw_error' => true];
                } else {
                $res = health_orphan_cleanup($pdo, false);
                save_platform_setting('orphan_cleanup_approve', []);
                if ($res['removed'] > 0) {
                    audit_log('health.orphan_cleanup_approved', 'schema', null, [
                        'total' => $res['removed'],
                        'codes' => $res['codes'],
                        'ran_at' => gmdate('c'),
                        'note' => 'e-posta onay bağlantısıyla yetim eşleştirmeler temizlendi',
                    ], 'health-check');
                }
                // Temizlik sonrası kalan yetim sayısını say.
                $remainingTotal = 0;
                $remainingParts = [];
                foreach ($tables as $t) {
                    try {
                        $tbl = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='" . $t . "'")->fetchColumn();
                        if (!$tbl) continue;
                        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM ' . $t . ' m ' . $join[$t] . ' WHERE ' . $where[$t])->fetchColumn();
                        if ($cnt > 0) { $remainingTotal += $cnt; $remainingParts[] = $labelMap[$t] . ': ' . $cnt; }
                    } catch (Throwable $e) {}
                }
                // Temizlenen kodların listesi.
                $cleanedCodes = [];
                foreach ($res['codes'] as $tbl => $codes) {
                    $lbl = $labelMap[$tbl] ?? $tbl;
                    foreach (array_slice($codes, 0, 5) as $c) {
                        $cleanedCodes[] = htmlspecialchars($c) . ' <span class="mini">(' . htmlspecialchars($lbl) . ')</span>';
                    }
                    if (count($codes) > 5) $cleanedCodes[] = '<span class="mini">… +' . (count($codes) - 5) . ' daha (' . htmlspecialchars($lbl) . ')</span>';
                }
                $codeList = $cleanedCodes !== [] ? '<div style="margin:12px 0 0;font-size:13px"><b>Temizlenen kodlar:</b><ul style="margin:6px 0 0;padding-left:18px">' . implode('', array_map(fn($c) => '<li><code>' . $c . '</code></li>', $cleanedCodes)) . '</ul></div>' : '';
                $remainTxt = $remainingTotal > 0
                    ? '<div style="margin:10px 0 0;padding:8px 12px;background:#fff3cd;border:1px solid #e0c9a3;border-radius:6px;font-size:13px">⚠ <b>' . $remainingTotal . ' yetim eşleştirme hâlâ mevcut</b> (' . htmlspecialchars(implode(', ', $remainingParts)) . ') — <a href="/nexustraveltech/admin/orphan-mappings" style="color:#8a6100;font-weight:bold">yönetim sayfasına git →</a></div>'
                    : '<div style="margin:10px 0 0;padding:8px 12px;background:#e6f8c7;border:1px solid #bcd98a;border-radius:6px;font-size:13px">✅ <b>Tüm yetimler temizlendi</b> — kalan yetim yok.</div>';
                $out = '✓ <b>' . $res['removed'] . ' yetim eşleştirme temizlendi</b> (oda / fiyat planı / ürün). Bu noktadan sonra geri alınamaz.' . $codeList . $remainTxt;
                $ok = true;
                } // pw check else
            } else {
                $total = 0;
                foreach ($items as $t) {
                    $total += count($t['rows']);
                }
                $confirm = ['token' => $token, 'items' => $items, 'total' => $total];
            }
        }
    }
}
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Yetim temizleme onayı | NEXUS Admin</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}.card{background:#fff;border:1px solid #e1e5de;border-radius:10px;padding:28px 32px;max-width:600px;width:calc(100% - 48px);box-shadow:0 4px 18px rgba(0,0,0,.05)}h1{font-size:20px;margin:0 0 10px}.ok{background:#e6f8c7;padding:12px;border-radius:6px}.no{background:#ffe2de;padding:12px;border-radius:6px}.warn{background:#fff3cd;border:1px solid #e0c9a3;border-radius:8px;padding:12px;margin:14px 0}.warn ul{margin:8px 0 0;padding-left:18px}.back{display:inline-block;margin-top:14px;color:#10211f}.danger{background:#9d3b1c;color:#fff;border:0;padding:12px 18px;font-size:15px;font-weight:bold;border-radius:6px;cursor:pointer;margin-right:10px}button{cursor:pointer}.mini{color:#6b7774;font-size:12px}.pw-err{background:#ffe2de;border:1px solid #e8b4b0;padding:8px;border-radius:6px;margin:8px 0;font-size:13px}</style></head><body><div class="card">
<?php if ($confirm !== null): ?>
<h1>🧹 Yetim eşleştirme temizliği onayı</h1>
<div class="warn"><p style="margin:0">Aşağıdaki <b style="color:#9d3b1c"><?= (int) $confirm['total'] ?> yetim eşleştirme</b> silinmiş oda tipi / fiyat planı / kanal / ürüne işaret ediyor. Onaylarsanız <b style="color:#9d3b1c">kalıcı olarak silinir</b> ve geri alınamaz — webhook yazımı bu satırlarda zaten başarısız olur, bu yüzden temizlik veri kaybı üretmez.</p>
<?php foreach ($confirm['items'] as $t): $tab = $t; ?>
<div style="margin-top:12px"><b><?= htmlspecialchars($t['label']) ?></b> — <?= count($t['rows']) ?> satır <span class="mini">(<?= htmlspecialchars($tab['label'] ?? '') ?>: <?= htmlspecialchars((string) $t['rows'][0]['code']) ?><?= count($t['rows']) > 1 ? ' …' : '' ?>)</span>
<ul><?php foreach (array_slice($t['rows'], 0, 8) as $r): ?><li>#<?= (int) $r['id'] ?> · <code><?= htmlspecialchars((string) $r['code']) ?></code> <span class="mini">(<?= htmlspecialchars((string) ($r['status'] ?? '')) ?>)</span></li><?php endforeach; ?><?php if (count($t['rows']) > 8): ?><li class="mini">… ve <?= count($t['rows']) - 8 ?> satır daha</li><?php endif; ?></ul></div>
<?php endforeach; ?>
</div>
<?php if (!empty($confirm['pw_error'])): ?><div class="pw-err">❌ Parola doğrulaması başarısız — lütfen doğru parolayı girin.</div><?php endif; ?><form method="post"><input type="hidden" name="token" value="<?= htmlspecialchars($confirm['token']) ?>"><?php if ((bool) platform_setting('orphan_cleanup_require_password', false)): ?><div style="margin:10px 0"><label style="font-size:13px;font-weight:bold">Yönetici parolası:<input type="password" name="admin_password" autocomplete="off" placeholder="Parolanızı girin" style="display:block;width:100%;padding:10px;border:1px solid #d8ded8;font:inherit;margin-top:4px"></label></div><?php endif; ?><button class="danger">Evet, <?= (int) $confirm['total'] ?> yetimi temizle</button><a class="back" href="/nexustraveltech/admin/">&nbsp;Vazgeç</a></form>
<?php else: ?>
<h1>🧹 Yetim eşleştirme temizliği onayı</h1>
<div class="<?= $ok ? 'ok' : 'no' ?>"><?= htmlspecialchars($out) ?></div>
<a class="back" href="/nexustraveltech/admin/">← Panele dön</a>
<?php endif; ?>
</div></body></html>
