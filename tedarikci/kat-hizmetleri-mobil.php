<?php
declare(strict_types=1);
$active_module = 'hotel_mobile';
require_once __DIR__ . '/layout.php';

if (empty($_SESSION['supplier_csrf'])) {
    $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
}
$u = $supplier_user;
$pdo = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['supplier_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = $_POST['action'] ?? 'update_task';

        if ($action === 'update_task') {
            $id = (int) ($_POST['task_id'] ?? 0);
            $status = $_POST['status'] === 'completed' ? 'completed' : 'in_progress';
            $q = $pdo->prepare('SELECT h.physical_room_id FROM housekeeping_tasks h JOIN properties p ON p.id=h.property_id WHERE h.id=? AND p.supplier_id=?');
            $q->execute([$id, $u['supplier_id']]);
            $task = $q->fetch();

            if ($task) {
                $pdo->prepare("UPDATE housekeeping_tasks SET status=?, completed_at=CASE WHEN ?='completed' THEN now() ELSE NULL END WHERE id=?")
                    ->execute([$status, $status, $id]);
                if ($status === 'completed' && $task['physical_room_id']) {
                    $pdo->prepare("UPDATE physical_rooms SET status='inspected' WHERE id=?")->execute([$task['physical_room_id']]);
                }
                $message = 'Görev durumu güncellendi: ' . ($status === 'completed' ? 'Tamamlandı (Oda Satışa Hazır/Kontrolde)' : 'Temizlik Başlatıldı');
            }
        }

        if ($action === 'report_incident') {
            $propId = (int) ($_POST['property_id'] ?? 0);
            $roomNum = trim((string) ($_POST['room_number'] ?? ''));
            $desc = trim((string) ($_POST['incident_desc'] ?? ''));

            if ($propId > 0 && $desc !== '') {
                // Housekeeping task veya arıza kaydı oluştur
                $pdo->prepare("INSERT INTO housekeeping_tasks(property_id, task_type, priority, notes, status) VALUES(?, 'maintenance', 'urgent', ?, 'pending')")
                    ->execute([$propId, "ARIZA/HASAR BİLDİRİMİ (Oda {$roomNum}): {$desc}"]);
                $message = 'Arıza/hasar bildirimi teknik servise ve resepsiyona iletildi.';
            }
        }
    }
}

$q = $pdo->prepare("SELECT h.*, p.id property_id, p.name property_name, pr.room_number 
                    FROM housekeeping_tasks h 
                    JOIN properties p ON p.id=h.property_id 
                    LEFT JOIN physical_rooms pr ON pr.id=h.physical_room_id 
                    WHERE p.supplier_id=? AND h.status NOT IN ('completed', 'inspected') 
                    ORDER BY CASE h.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END, h.created_at DESC");
$q->execute([$u['supplier_id']]);
$tasks = $q->fetchAll();

$q = $pdo->prepare("SELECT id, name FROM properties WHERE supplier_id=? ORDER BY name");
$q->execute([$u['supplier_id']]);
$properties = $q->fetchAll();

supply_start('Mobil Kat Hizmetleri & Görev Panosu', $active_module);
?>
<section class="page-intro">
    <p>Kat personeli ve teknik ekip için sade, hızlı, mobil uyumlu canlı görev ve arıza takip panosu.</p>
</section>

<?php if ($message): ?><p class="save-success">✓ <?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<section class="next-module" style="margin-bottom:20px">
    <h2>🧹 Bekleyen Oda & Temizlik Görevleri (<?= count($tasks) ?>)</h2>
    <?php if (!$tasks): ?>
        <p class="muted">Şu anda bekleyen açık bir temizlik veya bakım görevi bulunmuyor. Tüm odalar hazır!</p>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:14px">
        <?php foreach ($tasks as $t): ?>
            <div style="background:#fff;padding:16px;border-radius:10px;border:1px solid #e1e8e3;border-left:5px solid <?= $t['priority']==='urgent'?'#8e2410':($t['priority']==='high'?'#b26a00':'#13593b') ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <span style="font-weight:700;font-size:16px;color:#10211f">🚪 Oda <?= htmlspecialchars($t['room_number'] ?? 'Genel Alan') ?></span>
                    <span style="background:<?= $t['status']==='in_progress'?'#fff8e1':'#f0f7f3' ?>;color:<?= $t['status']==='in_progress'?'#b26a00':'#13593b' ?>;font-size:11px;font-weight:bold;padding:3px 8px;border-radius:4px">
                        <?= $t['status']==='in_progress' ? '⏳ Temizlik Sürüyor' : 'Bekliyor' ?>
                    </span>
                </div>
                <div style="font-size:13px;color:#555;margin:6px 0">
                    <b><?= htmlspecialchars($t['property_name']) ?></b> · 
                    <span><?= htmlspecialchars($t['task_type']) ?></span>
                </div>
                <?php if ($t['notes']): ?>
                    <p style="font-size:12px;background:#f9fbf9;padding:8px;border-radius:6px;color:#333;margin:8px 0"><?= htmlspecialchars($t['notes']) ?></p>
                <?php endif; ?>

                <form method="post" style="display:flex;gap:8px;margin-top:12px">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
                    <input type="hidden" name="action" value="update_task">
                    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                    <?php if ($t['status'] !== 'in_progress'): ?>
                        <button name="status" value="in_progress" style="flex:1;background:#fff;border:1.5px solid #13593b;color:#13593b;padding:8px;border-radius:6px;font-weight:600;cursor:pointer">▶ Başla</button>
                    <?php endif; ?>
                    <button name="status" value="completed" style="flex:1;background:#13593b;border:none;color:#fff;padding:8px;border-radius:6px;font-weight:600;cursor:pointer">✓ Tamamla (Hazır)</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="next-module" style="background:#fff4f2;border:1px solid #f8c7bf;padding:18px;border-radius:10px">
    <h2 style="color:#8e2410">⚠️ Hızlı Arıza & Hasar Bildirimi</h2>
    <p style="font-size:13px;color:#661a0b;margin-bottom:12px">Oda temizliği sırasında tespit edilen kırık eşya, su kaçağı veya klima arızasını anında teknik ekibe ve resepsiyona bildirin:</p>
    <form method="post" class="supply-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
        <input type="hidden" name="action" value="report_incident">
        <div class="form-row">
            <select name="property_id" required>
                <?php foreach ($properties as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input name="room_number" placeholder="Oda / Ünite No (Örn: 204)" required>
            <input name="incident_desc" placeholder="Arıza / Hasar Detayı (Örn: Klima su akıtıyor, lamba kırık)" required>
        </div>
        <button style="background:#8e2410;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-weight:bold;cursor:pointer">🚨 Arıza Kaydını Fırlat</button>
    </form>
</section>

<?php supply_end(); ?>
