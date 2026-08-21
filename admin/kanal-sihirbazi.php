<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/audit.php';
require_admin();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$pdo = db();
$msg = '';
$err = '';

// Otomatik Eşleştirme veya Manuel Eşleştirme POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');
    
    // Manuel Tekil Eşleştirme
    if ($action === 'map_single') {
        $mappingId = (int)($_POST['mapping_id'] ?? 0);
        $targetRoomId = (int)($_POST['target_room_id'] ?? 0);
        $targetPlanId = (int)($_POST['target_plan_id'] ?? 0);

        if ($mappingId > 0 && $targetRoomId > 0) {
            try {
                $up = $pdo->prepare("
                    UPDATE channel_room_mappings 
                    SET room_type_id=?, rate_plan_id=?, status='mapped', suggestion_score=100 
                    WHERE id=?
                ");
                $up->execute([$targetRoomId, $targetPlanId > 0 ? $targetPlanId : null, $mappingId]);
                audit_log('channel_wizard.manual_map', 'channel_room_mappings', $mappingId, [
                    'room_type_id' => $targetRoomId, 'rate_plan_id' => $targetPlanId
                ]);
                $msg = "Eşleştirme başarıyla tamamlandı ve aktifleştirildi.";
            } catch (Throwable $e) {
                $err = "Eşleştirme hatası: " . $e->getMessage();
            }
        }
    }

    // Toplu Akıllı Otomatik Eşleştirme
    if ($action === 'auto_map_all') {
        try {
            $unmapped = $pdo->query("SELECT * FROM channel_room_mappings WHERE status != 'mapped' OR room_type_id IS NULL")->fetchAll();
            $autoMappedCount = 0;

            foreach ($unmapped as $um) {
                $propId = (int)$um['property_id'];
                $extCode = (string)$um['external_room_id'];

                // Tesisin odalarını bul ve en yakın ismi seç
                $rooms = $pdo->prepare("SELECT id, name FROM room_types WHERE property_id=? AND status='active'");
                $rooms->execute([$propId]);
                $allR = $rooms->fetchAll();

                $bestMatch = null;
                $highestSim = 0;

                foreach ($allR as $r) {
                    similar_text(mb_strtolower($extCode, 'UTF-8'), mb_strtolower($r['name'], 'UTF-8'), $sim);
                    if ($sim > $highestSim) {
                        $highestSim = $sim;
                        $bestMatch = $r['id'];
                    }
                }

                if ($bestMatch && $highestSim >= 40) {
                    $up = $pdo->prepare("UPDATE channel_room_mappings SET room_type_id=?, status='mapped', suggestion_score=? WHERE id=?");
                    $up->execute([$bestMatch, (int)round($highestSim), $um['id']]);
                    $autoMappedCount++;
                }
            }
            audit_log('channel_wizard.auto_map_all', 'channel_room_mappings', 0, ['mapped_count' => $autoMappedCount]);
            $msg = "Harika! Toplam {$autoMappedCount} kanal eşleştirmesi akıllı benzerlik analiziyle tamamlandı.";
        } catch (Throwable $e) {
            $err = "Otomatik eşleştirme hatası: " . $e->getMessage();
        }
    }
}

// Eşleştirme Listesini Çek
$mappings = [];
try {
    $mq = $pdo->query("
        SELECT m.*, c.display_name as channel_name, p.name as property_name, rt.name as room_name, rp.name as plan_name
        FROM channel_room_mappings m
        LEFT JOIN channel_connections c ON c.id=m.channel_connection_id
        LEFT JOIN properties p ON p.id=m.property_id
        LEFT JOIN room_types rt ON rt.id=m.room_type_id
        LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id
        ORDER BY m.status ASC, m.id DESC
    ");
    $mappings = $mq->fetchAll();
} catch (Throwable $e) {}

// Odalar ve Planlar Listesi (Seçim kutuları için)
$allProperties = $pdo->query("SELECT id, name FROM properties WHERE status='active' ORDER BY name")->fetchAll();
$roomsByProp = [];
$plansByProp = [];
foreach ($allProperties as $ap) {
    $rq = $pdo->prepare("SELECT id, name FROM room_types WHERE property_id=? AND status='active'");
    $rq->execute([$ap['id']]);
    $roomsByProp[$ap['id']] = $rq->fetchAll();

    $plq = $pdo->prepare("SELECT id, name FROM rate_plans WHERE property_id=? AND status='active'");
    $plq->execute([$ap['id']]);
    $plansByProp[$ap['id']] = $plq->fetchAll();
}

$unmappedCount = count(array_filter($mappings, fn($m) => $m['status'] !== 'mapped'));

require_once __DIR__ . '/layout.php';
admin_layout_start('Akıllı Kanal Eşleştirme Sihirbazı', 'kanal-sihirbazi');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title"><i class="fa-solid fa-wand-magic-sparkles" style="color:var(--sui-primary);margin-right:8px"></i> OTA & iCal Kanal Eşleştirme Sihirbazı</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Booking.com, Airbnb, Expedia ve iCal bağlantılarınızdan gelen harici oda kodlarını platformunuzdaki odalarla akıllı benzerlik motoruyla eşleştirir.
            </p>
        </div>
        <div style="display:flex;gap:10px">
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="auto_map_all">
                <button type="submit" class="sui-btn sui-btn-primary">
                    <i class="fa-solid fa-bolt"></i> Tek Tıkla Otomatik Eşleştir (<?= $unmappedCount ?>)
                </button>
            </form>
        </div>
    </div>

    <?php if (!$mappings): ?>
        <div style="padding:40px;text-align:center;color:var(--sui-muted)">
            <i class="fa-solid fa-link-slash" style="font-size:36px;margin-bottom:10px"></i>
            <p>Şu anda sistemde tanımlı harici kanal eşleştirme kaydı bulunmuyor.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="sui-table">
                <thead>
                    <tr>
                        <th>Kanal & Tesis</th>
                        <th>Harici Oda Kodu</th>
                        <th>Öneri Skoru</th>
                        <th>Mevcut Durum</th>
                        <th>Eşleşen Oda & Fiyat Planı</th>
                        <th style="text-align:right">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mappings as $mp): 
                        $isMapped = $mp['status'] === 'mapped';
                        $pId = (int)$mp['property_id'];
                        $propRooms = $roomsByProp[$pId] ?? [];
                        $propPlans = $plansByProp[$pId] ?? [];
                    ?>
                        <tr>
                            <td>
                                <b><?= htmlspecialchars($mp['channel_name'] ?? 'OTA Kanalı') ?></b>
                                <div style="font-size:11px;color:var(--sui-muted)"><?= htmlspecialchars($mp['property_name'] ?? 'Tesis') ?></div>
                            </td>
                            <td>
                                <span style="font-family:monospace;font-weight:700;background:#f1f5f9;padding:3px 8px;border-radius:6px">
                                    <?= htmlspecialchars((string)$mp['external_room_id']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <div style="width:40px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                                        <div style="width:<?= (int)($mp['suggestion_score'] ?? 0) ?>%;height:100%;background:linear-gradient(90deg,#7928ca,#ff0080)"></div>
                                    </div>
                                    <span style="font-size:11px;font-weight:700;color:var(--sui-primary)">%<?= (int)($mp['suggestion_score'] ?? 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="sui-badge <?= $isMapped ? 'sui-badge-success' : 'sui-badge-warning' ?>">
                                    <?= $isMapped ? 'Eşleşti' : 'Bekliyor' ?>
                                </span>
                            </td>
                            <td>
                                <form method="post" id="form-<?= (int)$mp['id'] ?>" style="display:flex;gap:6px;margin:0">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                    <input type="hidden" name="action" value="map_single">
                                    <input type="hidden" name="mapping_id" value="<?= (int)$mp['id'] ?>">

                                    <select name="target_room_id" class="sui-input" style="font-size:12px;padding:6px 10px">
                                        <option value="">Oda Seçin...</option>
                                        <?php foreach ($propRooms as $pr): ?>
                                            <option value="<?= (int)$pr['id'] ?>" <?= (int)$mp['room_type_id'] === (int)$pr['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($pr['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <select name="target_plan_id" class="sui-input" style="font-size:12px;padding:6px 10px">
                                        <option value="">Plan Seçin...</option>
                                        <?php foreach ($propPlans as $pp): ?>
                                            <option value="<?= (int)$pp['id'] ?>" <?= (int)$mp['rate_plan_id'] === (int)$pp['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($pp['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td style="text-align:right">
                                <button type="submit" form="form-<?= (int)$mp['id'] ?>" class="sui-btn <?= $isMapped ? 'sui-btn-outline' : 'sui-btn-primary' ?> sui-btn-sm">
                                    <i class="fa-solid fa-link"></i> <?= $isMapped ? 'Güncelle' : 'Eşleştir' ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
