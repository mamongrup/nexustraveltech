<?php
declare(strict_types=1);

// Sunucu güncelleme sonrası sağlık kontrolü — tek komut:
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php            → eksik migration'ları idempotent uygular ve raporlar
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --dry-run  → hiçbir şey uygulamaz, yalnızca durumu gösterir
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --repair   → yabancı şemalı BOŞ tabloları (örn. bozuk
//     channel_room_mappings) otomatik düşürüp migration'larla yeniden kurar; DOLU tablolara dokunmaz.
//     Her tablo için ETKİLEŞİMLİ onay ister; otomasyon/cron için --yes (onaysız düşür) ekleyin.
//     Önce --repair --dry-run ile tespiti görün — kuru mod hiçbir şey düşürmez.
//     Ayrıca SAHİPLİK DEVRİ yapar: app kullanıcısı public tabloların sahibi değilse önce
//     sahipliği devreder (mevcut bağlantı süper ise doğrudan, secrets db_admin_user varsa onunla),
//     sonra migration'ları uygular; devir yapılamazsa migration uygulaması atlanır ve tek satır
//     komut gösterilir.
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --fix      → eksik/geçersiz/tekrarlanan kanal tokenlarını
//     otomatik yeniler (64 hex, benzersiz); --dry-run ile yalnızca önizleme
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --orphans  → yetim/uyumsuz eşleştirmeleri sayı yerine
//     satır satır ayrıntılı gösterir (ID + dış kod + sorun türü) — oda/plan/ürün eşleştirmeleri
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --json     → her bölümün sonucunu makinece okunabilir
//     JSON olarak döndürür (checks: tables/columns/room_mappings/tokens/consistency/ownership_precheck/
//     migrations/operational/env — her biri status + detay; ayrıştırıcı/CI için idealdir).
//   /opt/plesk/php/8.5/bin/php scripts/health-check.php --repair --backup-schema --yes
//     → Düşürülecek BOŞ tabloların CANLI şemasını (kolonlar + kısıtlar + indeksler) düşürmeden
//     ÖNCE database/backups/schema-backup-*.sql dosyasına yazar. --dry-run ile birlikte
//     kullanılırsa yedek yazılmaz (hiçbir şey düşürülmez). Yedek yolu çıktıda ve denetimde görünür.
//
// Bölümler: 1) tablolar   2) kritik kolonlar   2b) kanal token doğrulama   3) migration durumu   4) ortam.
// Herhangi bir sorun varsa çıkış kodu 1. Mantık, günlük zamanlayıcı göreviyle
// (cron/health-check-alert.php) paylaşılmak üzere config/health.php'ye taşındı.

require_once __DIR__ . '/../config/health.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$repair = in_array('--repair', $argv ?? [], true);
$fix = in_array('--fix', $argv ?? [], true);
$yes = in_array('--yes', $argv ?? [], true);
$orphans = in_array('--orphans', $argv ?? [], true);
$backupSchema = in_array('--backup-schema', $argv ?? [], true);
$json = in_array('--json', $argv ?? [], true);
$result = health_check_run($dryRun, $repair, $fix, $yes, $orphans, $backupSchema);

if ($json) {
    // Makinece okunabilir mod — insan çıktısı yerine yapılandırılmış JSON döner.
    // Çıkış kodu insan moduyla aynı kalır (sorun varsa 1), böylece CI/cron ayrıştırabilir.
    $payload = [
        'ok' => $result['ok'],
        'ran_at' => $result['ran_at'] ?? gmdate('c'),
        'checks' => $result['checks'] ?? [],
        'errors' => $result['errors'],
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($result['ok'] ? 0 : 1);
}

echo $result['output'];
exit($result['ok'] ? 0 : 1);
