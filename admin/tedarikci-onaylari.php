<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/product_types.php';
require __DIR__ . '/../config/verification_documents.php';
require __DIR__ . '/../config/audit.php';
require __DIR__ . '/../config/notifications.php';

require_admin();
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

$rows = db()->query('SELECT v.*, s.company_name, u.full_name, u.email FROM supplier_verifications v JOIN suppliers s ON s.id=v.supplier_id LEFT JOIN supplier_users u ON u.supplier_id=s.id AND u.role=\'owner\' ORDER BY CASE v.review_status WHEN \'pending\' THEN 1 WHEN \'rejected\' THEN 2 ELSE 3 END, v.submitted_at DESC')->fetchAll();
$documentListQuery = db()->prepare('SELECT id, document_type FROM supplier_verification_documents WHERE supplier_id=? ORDER BY document_type');
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tedarikçi onayları | NEXUS Admin</title><style>body{margin:0;background:#f7f7f2;color:#10211f;font-family:Arial,sans-serif}.wrap{width:min(1100px,calc(100% - 32px));margin:40px auto}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:28px;font-weight:800}.brand span{color:#e85f42}.back{color:#10211f}.notice,.error{padding:11px}.notice{background:#e6f8c7}.error{background:#ffe2de}.card{background:#fff;border:1px solid #e1e5de;padding:22px;margin-top:18px}.card h2{margin:0 0 5px;font-size:20px}.meta{color:#64716d;font-size:13px;line-height:1.55}.status{font-size:12px;font-weight:bold;text-transform:uppercase;color:#a86026}.form{display:grid;gap:12px;margin-top:15px}.check-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.check-grid label{border:1px solid #e1e5de;padding:8px;font-size:12px}.form textarea{padding:10px;border:1px solid #d8ded8;font:inherit;resize:vertical}.actions{display:flex;gap:10px}.approve,.reject{border:0;padding:10px 13px;font-weight:700;cursor:pointer}.approve{background:#10211f;color:#fff}.reject{background:#ffe3dd;color:#8e2410}@media(max-width:700px){.check-grid{grid-template-columns:repeat(2,1fr)}}</style></head><body><main class="wrap"><div class="top"><div><div class="brand">N<span>∿</span>XUS Admin</div><p>Tedarikçi kimlik ve ürün yetkisi incelemeleri</p></div><a class="back" href="/nexustraveltech/admin/">← Panele dön</a></div><?php if($message):?><p class="notice"><?=htmlspecialchars($message)?></p><?php endif;?><?php if($error):?><p class="error"><?=htmlspecialchars($error)?></p><?php endif;?><?php if(!$rows):?><section class="card">İncelenecek doğrulama talebi yok.</section><?php endif;?><?php foreach($rows as $row):$requested=json_decode((string)$row['requested_product_types'],true)?:[];$approved=json_decode((string)$row['approved_product_types'],true)?:[];?><section class="card"><div class="status"><?=htmlspecialchars($row['review_status'])?> · Kimlik kontrolü: <?=htmlspecialchars($row['identity_check_status'])?></div><?php if ($row['identity_check_status'] !== 'verified'): ?><form method="post" class="actions"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="supplier_id" value="<?= (int)$row['supplier_id']?>"><button class="approve" name="action" value="verify_identity">Kimlik teyidini başarılı olarak işaretle</button></form><?php endif; ?><h2><?=htmlspecialchars($row['company_name'])?></h2><p class="meta"><b>Başvuran:</b> <?=htmlspecialchars($row['full_name']?:'—')?> · <?=htmlspecialchars($row['email']?:'—')?><br><b>Tür:</b> <?=htmlspecialchars($row['legal_identity_type']==='individual'?'Gerçek kişi':'Tüzel kişi / işletme')?> · <b>Resmî ad:</b> <?=htmlspecialchars($row['legal_name'])?><br><b>Yetki beyanı:</b> <?=htmlspecialchars($row['authority_role'])?><?= $row['verification_reference']?' · <b>Referans:</b> '.htmlspecialchars($row['verification_reference']):'' ?><br><b>Talep edilen kategoriler:</b> <?=htmlspecialchars(implode(', ',array_map(fn($key)=>$types[$key]['label']??$key,$requested)))?><?= $row['identity_check_message']?'<br><b>Kimlik kontrol notu:</b> '.htmlspecialchars($row['identity_check_message']):''?><?= $row['request_note']?'<br><b>Not:</b> '.nl2br(htmlspecialchars($row['request_note'])):''?></p><form method="post" class="form"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="supplier_id" value="<?= (int)$row['supplier_id']?>"><div class="check-grid"><?php foreach($types as $key=>$type):?><label><input type="checkbox" name="approved_product_types[]" value="<?=htmlspecialchars($key)?>" <?=in_array($key,$approved?:$requested,true)?'checked':''?>> <?=htmlspecialchars($type['label'])?></label><?php endforeach;?></div><textarea name="review_note" rows="2" placeholder="İnceleme notu (tedarikçiye görünür)"><?=htmlspecialchars((string)$row['review_note'])?></textarea><div class="actions"><button class="approve" name="action" value="approve">Yetki ve kategorileri onayla</button><button class="reject" name="action" value="reject">Reddet / düzeltme iste</button></div></form></section><?php endforeach;?></main></body></html>
