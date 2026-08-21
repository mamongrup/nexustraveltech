<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/audit.php';

require_admin();
$q = trim((string) ($_GET['q'] ?? ''));
$guestId = (int) ($_GET['guest'] ?? 0);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        $err = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['guest_id'] ?? 0);
        if ($action === 'export' && $id > 0) {
            $pdo = db();
            $gq = $pdo->prepare('SELECT * FROM guest_profiles WHERE id=?');
            $gq->execute([$id]);
            $guest = $gq->fetch();
            if (!$guest) { $err = 'Misafir bulunamadı.'; }
            else {
                $data = ['guest_profile' => $guest];
                $q1 = $pdo->prepare('SELECT * FROM guest_loyalty_accounts WHERE guest_id=?');
                $q1->execute([$id]);
                $data['loyalty_account'] = $q1->fetchAll();
                $q2 = $pdo->prepare('SELECT bg.booking_id,b.* FROM booking_guests bg JOIN supplier_bookings b ON b.id=bg.booking_id WHERE bg.guest_id=?');
                $q2->execute([$id]);
                $data['bookings'] = $q2->fetchAll();
                $q3 = $pdo->prepare('SELECT * FROM guest_communications WHERE guest_id=?');
                $q3->execute([$id]);
                $data['communications'] = $q3->fetchAll();
                $q4 = $pdo->prepare('SELECT * FROM guest_document_records WHERE guest_id=?');
                $q4->execute([$id]);
                $data['documents'] = $q4->fetchAll();
                $q5 = $pdo->prepare('SELECT r.* FROM guest_reviews r JOIN booking_guests bg ON bg.booking_id=r.booking_id WHERE bg.guest_id=?');
                $q5->execute([$id]);
                $data['reviews'] = $q5->fetchAll();
                $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                audit_log('kvkk.export', 'guest', $id, ['q' => $q]);
                header('Content-Type: application/json; charset=UTF-8');
                header('Content-Disposition: attachment; filename="kvkk-misafir-' . $id . '.json"');
                echo $payload;
                exit;
            }
        }
        if ($action === 'anonymize' && $id > 0) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE guest_profiles SET first_name='[Gizli]',last_name='[Gizli]',email=NULL,phone=NULL,nationality=NULL,birth_date=NULL,identity_type=NULL,identity_number=NULL,passport_country=NULL,vip_level=NULL,marketing_consent=false,preferences='{}'::jsonb WHERE id=?")
                ->execute([$id]);
            $pdo->prepare('DELETE FROM guest_loyalty_accounts WHERE guest_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM guest_communications WHERE guest_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM guest_document_records WHERE guest_id=?')->execute([$id]);
            $pdo->commit();
            audit_log('kvkk.anonymize', 'guest', $id);
            $msg = 'Misafir verisi anonimleştirildi: kimlik alanları silindi, sadakat ve iletişim kayıtları kaldırıldı. Rezervasyon geçmişi yasal saklama nedeniyle korundu.';
        }
    }
}

$matches = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $mq = db()->prepare("SELECT g.*,s.company_name supplier_name FROM guest_profiles g LEFT JOIN suppliers s ON s.id=g.supplier_id WHERE g.email ILIKE ? OR g.phone ILIKE ? OR g.first_name ILIKE ? OR g.last_name ILIKE ? ORDER BY g.id DESC LIMIT 30");
    $mq->execute([$like, $like, $like, $like]);
    $matches = $mq->fetchAll();
}
$detail = null;
if ($guestId > 0) {
    $gq = db()->prepare('SELECT * FROM guest_profiles WHERE id=?');
    $gq->execute([$guestId]);
    $detail = $gq->fetch() ?: null;
}
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('KVKK & Veri Gizliliği Yönetimi', 'kvkk');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">🔍 KVKK Misafir Verisi Sorgulama</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Misafir başına veri dışa aktarma (taşınabilirlik) ve unutulma hakkı (anonimleştirme) işlemleri.
            </p>
        </div>
    </div>
    <form method="get" style="display:flex;gap:8px;max-width:500px">
        <input name="q" value="<?= htmlspecialchars($q) ?>" class="sui-input" placeholder="E-posta, telefon veya ad soyad..." required>
        <button class="sui-btn sui-btn-primary">Ara</button>
    </form>

    <?php if ($q !== ''): ?>
        <div style="margin-top:20px;border-top:1px solid var(--sui-border);padding-top:16px">
            <h3 style="font-size:14px;margin:0 0 12px 0;color:var(--sui-text)"><?= count($matches) ?> Arama Sonucu</h3>
            <div style="display:grid;gap:8px">
                <?php foreach ($matches as $g): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--sui-bg);border-radius:6px">
                        <div>
                            <b><?= htmlspecialchars((string) $g['first_name'] . ' ' . (string) $g['last_name']) ?></b>
                            <span style="font-size:12px;color:var(--sui-muted);margin-left:8px">
                                <?= htmlspecialchars((string) ($g['email'] ?? '—')) ?> · <?= htmlspecialchars((string) ($g['phone'] ?? '—')) ?>
                            </span>
                            <span style="font-size:11px;color:var(--sui-primary);margin-left:8px">
                                [<?= htmlspecialchars((string) ($g['supplier_name'] ?? 'Genel')) ?>]
                            </span>
                        </div>
                        <a href="kvkk?guest=<?= (int) $g['id'] ?>&q=<?= urlencode($q) ?>" class="sui-btn sui-btn-outline sui-btn-sm">
                            İncele / İşlem Yap →
                        </a>
                    </div>
                <?php endforeach; ?>
                <?php if (!$matches): ?>
                    <div style="color:var(--sui-muted);font-size:13px">Eşleşen misafir kaydı bulunamadı.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($detail): ?>
    <div class="sui-card" style="border-left:4px solid var(--sui-primary)">
        <div class="sui-card-header">
            <div>
                <h2 class="sui-card-title">👤 Misafir Profil #<?= (int) $detail['id'] ?> — <?= htmlspecialchars((string) $detail['first_name'] . ' ' . (string) $detail['last_name']) ?></h2>
                <div style="font-size:12px;color:var(--sui-muted);margin-top:4px">
                    E-posta: <b><?= htmlspecialchars((string) ($detail['email'] ?? '—')) ?></b> · 
                    Telefon: <b><?= htmlspecialchars((string) ($detail['phone'] ?? '—')) ?></b> · 
                    TC/Pasaport: <b><?= htmlspecialchars((string) ($detail['identity_number'] ?? '—')) ?></b> · 
                    Pazarlama İzni: <span class="sui-badge <?= $detail['marketing_consent'] ? 'sui-badge-success' : 'sui-badge-warning' ?>"><?= $detail['marketing_consent'] ? 'Mevcut' : 'Yok' ?></span>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap">
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="export">
                <input type="hidden" name="guest_id" value="<?= (int) $detail['id'] ?>">
                <button class="sui-btn sui-btn-primary">📥 JSON Dışa Aktar (KVKK Taşınabilirlik)</button>
            </form>
            <form method="post" style="margin:0" onsubmit="return confirm('Bu işlem GERİ ALINAMAZ: Tüm kişisel kimlik ve iletişim bilgileri maskelenecektir. Devam edilsin mi?');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="action" value="anonymize">
                <input type="hidden" name="guest_id" value="<?= (int) $detail['id'] ?>">
                <button class="sui-btn sui-btn-danger">🛡️ Anonimleştir (Unutulma Hakkı)</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

