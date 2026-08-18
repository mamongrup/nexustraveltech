<?php
/**
 * Tablo bekçisi — cron görevleri çalışmadan önce gerekli tabloların varlığını
 * doğrular. Migration eksikse sessizce atlanır (cron durmaz) ama log'a yazılır.
 *
 * Kullanım:
 *   require_once __DIR__ . '/../config/table-guard.php';
 *   table_guard(['pending_trash_purges', 'feature_delete_backups']);
 *   // Buraya kadar gelindiyse tablolar mevcut veya atlandı.
 *
 *   // Tek tablo kontrolü (inline):
 *   if (!table_exists('pending_trash_purges')) { warn('Tablo eksik'); return; }
 *
 *   // Eksik tabloları toplu raporla (health-check için):
 *   $missing = table_guard_report(['tablo1', 'tablo2']);
 */

declare(strict_types=1);

/**
 * Verilen tabloların public şemada var olup olmadığını kontrol eder.
 * Eksik tabloları error_log'a yazar ve false döner; hepsi varsa true döner.
 *
 * @param string[] $tables  Kontrol edilecek tablo adları
 * @param bool     $fatal   false ise eksik tabloları atla (cron devam etsin)
 * @return bool             Tüm tablolar mevcutsa true
 */
function table_guard(array $tables, bool $fatal = false): bool
{
    $missing = table_guard_report($tables);
    if (empty($missing)) return true;

    $list = implode(', ', $missing);
    $msg = "[table-guard] Eksik tablolar: {$list} — migration uygulanmamış olabilir.";

    if ($fatal) {
        error_log("[table-guard] FATAL: {$msg}");
        throw new RuntimeException($msg);
    }

    error_log("[table-guard] WARN: {$msg}");
    return false;
}

/**
 * Verilen tabloların varlık durumunu döndürür.
 * Kullanılmayan tabloları atlar, eksik olanları listedir.
 *
 * @param string[] $tables
 * @return string[]  Eksik tablo adları (boş dizi = hepsi mevcut)
 */
function table_guard_report(array $tables): array
{
    if (empty($tables)) return [];

    try {
        $pdo = db();
        $missing = [];
        foreach ($tables as $tbl) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
            $q->execute([$tbl]);
            if ((int) $q->fetchColumn() === 0) {
                $missing[] = $tbl;
            }
        }
        return $missing;
    } catch (Throwable $e) {
        error_log("[table-guard] Sorgu hatası: " . $e->getMessage());
        return $tables; // Hata durumunda hepsini eksik say
    }
}

/**
 * Tek tablo varlık kontrolü (inline kullanım için).
 */
function table_exists(string $table): bool
{
    try {
        $q = db()->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
        $q->execute([$table]);
        return (bool) $q->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Eksik tabloları onarım modunda raporlar — health-check --repair için
 * hangi tabloların düşürülüp yeniden kurulması gerektiğini söyler.
 *
 * @param string[] $tables       Kontrol edilecek tablolar
 * @param string[] $migrationDir Migration dizini
 * @return array{missing: string[], migrations: array<string, string>}
 */
function table_guard_repair_report(array $tables, string $migrationDir = ''): array
{
    $missing = table_guard_report($tables);
    if (empty($missing)) return ['missing' => [], 'migrations' => []];

    if ($migrationDir === '') {
        $migrationDir = dirname(__DIR__) . '/database/migrations';
    }

    $migrations = [];
    if (is_dir($migrationDir)) {
        foreach ($missing as $tbl) {
            // Tabloyu içeren migration dosyasını bul (CREATE TABLE ... $tbl)
            $files = glob($migrationDir . '/*.sql');
            foreach ($files as $f) {
                $content = file_get_contents($f);
                if (stripos($content, "CREATE TABLE") !== false && stripos($content, $tbl) !== false) {
                    $migrations[$tbl] = basename($f);
                    break;
                }
            }
        }
    }

    return ['missing' => $missing, 'migrations' => $migrations];
}
