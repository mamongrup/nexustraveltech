<?php
declare(strict_types=1);

// 044 sonrası token değeri olmayan (veya geçersiz biçimli) kanal bağlantılarını
// tespit edip otomatik doldurur. Token: 64 hex karakter (endpoint bunu ister).
// pgcrypto gerektirmez — PHP random_bytes ile üretilir.
//
// Kullanım: /opt/plesk/php/8.5/bin/php scripts/ensure-channel-tokens.php

require_once __DIR__ . '/../config/database.php';

$pdo = db();
$rows = $pdo->query('SELECT id, channel_code, display_name, access_token FROM channel_connections ORDER BY id')->fetchAll();

$missing = [];
$already = 0;
foreach ($rows as $r) {
    $tok = trim((string) ($r['access_token'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $tok)) {
        $already++;
    } else {
        $missing[] = $r;
    }
}

if (!$missing) {
    echo 'Tüm kanal bağlantılarında geçerli token dolu (' . count($rows) . " bağlantı).\n";
    exit(0);
}

$up = $pdo->prepare('UPDATE channel_connections SET access_token=? WHERE id=?');
$filled = 0;
foreach ($missing as $m) {
    $newToken = bin2hex(random_bytes(32));
    $up->execute([$newToken, (int) $m['id']]);
    $filled++;
    $state = trim((string) ($m['access_token'] ?? '')) === '' ? 'eksikti' : 'geçersiz biçimliydi';
    echo '#' . $m['id'] . ' ' . $m['display_name'] . ' (' . $m['channel_code'] . ') token ' . $state . ' → atandı: ' . substr($newToken, 0, 8) . "…\n";
}

echo "Özet: {$filled} bağlantıya token atandı, {$already} bağlantıda geçerli token zaten vardı.\n";
