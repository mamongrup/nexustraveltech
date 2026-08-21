<?php
declare(strict_types=1);

// NEXUS — sahiplik devri sonrası 'must be owner' hatalarını tarar.
//
//   /opt/plesk/php/8.5/bin/php scripts/check-ownership.php           → konsol raporu
//   /opt/plesk/php/8.5/bin/php scripts/check-ownership.php --json    → makinece okunabilir JSON
//   /opt/plesk/php/8.5/bin/php scripts/check-ownership.php --repair  → sorunlu tabloları otomatik devreder
//
// Kontroller:
//   1) Tablo sahipliği: postgres veya tanımsız kullanıcı sahipli tablo → must be owner riski
//   2) Dizi sahipliği: aynı kontrol sequence'lar için
//   3) Schema sahipliği: public schema doğru kullanıcıda mı
//   4) Migration uygulama testi: küçük bir ALTER尝试 ile must be owner doğrudan test edilir
//   5) App kullanıcısı mevcut mu: pg_roles'de var mı

require_once __DIR__ . '/../config/database.php';

$repair = in_array('--repair', $argv ?? [], true);
$json   = in_array('--json', $argv ?? [], true);

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "Veritabanına bağlanılamadı: " . $e->getMessage() . "\n");
    exit(1);
}

// App kullanıcısını secrets'tan oku
$appUser = 'app';
if (file_exists(__DIR__ . '/../config/secrets.php')) {
    $sc = require __DIR__ . '/../config/secrets.php';
    $appUser = $sc['db_user'] ?? 'app';
}

$currentUser = $pdo->query("SELECT current_user")->fetchColumn();
$sessionUser = $pdo->query("SELECT session_user")->fetchColumn();

$checks = [];
$issues = [];

// ── 1) App kullanıcısı mevcut mu? ──
$userExists = (bool) $pdo->query("SELECT 1 FROM pg_roles WHERE rolname = '" . $appUser . "'")->fetchColumn();
$checks[] = [
    'name' => 'app kullanıcısı mevcut',
    'status' => $userExists ? 'ok' : 'fail',
    'detail' => $userExists ? "$appUser rolü mevcut" : "$appUser rolü bulunamadı — CREATE ROLE gerekli",
];
if (!$userExists) $issues[] = "App kullanıcısı ($appUser) pg_roles'de yok";

// ── 2) Tablo sahipliği ──
$tableOwnerRows = $pdo->query(
    "SELECT tableowner, count(*) cnt FROM pg_tables WHERE schemaname='public' GROUP BY tableowner ORDER BY 2 DESC"
)->fetchAll();

$totalTables = 0;
$wrongOwnerTables = [];
foreach ($tableOwnerRows as $r) {
    $totalTables += (int) $r['cnt'];
    if ($r['tableowner'] !== $appUser) {
        $wrongOwnerTables[] = $r['tableowner'] . ':' . $r['cnt'];
    }
}

$checks[] = [
    'name' => 'tablo sahipliği',
    'status' => $wrongOwnerTables === [] ? 'ok' : 'fail',
    'detail' => $wrongOwnerTables === []
        ? "tümü ($totalTables tablo) $appUser sahipliğinde"
        : "yanlış sahip: " . implode(', ', $wrongOwnerTables) . " — must be owner riski",
];
if ($wrongOwnerTables !== []) {
    $issues[] = "Tablo sahipliği hatalı: " . implode(', ', $wrongOwnerTables);
    // Sorunlu tabloları listele
    $wrongTables = $pdo->query(
        "SELECT tablename, tableowner FROM pg_tables WHERE schemaname='public' AND tableowner <> '" . $appUser . "' ORDER BY tablename"
    )->fetchAll();
    foreach ($wrongTables as $t) {
        $issues[] = "  → {$t['tablename']} (sahip: {$t['tableowner']})";
    }
}

// ── 3) Dizi sahipliği ──
$seqOwnerRows = $pdo->query(
    "SELECT sequenceowner, count(*) cnt FROM pg_sequences WHERE schemaname='public' GROUP BY sequenceowner ORDER BY 2 DESC"
)->fetchAll();

$wrongOwnerSeqs = [];
foreach ($seqOwnerRows as $r) {
    if ($r['sequenceowner'] !== $appUser) {
        $wrongOwnerSeqs[] = $r['sequenceowner'] . ':' . $r['cnt'];
    }
}

$checks[] = [
    'name' => 'dizi (sequence) sahipliği',
    'status' => $wrongOwnerSeqs === [] ? 'ok' : 'warn',
    'detail' => $wrongOwnerSeqs === [] ? 'tümü doğru sahipte' : 'yanlış sahip: ' . implode(', ', $wrongOwnerSeqs),
];
if ($wrongOwnerSeqs !== []) $issues[] = "Dizi sahipliği hatalı: " . implode(', ', $wrongOwnerSeqs);

// ── 4) Schema sahipliği ──
$schemaOwner = $pdo->query("SELECT schema_owner FROM information_schema.schemata WHERE schema_name='public'")->fetchColumn();
$checks[] = [
    'name' => 'public schema sahipliği',
    'status' => $schemaOwner === $appUser ? 'ok' : 'warn',
    'detail' => $schemaOwner === $appUser ? "$appUser sahipliğinde" : "sahip: $schemaOwner — $appUser olmalı",
];
if ($schemaOwner !== $appUser) $issues[] = "public schema sahibi $schemaOwner, $appUser olmalı";

// ── 5) Rol mevcudiyeti ──
$roleGrant = $pdo->query("SELECT has_database_privilege('$appUser', current_database(), 'CONNECT')")->fetchColumn();
$checks[] = [
    'name' => '$appUser CONNECT yetkisi',
    'status' => $roleGrant ? 'ok' : 'fail',
    'detail' => $roleGrant ? "$appUser veritabanına bağlanabilir" : "$appUser CONNECT yetkisi yok",
];
if (!$roleGrant) $issues[] = "$appUser kullanıcısının CONNECT yetkisi yok";

// ── 6) must be owner doğrudan test ──
// Küçük bir tabloda ALTER TABLE ... OWNER TO current_user dene
$testTable = null;
$candidates = ['error_logs', 'blocked_ips', 'login_throttle', 'guest_reviews'];
foreach ($candidates as $c) {
    $exists = $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='$c'")->fetchColumn();
    if ($exists) { $testTable = $c; break; }
}
if ($testTable) {
    // Mevcut sahibi kaydet
    $origOwner = $pdo->query("SELECT tableowner FROM pg_tables WHERE schemaname='public' AND tablename='$testTable'")->fetchColumn();
    if ($origOwner === $appUser) {
        // Zaten app sahipliğinde — postgres'e çevirip geri alarak test et
        try {
            $pdo->exec("ALTER TABLE $testTable OWNER TO postgres");
            $pdo->exec("ALTER TABLE $testTable OWNER TO $appUser");
            $checks[] = [
                'name' => "must be owner test ($testTable)",
                'status' => 'ok',
                'detail' => "ALTER TABLE çalıştırılabilir — sahiplik sorunu yok",
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'name' => "must be owner test ($testTable)",
                'status' => 'fail',
                'detail' => $e->getMessage(),
            ];
            $issues[] = "ALTER TABLE çalıştırılamadı: " . $e->getMessage();
        }
    } else {
        $checks[] = [
            'name' => "must be owner test ($testTable)",
            'status' => 'warn',
            'detail' => "tablo $origOwner sahipliğinde — ALTER testi atlandı",
        ];
    }
} else {
    $checks[] = [
        'name' => 'must be owner test',
        'status' => 'warn',
        'detail' => 'test edilecek tablo bulunamadı',
    ];
}

// ── 7) Migration uygulanabilirlik ──
$lastMigration = $pdo->query("SELECT file FROM schema_migrations ORDER BY id DESC LIMIT 1")->fetchColumn();
$checks[] = [
    'name' => 'son uygulanan migration',
    'status' => 'ok',
    'detail' => $lastMigration ?? 'henüz yok',
];

// ── Çıktı ──
$ok = empty(array_filter($checks, fn($c) => $c['status'] === 'fail'));

if ($json) {
    echo json_encode([
        'ok' => $ok,
        'app_user' => $appUser,
        'current_user' => $currentUser,
        'session_user' => $sessionUser,
        'schema_owner' => $schemaOwner,
        'total_tables' => $totalTables,
        'checks' => $checks,
        'issues' => $issues,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($ok ? 0 : 1);
}

// İnsan okunur çıktı
echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  NEXUS Sahiplik Doğrulama                          ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

echo "App kullanıcısı: $appUser\n";
echo "Bağlantı kullanıcısı: $currentUser (session: $sessionUser)\n";
echo "Toplam tablo: $totalTables\n\n";

foreach ($checks as $c) {
    $icon = $c['status'] === 'ok' ? '✓' : ($c['status'] === 'warn' ? '⚠' : '✗');
    $color = $c['status'] === 'ok' ? '32' : ($c['status'] === 'warn' ? '33' : '31');
    echo "\033[{$color}m$icon\033[0m {$c['name']}";
    if ($c['detail'] !== '') echo " — {$c['detail']}";
    echo "\n";
}

echo "\n";
if ($ok) {
    echo "\033[32m✓ Tüm kontroller başarılı — 'must be owner' hatası beklenmiyor.\033[0m\n";
} else {
    echo "\033[31m✗ Sorunlar bulundu:\033[0m\n";
    foreach ($issues as $issue) {
        echo "  $issue\n";
    }
    echo "\nÇözüm: bash scripts/transfer-ownership.sh\n";
}

// ── Repair modu ──
if ($repair && !$ok) {
    echo "\n=== Otomatik onarım ===\n";
    $phpBin = trim((string) shell_exec('command -v /opt/plesk/php/8.5/bin/php || command -v php || echo php'));
    $script = __DIR__ . '/transfer-ownership.sh';
    if (file_exists($script)) {
        echo "→ bash scripts/transfer-ownership.sh çalıştırılıyor...\n";
        passthru("bash $script", $rc);
        if ($rc === 0) {
            echo "\n✓ Onarım tamamlandı — tekrar doğrulayın: php scripts/check-ownership.php\n";
        } else {
            echo "\n⚠ Onarım kısmen başarısız (çıkış kodu: $rc)\n";
        }
    } else {
        echo "✗ transfer-ownership.sh bulunamadı\n";
    }
}

exit($ok ? 0 : 1);
