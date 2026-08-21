<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/platform_settings.php';
require __DIR__ . '/../config/ai_settings.php';
require __DIR__ . '/../config/chat_topics.php';
require __DIR__ . '/../config/scheduler.php';
require __DIR__ . '/../config/mapping_suggestions.php';

require_admin();

$leads = db()->query('SELECT * FROM early_access_leads ORDER BY created_at DESC LIMIT 200')->fetchAll();
$total = (int) db()->query('SELECT COUNT(*) FROM early_access_leads')->fetchColumn();
$identityAlerts = (int) db()->query("SELECT COUNT(*) FROM admin_alerts WHERE is_read=false AND alert_type='identity_verification_failed'")->fetchColumn();
$blockCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='block'")->fetchColumn();
$flagCount = (int) db()->query("SELECT COUNT(*) FROM blocked_ips WHERE action='flag'")->fetchColumn();
$adminPending = admin_pending_mapping_suggestions(db());

// Yaklasan kalici silme uyarisi
$upcomingPurge = ['count' => 0, 'items' => []];
try {
    $ttlTmp = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
    $upRows = db()->query("SELECT id, label, code, deleted_at, purge_at FROM property_feature_catalog WHERE deleted_at IS NOT NULL ORDER BY deleted_at")->fetchAll();
    foreach ($upRows as $ur) {
        $dTs = strtotime((string)$ur['deleted_at']) ?: 0;
        if ($dTs <= 0) continue;
        $custom = !empty($ur['purge_at']);
        $pTs = $custom ? (strtotime((string)$ur['purge_at']) ?: 0) : 0;
        if ($pTs <= 0) $pTs = $dTs + $ttlTmp * 86400;
        $diff = $pTs - time();
        $upWarnDays = max(1, (int) platform_setting('trash_upcoming_warning_days', 3));
        if ($diff <= 0 || $diff > $upWarnDays * 86400) continue;
        $upcomingPurge['count']++;
        $upcomingPurge['items'][] = ['label'=>(string)$ur['label'],'purge_date'=>date('Y-m-d',$pTs),'remain'=>max(1,(int)ceil($diff/86400))];
    }
} catch (Throwable $e) {}

// Yetim eşleştirme
$orphanTotal = 0;
$orphanBreakdown = [];
try {
    $pdo = db();
    $orphanQueries = [
        ['channel_room_mappings', 'Oda', "m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id)", 'LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id'],
        ['channel_rate_plan_mappings', 'Plan', '(m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL', 'LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id'],
        ['channel_property_mappings', 'Ürün', 'p.id IS NULL OR c.id IS NULL', 'LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id'],
        ['ical_connections', 'iCal', 'p.id IS NULL', 'LEFT JOIN properties p ON p.id=c.property_id'],
    ];
    foreach ($orphanQueries as [$tbl, $label, $where, $join]) {
        $tblOk = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='$tbl'")->fetchColumn();
        if (!$tblOk) continue;
        try {
            $cnt = (int) $pdo->query("SELECT COUNT(*) FROM $tbl m $join WHERE $where")->fetchColumn();
            if ($cnt > 0) { $orphanTotal += $cnt; $orphanBreakdown[] = $label . ': ' . $cnt; }
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {}

// Sohbet
$chatMinLen = max(1, (int) platform_setting('chat_min_length', 5));
$chatRequireSpace = (bool) platform_setting('chat_require_space', true);
$chatBlocklist = array_values(array_filter(array_map('trim', (array) platform_setting('chat_blocklist', [])), fn($l) => $l !== ''));
$topicRespCount = count(array_filter((array) platform_setting('chat_topic_responses', []), fn($c) => trim((string) ($c['text'] ?? '')) !== '' || trim((string) ($c['link'] ?? '')) !== ''));
$chatToday = (int) db()->query('SELECT COUNT(*) FROM public_chat_messages WHERE created_at >= CURRENT_DATE')->fetchColumn();
$chatWeek = [];
for ($i = 6; $i >= 0; $i--) { $chatWeek[date('Y-m-d', time() - $i * 86400)] = 0; }
foreach (db()->query('SELECT created_at::date d, COUNT(*) c FROM public_chat_messages WHERE created_at >= CURRENT_DATE - 6 GROUP BY created_at::date')->fetchAll() as $r) {
    $d = (string) $r['d'];
    if (isset($chatWeek[$d])) $chatWeek[$d] = (int) $r['c'];
}
$chatWeekMax = max(1, max($chatWeek));
$aiKeyReady = false;
try { $aiKeyReady = deepseek_settings()['api_key'] !== ''; } catch (Throwable $e) {}

// Zamanlayıcı
$schedulerJobs = scheduler_jobs();
$timerAvg7 = (int) db()->query("SELECT COALESCE(AVG(duration_ms),0) FROM scheduled_job_runs WHERE created_at >= now() - interval '7 days'")->fetchColumn();

require_once __DIR__.'/layout.php';
admin_layout_start('Dashboard', 'index');
?>

<!-- Stats Row -->
<div class="sui-stats">
    <a href="/nexustraveltech/admin/tedarikci-ilanlari" class="sui-stat">
        <div class="sui-stat-icon purple"><i class="fas fa-hotel"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Toplam Başvuru</div>
            <div class="sui-stat-value"><?= number_format($total) ?></div>
        </div>
    </a>
    <a href="/nexustraveltech/admin/ziyaretci-sohbet" class="sui-stat">
        <div class="sui-stat-icon red"><i class="fas fa-ban"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Engelli IP</div>
            <div class="sui-stat-value"><?= (int)$blockCount ?></div>
        </div>
    </a>
    <a href="/nexustraveltech/admin/ziyaretci-sohbet" class="sui-stat">
        <div class="sui-stat-icon orange"><i class="fas fa-flag"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Bayraklı IP</div>
            <div class="sui-stat-value"><?= (int)$flagCount ?></div>
        </div>
    </a>
    <a href="/nexustraveltech/admin/orphan-mappings" class="sui-stat">
        <div class="sui-stat-icon teal"><i class="fas fa-exchange-alt"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Bekleyen Öneri</div>
            <div class="sui-stat-value"><?= (int)$adminPending['total'] ?></div>
        </div>
    </a>
    <?php if ($upcomingPurge["count"] > 0): ?>
    <a href="/nexustraveltech/admin/ozellik-listeleri#trash" class="sui-stat">
        <div class="sui-stat-icon red"><i class="fas fa-trash-alt"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Yaklaşan Silme</div>
            <div class="sui-stat-value"><?= (int)$upcomingPurge["count"] ?></div>
        </div>
    </a>
    <?php endif; ?>
    <?php if ($orphanTotal > 0): ?>
    <a href="/nexustraveltech/admin/orphan-mappings" class="sui-stat">
        <div class="sui-stat-icon red"><i class="fas fa-broom"></i></div>
        <div class="sui-stat-info">
            <div class="sui-stat-label">Yetim Eşleştirme</div>
            <div class="sui-stat-value"><?= (int)$orphanTotal ?></div>
        </div>
    </a>
    <?php endif; ?>
</div>

<!-- Kimlik Uyarısı -->
<?php if ($identityAlerts > 0): ?>
<div class="sui-alert warning">
    ⚠️ <b><?= $identityAlerts ?> kimlik doğrulama uyarısı</b> —
    <a href="/nexustraveltech/admin/tedarikci-onaylari" style="color:inherit;font-weight:700;text-decoration:underline">Onay sayfasına git →</a>
</div>
<?php endif; ?>

<!-- Sohbet Ayarları Kartı -->
<div class="sui-card">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">💬 Sohbet Özeti</h2>
            <p class="sui-card-subtitle">Son 7 gün · Ziyaretçi asistanı performansı</p>
        </div>
        <a href="/nexustraveltech/admin/kontrol-merkezi" class="sui-btn sui-btn-outline sui-btn-sm">Düzenle →</a>
    </div>
    <div class="sui-stats" style="margin-bottom:0">
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f8f9fa;border-radius:var(--sui-radius-xs)">
            <div class="sui-stat-icon blue" style="width:36px;height:36px;font-size:14px"><i class="fas fa-comment-dots"></i></div>
            <div><div class="sui-stat-label">Bugünkü Soru</div><div style="font-size:18px;font-weight:700"><?= (int)$chatToday ?></div></div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f8f9fa;border-radius:var(--sui-radius-xs)">
            <div class="sui-stat-icon green" style="width:36px;height:36px;font-size:14px"><i class="fas fa-key"></i></div>
            <div><div class="sui-stat-label">DeepSeek Anahtarı</div><div style="font-size:14px;font-weight:700;color:<?= $aiKeyReady ? '#17c964' : '#f5a623' ?>"><?= $aiKeyReady ? '✅ Aktif' : '⚠ Eksik' ?></div></div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f8f9fa;border-radius:var(--sui-radius-xs)">
            <div class="sui-stat-icon indigo" style="width:36px;height:36px;font-size:14px"><i class="fas fa-clock"></i></div>
            <div><div class="sui-stat-label">Ortalama Süre</div><div style="font-size:18px;font-weight:700"><?= number_format($timerAvg7) ?> ms</div></div>
        </div>
    </div>
    
    <!-- Haftalık Grafik -->
    <div style="margin-top:16px">
        <div style="font-size:12px;font-weight:600;color:var(--sui-muted);margin-bottom:8px">Son 7 Gün Soru Eğilimi</div>
        <div style="display:flex;align-items:flex-end;gap:3px;height:40px">
            <?php foreach ($chatWeek as $d => $c): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;min-width:0" title="<?= htmlspecialchars($d) ?>: <?= $c ?> soru">
                <div style="width:100%;background:linear-gradient(180deg,#7928ca,#ff0080);border-radius:3px 3px 0 0;height:<?= max(2, (int) round($c / $chatWeekMax * 35)) ?>px;transition:height .3s"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:3px;margin-top:4px">
            <?php foreach ($chatWeek as $d => $c): ?>
            <div style="flex:1;text-align:center;font-size:9px;color:var(--sui-muted);min-width:0"><?= date('D', strtotime($d)) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Hızlı Bağlantılar -->
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">⚡ Hızlı Erişim</h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">
        <?php
        $quickLinks = [
            ['label'=>'Pricing Coach (Otopilot)','icon'=>'fa-solid fa-gauge-high','href'=>'pricing-coach','color'=>'teal'],
            ['label'=>'Fiyat & Müsaitlik Matrisi','icon'=>'fa-solid fa-calendar-days','href'=>'fiyat-matrisi','color'=>'orange'],
            ['label'=>'AI Gelir Yöneticisi','icon'=>'fa-solid fa-brain','href'=>'ai-gelir-yonetimi','color'=>'purple'],
            ['label'=>'Misafir CRM & Sadakat','icon'=>'fa-solid fa-users','href'=>'misafir-crm','color'=>'purple'],
            ['label'=>'Kat Hizmetleri (HK)','icon'=>'fa-solid fa-broom','href'=>'kat-hizmetleri','color'=>'orange'],
            ['label'=>'KBS Kimlik Bildirimi','icon'=>'fa-solid fa-id-card-clip','href'=>'kbs-bildirim','color'=>'teal'],
            ['label'=>'Kanal Sihirbazı','icon'=>'fa-solid fa-wand-magic-sparkles','href'=>'kanal-sihirbazi','color'=>'teal'],
            ['label'=>'Kontrol Merkezi','icon'=>'fa-solid fa-sliders','href'=>'kontrol-merkezi','color'=>'blue'],
            ['label'=>'Dağıtım Sağlığı','icon'=>'fa-solid fa-network-wired','href'=>'dagitim-sagligi','color'=>'purple'],
            ['label'=>'Hazırlık Özeti','icon'=>'fa-solid fa-chart-simple','href'=>'hazirlik-ozet','color'=>'green'],
            ['label'=>'Ürün Şablonları','icon'=>'fa-solid fa-layer-group','href'=>'urun-turleri','color'=>'teal'],
            ['label'=>'Tedarikçi Onayları','icon'=>'fa-solid fa-clipboard-check','href'=>'tedarikci-onaylari','color'=>'green'],
            ['label'=>'Katalog Yönetimi','icon'=>'fa-solid fa-list-check','href'=>'ozellik-listeleri','color'=>'indigo'],
            ['label'=>'Denetim Kayıtları','icon'=>'fa-solid fa-shield-halved','href'=>'denetim-kayitlari','color'=>'blue'],
            ['label'=>'Zamanlayıcılar','icon'=>'fa-solid fa-stopwatch','href'=>'timerlar','color'=>'orange'],
            ['label'=>'DeepSeek AI','icon'=>'fa-solid fa-brain','href'=>'ai-ayarlari','color'=>'purple'],
            ['label'=>'Gemini AI','icon'=>'fa-solid fa-wand-magic-sparkles','href'=>'gemini-ayarlari','color'=>'pink'],
            ['label'=>'Uyarı Merkezi','icon'=>'fa-solid fa-bell','href'=>'uyari-merkezi','color'=>'orange'],
            ['label'=>'Kullanım Kılavuzu','icon'=>'fa-solid fa-book-bookmark','href'=>'kullanim-kilavuzu','color'=>'green'],
        ];
        foreach ($quickLinks as $ql): ?>
        <a href="<?= $ql['href'] ?>" class="sui-nav-item" style="margin:0;padding:12px 14px;background:#fff;border:1px solid var(--sui-border);border-radius:12px;box-shadow:var(--sui-shadow-sm);text-decoration:none;transition:all 0.2s">
            <div class="sui-icon-box <?= $ql['color'] ?>">
                <i class="<?= $ql['icon'] ?>"></i>
            </div>
            <span style="font-size:13px;font-weight:600;color:var(--sui-text)"><?= htmlspecialchars($ql['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Başvuru Tablosu -->
<?php if ($leads !== []): ?>
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">📋 Son Başvurular</h2>
        <span class="sui-badge purple"><?= number_format($total) ?> toplam</span>
    </div>
    <div class="sui-table-wrap">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>E-posta</th>
                    <th>Rol</th>
                    <th>Dil</th>
                    <th>Para</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($leads, 0, 15) as $lead): ?>
                <tr>
                    <td style="white-space:nowrap"><?= htmlspecialchars(mb_substr((string) $lead['created_at'], 0, 16)) ?></td>
                    <td><b><?= htmlspecialchars((string) $lead['email']) ?></b></td>
                    <td><span class="sui-badge <?= $lead['role']==='supplier' ? 'blue' : 'green' ?>"><?= htmlspecialchars((string) $lead['role']) ?></span></td>
                    <td><?= htmlspecialchars((string) $lead['language']) ?></td>
                    <td><?= htmlspecialchars((string) $lead['currency']) ?></td>
                    <td class="sui-mono" style="color:var(--sui-muted)"><?= htmlspecialchars((string) $lead['ip_address']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php admin_layout_end(); ?>
