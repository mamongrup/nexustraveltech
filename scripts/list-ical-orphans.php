<?php
/**
 * Yetim iCal bağlantılarını listeler: silinmiş ürüne ait ical_connections satırları.
 * 
 * Kullanım:
 *   php scripts/list-ical-orphans.php
 *   php scripts/list-ical-orphans.php --json
 *   php scripts/list-ical-orphans.php --delete   (silmek için onay ister)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$jsonMode = in_array('--json', $argv ?? [], true);
$deleteMode = in_array('--delete', $argv ?? [], true);

$pdo = db();

// Tablo var mı kontrol et
$tblExists = (bool) $pdo->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='ical_connections'")->fetchColumn();
if (!$tblExists) {
    if ($jsonMode) {
        echo json_encode(['ok' => true, 'orphans' => [], 'message' => 'ical_connections tablosu yok']) . PHP_EOL;
    } else {
        echo "ical_connections tablosu bulunamadı.\n";
    }
    exit(0);
}

// Yetim bağlantıları listele
$stmt = $pdo->query(
    "SELECT c.id, c.label, c.status, c.direction, c.last_sync_at, c.created_at,
            p.id AS property_id, p.name AS property_name, p.property_type
     FROM ical_connections c
     LEFT JOIN properties p ON p.id = c.property_id
     WHERE p.id IS NULL
     ORDER BY c.created_at DESC"
);
$orphans = $stmt->fetchAll();

if ($jsonMode) {
    echo json_encode([
        'ok' => true,
        'count' => count($orphans),
        'orphans' => array_map(fn($o) => [
            'id' => (int) $o['id'],
            'label' => (string) $o['label'],
            'status' => (string) $o['status'],
            'direction' => (string) $o['direction'],
            'property_id' => null,
            'property_name' => null,
            'last_sync_at' => $o['last_sync_at'] ? (string) $o['last_sync_at'] : null,
            'created_at' => (string) $o['created_at'],
        ], $orphans),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// Metin çıktısı
if ($orphans === []) {
    echo "✓ Yetim iCal bağlantısı yok.\n";
    exit(0);
}

echo "⚠ " . count($orphans) . " yetim iCal bağlantısı bulundu (silinmiş ürüne ait):\n\n";
echo str_pad('ID', 6) . ' '
    . str_pad('Durum', 10) . ' '
    . str_pad('Yön', 8) . ' '
    . str_pad('Bağlantı adı', 30) . ' '
    . str_pad('Ürün (silindi)', 20) . ' '
    . 'Son senkron' . "\n";
echo str_repeat('-', 110) . "\n";

foreach ($orphans as $o) {
    echo str_pad((string) $o['id'], 6) . ' '
        . str_pad((string) $o['status'], 10) . ' '
        . str_pad((string) $o['direction'], 8) . ' '
        . str_pad(mb_substr((string) $o['label'], 0, 28), 30) . ' '
        . str_pad('— (silindi)', 20) . ' '
        . ($o['last_sync_at'] ? mb_substr((string) $o['last_sync_at'], 0, 16) : 'hiç yok') . "\n";
}

echo "\nToplam: " . count($orphans) . " yetim bağlantı.\n";
echo "Temizlemek için: php scripts/list-ical-orphans.php --delete\n";
echo "Veya: health-check --repair (otomatik temizler)\n";

// Silme modu
if ($deleteMode) {
    echo "\n⚠ Bu işlem " . count($orphans) . " ical_connections satırını ve cascade ile bağlı ical_events/ical_sync_logs satırlarını silecektir.\n";
    echo "Devam etmek için 'EVET' yazın: ";
    $handle = fopen('php://stdin', 'r');
    $confirm = trim((string) fgets($handle));
    fclose($handle);

    if ($confirm !== 'EVET') {
        echo "İptal edildi.\n";
        exit(0);
    }

    $del = $pdo->prepare('DELETE FROM ical_connections WHERE id = ?');
    $deleted = 0;
    foreach ($orphans as $o) {
        $del->execute([(int) $o['id']]);
        $deleted++;
        echo "  ✓ #" . (int) $o['id'] . " " . htmlspecialchars((string) $o['label']) . " silindi\n";
    }
    echo "\nÖzet: $deleted yetim bağlantı silindi (cascade ile bağlı kayıtlar dahil).\n";
}
