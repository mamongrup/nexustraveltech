<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/product_types.php';
require_once __DIR__ . '/../config/verification_documents.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/notifications.php';

if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

$types = product_types();
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $note = trim((string) ($_POST['review_note'] ?? ''));
        $approvedTypes = array_values(array_intersect((array) ($_POST['approved_product_types'] ?? []), array_keys($types)));
        $verificationQuery = db()->prepare('SELECT identity_check_status FROM supplier_verifications WHERE supplier_id=?');
        $verificationQuery->execute([$supplierId]);
        $verification = $verificationQuery->fetch();
        if ($supplierId < 1 || !in_array($action, ['approve', 'reject', 'verify_identity'], true) || !$verification) {
            $error = 'Geçersiz onay işlemi.';
        } elseif ($action === 'verify_identity') {
            if ($verification['identity_check_status'] === 'verified') {
                $error = 'Kimlik teyidi zaten başarılı olarak işaretlenmiş.';
            } else {
                db()->prepare("UPDATE supplier_verifications SET identity_status='approved', identity_check_status='verified', identity_check_message=NULL, identity_checked_at=now() WHERE supplier_id=?")
                    ->execute([$supplierId]);
                audit_log('supplier.identity_verified', 'supplier', $supplierId);
                notify_supplier_users($supplierId, 'verification.updated', 'Kimlik teyidiniz başarılı olarak işaretlendi.', '/nexustraveltech/tedarikci/hesap-dogrulama');
                $message = 'Kimlik teyidi başarılı olarak işaretlendi; artık yetki onayı yapılabilir.';
            }
        } elseif ($action === 'approve' && $verification['identity_check_status'] !== 'verified') {
            $error = 'Nüfus/KPS kimlik teyidi başarılı olmadan tedarikçi onaylanamaz.';
        } elseif ($action === 'approve' && !$approvedTypes) {
            $error = 'Onay için en az bir ürün türü seçin.';
        } else {
            $approved = $action === 'approve';
            db()->prepare("UPDATE supplier_verifications SET review_status=?, authority_status=?, approved_product_types=?::jsonb, review_note=?, reviewed_by=?, reviewed_at=now() WHERE supplier_id=?")
                ->execute([$approved ? 'approved' : 'rejected', $approved ? 'approved' : 'rejected', json_encode($approved ? $approvedTypes : [], JSON_UNESCAPED_UNICODE), $note ?: null, 'NEXUS yönetici', $supplierId]);
            audit_log($approved ? 'supplier.approved' : 'supplier.rejected', 'supplier', $supplierId, ['product_types' => $approved ? $approvedTypes : [], 'note' => $note]);
            notify_supplier_users($supplierId, 'verification.updated', $approved ? 'Tedarikçi hesabınız ve ürün yetkileriniz onaylandı.' : 'Doğrulama talebiniz reddedildi; gerekçe panelde görüntülenir.', '/nexustraveltech/tedarikci/hesap-dogrulama');
            $message = $approved ? 'Tedarikçi ve seçili ürün yetkileri onaylandı.' : 'Doğrulama talebi reddedildi; tedarikçiye not iletildi.';
        }
    }
}

$rows = db()->query("SELECT v.*, s.company_name, u.full_name, u.email FROM supplier_verifications v JOIN suppliers s ON s.id=v.supplier_id LEFT JOIN supplier_users u ON u.supplier_id=s.id AND u.role='owner' ORDER BY CASE v.review_status WHEN 'pending' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END, v.submitted_at DESC")->fetchAll();

admin_layout_start('İlan & Tedarikçi Onayları', 'tedarikci-onaylari');
?>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">📋 Tedarikçi Kimlik ve Yetki İncelemeleri (<?= count($rows) ?>)</h2>
    </div>

    <?php if (!$rows): ?>
        <p style="color:var(--sui-muted);padding:14px 0">Şu anda onay bekleyen tedarikçi veya yetki başvurusu bulunmuyor.</p>
    <?php endif; ?>

    <div style="display:grid;gap:18px">
        <?php foreach ($rows as $row): 
            $requested = json_decode((string)$row['requested_product_types'], true) ?: [];
            $approved = json_decode((string)$row['approved_product_types'], true) ?: [];
        ?>
            <div style="background:#fff;border:1px solid var(--sui-border);border-radius:var(--sui-radius-sm);padding:20px;box-shadow:var(--sui-shadow-sm)">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <div style="display:flex;gap:8px;align-items:center">
                        <span class="sui-badge <?= $row['review_status'] === 'approved' ? 'sui-badge-success' : ($row['review_status'] === 'rejected' ? 'sui-badge-danger' : 'sui-badge-warning') ?>">
                            <?= htmlspecialchars((string)$row['review_status']) ?>
                        </span>
                        <span class="sui-badge <?= $row['identity_check_status'] === 'verified' ? 'sui-badge-success' : 'sui-badge-warning' ?>">
                            Kimlik: <?= htmlspecialchars((string)$row['identity_check_status']) ?>
                        </span>
                    </div>
                    <?php if ($row['identity_check_status'] !== 'verified'): ?>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                            <input type="hidden" name="supplier_id" value="<?= (int)$row['supplier_id'] ?>">
                            <button class="sui-btn sui-btn-success sui-btn-sm" name="action" value="verify_identity">✓ Kimlik Teyidini Doğrula</button>
                        </form>
                    <?php endif; ?>
                </div>

                <h3 style="margin:0 0 8px 0;font-size:17px"><?= htmlspecialchars((string)$row['company_name']) ?></h3>
                
                <div style="font-size:13px;color:var(--sui-muted);line-height:1.7;margin-bottom:14px">
                    <b>Başvuran:</b> <?= htmlspecialchars($row['full_name'] ?: '—') ?> · <?= htmlspecialchars($row['email'] ?: '—') ?><br>
                    <b>Tür:</b> <?= htmlspecialchars($row['legal_identity_type'] === 'individual' ? 'Gerçek Kişi' : 'Tüzel Kişi / İşletme') ?> · <b>Resmî Ad:</b> <?= htmlspecialchars((string)$row['legal_name']) ?><br>
                    <b>Yetki Beyanı:</b> <?= htmlspecialchars((string)$row['authority_role']) ?>
                    <?= $row['verification_reference'] ? ' · <b>Referans:</b> ' . htmlspecialchars((string)$row['verification_reference']) : '' ?><br>
                    <b>Talep Edilen Kategoriler:</b> <?= htmlspecialchars(implode(', ', array_map(fn($key) => $types[$key]['label'] ?? $key, $requested))) ?>
                </div>

                <form method="post" style="background:var(--sui-bg);border-radius:var(--sui-radius-xs);padding:14px">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                    <input type="hidden" name="supplier_id" value="<?= (int)$row['supplier_id'] ?>">
                    
                    <div style="font-size:12px;font-weight:600;margin-bottom:8px">Onaylanacak Ürün Yetkileri:</div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
                        <?php foreach ($types as $key => $type): ?>
                            <label style="font-size:13px;display:flex;align-items:center;gap:4px">
                                <input type="checkbox" name="approved_product_types[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $approved ?: $requested, true) ? 'checked' : '' ?>> 
                                <?= htmlspecialchars($type['label']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <textarea name="review_note" class="sui-input" rows="2" placeholder="İnceleme notu (tedarikçiye iletilecektir)" style="margin-bottom:12px"><?= htmlspecialchars((string)$row['review_note']) ?></textarea>
                    
                    <div style="display:flex;gap:8px">
                        <button class="sui-btn sui-btn-primary sui-btn-sm" name="action" value="approve">Yetki ve Kategorileri Onayla</button>
                        <button class="sui-btn sui-btn-danger sui-btn-sm" name="action" value="reject">Reddet / Düzeltme İste</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

