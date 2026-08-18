<?php
/**
 * Birlesik TTL yonetim paneli — kontrol-merkezi.php icin dahil edilir.
 * TTL tabanli tum kaynaklari tek panelde goruntuler.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/platform_settings.php';

$ttlPanelItems = [];
try {
    $pdo = db();

    // 1) Feature trash
    $trashTtl = max(7, (int) platform_setting('feature_trash_ttl_days', 30));
    $trashCount = (int) $pdo->query("SELECT COUNT(*) FROM property_feature_catalog WHERE deleted_at IS NOT NULL")->fetchColumn();
    $trashWarnCount = (int) $pdo->query("SELECT COUNT(*) FROM property_feature_catalog WHERE deleted_at IS NOT NULL AND ((purge_at IS NOT NULL AND purge_at <= now()) OR (purge_at IS NULL AND deleted_at < now() - interval '{$trashTtl} days'))")->fetchColumn();
    $ttlPanelItems[] = [
        'icon' => '🗑', 'label' => 'Özellik çöp kutusu',
        'setting' => 'feature_trash_ttl_days', 'current' => $trashTtl, 'unit' => 'gün',
        'count' => $trashCount,
        'warn' => $trashWarnCount > 0 ? "{$trashWarnCount} vadesi dolmuş" : '',
        'link' => '/nexustraveltech/admin/ozellik-listeleri#trash',
    ];

    // 2) Pending trash purges
    $pendingPurge = (int) $pdo->query("SELECT COUNT(*) FROM pending_trash_purges WHERE approved_at IS NULL AND expires_at > now()")->fetchColumn();
    $ttlPanelItems[] = [
        'icon' => '⏳', 'label' => 'Bekleyen silme onayı',
        'setting' => null, 'current' => null, 'unit' => '',
        'count' => $pendingPurge,
        'warn' => $pendingPurge > 0 ? "{$pendingPurge} onay bekliyor" : '',
        'link' => '/nexustraveltech/admin/ozellik-listeleri#trash',
    ];

    // 3) Suggestion TTL
    $sugTtl = max(7, (int) platform_setting('channel_suggestion_ttl_days', 30));
    $sugCount = 0;
    try { $sugCount += (int) $pdo->query("SELECT COUNT(*) FROM channel_room_mappings WHERE status='suggested'")->fetchColumn(); } catch (Throwable $e) {}
    try { $sugCount += (int) $pdo->query("SELECT COUNT(*) FROM channel_rate_plan_mappings WHERE status='suggested'")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '🛏', 'label' => 'Eşleştirme önerisi TTL',
        'setting' => 'channel_suggestion_ttl_days', 'current' => $sugTtl, 'unit' => 'gün',
        'count' => $sugCount,
        'warn' => $sugCount > 0 ? "{$sugCount} onay bekliyor" : '',
        'link' => '/nexustraveltech/tedarikci/dagitim-merkezi#sec-room-map',
    ];

    // 4) Payment links
    $payExpired = 0;
    try { $payExpired = (int) $pdo->query("SELECT COUNT(*) FROM payment_links WHERE status='pending' AND expires_at < now()")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '💶', 'label' => 'Ödeme bağlantıları',
        'setting' => null, 'current' => 30, 'unit' => 'gün (sabit)',
        'count' => $payExpired,
        'warn' => $payExpired > 0 ? "{$payExpired} süresi dolmuş" : '',
        'link' => null,
    ];

    // 5) Email outbox (sent older than 7 days)
    $emailOld = 0;
    try { $emailOld = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status IN ('sent','failed') AND created_at < now() - interval '7 days'")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '📧', 'label' => 'E-posta kuyruğu (eski)',
        'setting' => null, 'current' => 7, 'unit' => 'gün (sabit)',
        'count' => $emailOld,
        'warn' => $emailOld > 100 ? "⚠ {$emailOld} eski kayıt" : '',
        'link' => null,
    ];

    // 6) Error logs (older than 30 days)
    $errOld = 0;
    try { $errOld = (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE created_at < now() - interval '30 days'")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '⚠️', 'label' => 'Hata logları (eski)',
        'setting' => null, 'current' => 30, 'unit' => 'gün (sabit)',
        'count' => $errOld,
        'warn' => $errOld > 500 ? "⚠ {$errOld} eski kayıt" : '',
        'link' => '/nexustraveltech/admin/hata-izleme',
    ];

    // 7) Channel sync logs (older than 30 days)
    $syncOld = 0;
    try { $syncOld = (int) $pdo->query("SELECT COUNT(*) FROM channel_sync_logs WHERE created_at < now() - interval '30 days'")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '🔄', 'label' => 'Kanal senkron logları',
        'setting' => null, 'current' => 30, 'unit' => 'gün (sabit)',
        'count' => $syncOld,
        'warn' => $syncOld > 1000 ? "⚠ {$syncOld} eski kayıt" : '',
        'link' => null,
    ];

    // 8) iCal sync logs (older than 30 days)
    $icalOld = 0;
    try { $icalOld = (int) $pdo->query("SELECT COUNT(*) FROM ical_sync_logs WHERE created_at < now() - interval '30 days'")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '📅', 'label' => 'iCal senkron logları',
        'setting' => null, 'current' => 30, 'unit' => 'gün (sabit)',
        'count' => $icalOld,
        'warn' => $icalOld > 500 ? "⚠ {$icalOld} eski kayıt" : '',
        'link' => null,
    ];

    // 9) Scheduled job runs (older than 90 days)
    $runOld = 0;
    try { $runOld = (int) $pdo->query("SELECT COUNT(*) FROM scheduled_job_runs WHERE started_at < now() - interval '90 days'")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '⏱', 'label' => 'Görev çalışma geçmişi',
        'setting' => null, 'current' => 90, 'unit' => 'gün (sabit)',
        'count' => $runOld,
        'warn' => $runOld > 0 ? "{$runOld} eski kayıt" : '',
        'link' => '/nexustraveltech/admin/timerlar',
    ];

    // 10) Login throttle
    $throttleOld = 0;
    try { $throttleOld = (int) $pdo->query("SELECT COUNT(*) FROM login_throttle WHERE window_start < now() - interval '1 hour'")->fetchColumn(); } catch (Throwable $e) {}
    $ttlPanelItems[] = [
        'icon' => '🔒', 'label' => 'Giriş deneme kilidi',
        'setting' => null, 'current' => null, 'unit' => '15 dk pencere',
        'count' => $throttleOld,
        'warn' => $throttleOld > 100 ? "⚠ {$throttleOld} eski kayıt" : '',
        'link' => null,
    ];

} catch (Throwable $e) {}
?>
<section class="card" style="border-color:#3a6ea5;background:#f8f9fc">
<h2>🕐 TTL yönetimi — tüm zaman aşımlı kaynaklar</h2>
<p class="muted" style="margin:0 0 12px">Silme, temizleme ve süresi dolan kaynakların tek kartlık özeti. Ayarlanabilir değerler kontrol merkezinden değiştirilir; sabit değerler kodda tanımlıdır.</p>
<div style="display:grid;gap:8px">
<?php foreach ($ttlPanelItems as $ti): ?>
<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid <?= ($ti['count'] > 0 && ($ti['warn'] ?? '') !== '') ? '#f3c4ba' : '#e1e5de' ?>;border-radius:8px;background:<?= ($ti['count'] > 0 && ($ti['warn'] ?? '') !== '') ? '#fff7f5' : '#fff' ?>">
<span style="font-size:20px;flex-shrink:0"><?= $ti['icon'] ?></span>
<div style="flex:1;min-width:0">
<div style="font-size:13px;font-weight:bold;color:#10211f"><?= htmlspecialchars($ti['label']) ?></div>
<div style="font-size:12px;color:#6b7774;margin-top:2px">
<?php if ($ti['current'] !== null): ?>Süre: <b><?= (int)$ti['current'] ?> <?= htmlspecialchars($ti['unit']) ?></b><?php endif; ?>
<?php if ($ti['setting']): ?> · Ayarlanabilir (kontrol merkezinden)<?php endif; ?>
</div>
<?php if (($ti['warn'] ?? '') !== ''): ?><div style="font-size:12px;color:<?= $ti['count'] > 50 ? '#b0301a' : '#8a6100' ?>;margin-top:2px;font-weight:bold"><?= htmlspecialchars($ti['warn']) ?></div><?php endif; ?>
</div>
<div style="text-align:right;flex-shrink:0">
<div style="font-size:18px;font-weight:bold;color:<?= $ti['count'] > 0 ? '#b0301a' : '#2e7d32' ?>"><?= (int)$ti['count'] ?></div>
<div style="font-size:10px;color:#64716d">kayıt</div>
</div>
<?php if ($ti['link']): ?>
<a href="<?= $ti['link'] ?>" style="font-size:12px;color:#3a6ea5;font-weight:bold;text-decoration:none;white-space:nowrap" title="İlgili sayfaya git">Git →</a>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<p style="margin:12px 0 0;font-size:12px;color:#64716d">📌 Sabit TTL'ler kodda tanımlıdır (geriye dönük temizlik gerektirir). Ayarlanabilir değerler: çöp kutusu TTL, yaklaşan silme uyarı penceresi, öneri TTL — <a href="/nexustraveltech/admin/kontrol-merkezi" style="color:#3a6ea5">kontrol merkezinden</a> değiştirilebilir.</p>
</section>
