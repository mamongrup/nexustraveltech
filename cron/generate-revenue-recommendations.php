<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/pricing.php';

$pdo = db();
$properties = $pdo->query("SELECT id, name FROM properties WHERE status = 'active'")->fetchAll();
$totalGenerated = 0;
$totalApplied = 0;

foreach ($properties as $prop) {
    $res = run_dynamic_revenue_engine((int) $prop['id'], false);
    $totalGenerated += $res['generated'];
    $totalApplied += $res['applied'];
}

echo json_encode([
    'ok' => true,
    'properties_checked' => count($properties),
    'recommendations_generated' => $totalGenerated,
    'auto_applied' => $totalApplied,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
