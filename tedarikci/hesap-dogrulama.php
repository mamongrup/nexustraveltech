<?php

declare(strict_types=1);

$active_module = 'verification';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/product_types.php';
require_once __DIR__ . '/../config/hotel_form.php';
require_once __DIR__ . '/../config/supplier_verification.php';
require_once __DIR__ . '/../config/verification_documents.php';

$user = $supplier_user;
$types = product_types();
$current = supplier_verification((int) $user['supplier_id']);
$hotelDocuments = hotel_verification_documents();
$documentQuery = db()->prepare('SELECT document_type FROM supplier_verification_documents WHERE supplier_id=?');
$documentQuery->execute([(int) $user['supplier_id']]);
$existingDocuments = array_column($documentQuery->fetchAll(), 'document_type');
$error = '';
$notice = isset($_GET['submitted']) ? 'Doğrulama talebiniz yönetici incelemesine gönderildi.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Güvenlik doğrulaması yenilendi. Lütfen tekrar deneyin.';
    } else {
        $identityType = (string) ($_POST['legal_identity_type'] ?? 'company');
        $legalName = trim((string) ($_POST['legal_name'] ?? ''));
        $authorityRole = (string) ($_POST['authority_role'] ?? '');
        $reference = trim((string) ($_POST['verification_reference'] ?? ''));
        $hotelCertificateNumber = trim((string) ($_POST['hotel_certificate_number'] ?? ''));
        $note = trim((string) ($_POST['request_note'] ?? ''));
        $requested = array_values(array_intersect((array) ($_POST['product_types'] ?? []), array_keys($types)));

        $hotelRequested = in_array('hotel', $requested, true);
        if (!in_array($identityType, ['individual', 'company'], true) || $legalName === '' || !in_array($authorityRole, ['owner', 'authorized_representative', 'contracted_supplier'], true) || !$requested) {
            $error = 'Kimlik/ünvan, yetki şekli ve en az bir ürün türü seçin.';
        } elseif ($hotelRequested && $hotelCertificateNumber === '') {
            $error = 'Otel tedarikçisi için otel belge numarası zorunludur.';
        } elseif ($hotelRequested) {
            foreach ($hotelDocuments as $key => $label) {
                $fileError = $_FILES['documents']['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                if (!in_array($key, $existingDocuments, true) && $fileError !== UPLOAD_ERR_OK) {
                    $error = $label . ' yüklenmelidir.';
                    break;
                }
            }
        }
        if ($error === '') {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO supplier_verifications (supplier_id, legal_identity_type, legal_name, authority_role, verification_reference, hotel_certificate_number, request_note, requested_product_types, approved_product_types, review_status, identity_status, identity_check_status, identity_check_message, identity_checked_at, authority_status, review_note, submitted_at, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, '[]'::jsonb, 'pending', 'pending', 'pending', NULL, NULL, 'pending', NULL, now(), NULL) ON CONFLICT (supplier_id) DO UPDATE SET legal_identity_type=EXCLUDED.legal_identity_type, legal_name=EXCLUDED.legal_name, authority_role=EXCLUDED.authority_role, verification_reference=EXCLUDED.verification_reference, hotel_certificate_number=EXCLUDED.hotel_certificate_number, request_note=EXCLUDED.request_note, requested_product_types=EXCLUDED.requested_product_types, approved_product_types='[]'::jsonb, review_status='pending', identity_status='pending', identity_check_status='pending', identity_check_message=NULL, identity_checked_at=NULL, authority_status='pending', review_note=NULL, submitted_at=now(), reviewed_at=NULL")
                    ->execute([(int) $user['supplier_id'], $identityType, $legalName, $authorityRole, $reference ?: null, $hotelCertificateNumber ?: null, $note ?: null, json_encode($requested, JSON_UNESCAPED_UNICODE)]);
                if ($hotelRequested) {
                    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
                    $directory = verification_document_directory((int) $user['supplier_id']);
                    if (!is_dir($directory)) mkdir($directory, 0700, true);
                    foreach ($hotelDocuments as $key => $label) {
                        if (($_FILES['documents']['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                        $tmp = $_FILES['documents']['tmp_name'][$key]; $size = (int) $_FILES['documents']['size'][$key];
                        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                        if ($size < 1 || $size > 10 * 1024 * 1024 || !isset($allowed[$mime])) throw new RuntimeException($label . ' PDF, JPG veya PNG biçiminde ve en fazla 10 MB olmalıdır.');
                        $stored = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
                        if (!move_uploaded_file($tmp, $directory . '/' . $stored)) throw new RuntimeException($label . ' güvenli depoya taşınamadı.');
                        $pdo->prepare('INSERT INTO supplier_verification_documents (supplier_id,document_type,file_name,stored_name,mime_type,file_size) VALUES (?,?,?,?,?,?) ON CONFLICT (supplier_id,document_type) DO UPDATE SET file_name=EXCLUDED.file_name,stored_name=EXCLUDED.stored_name,mime_type=EXCLUDED.mime_type,file_size=EXCLUDED.file_size,uploaded_at=now()')->execute([(int) $user['supplier_id'], $key, basename((string) $_FILES['documents']['name'][$key]), $stored, $mime, $size]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Belgeler kaydedilemedi: ' . $exception->getMessage();
            }
            if ($error === '') { header('Location: /nexustraveltech/tedarikci/hesap-dogrulama?submitted=1'); exit; }
        }
    }
}

$current = supplier_verification((int) $user['supplier_id']);
$requested = $current ? (json_decode((string) $current['requested_product_types'], true) ?: []) : [];
$approved = supplier_allowed_product_types((int) $user['supplier_id']);
$statusLabel = !$current ? 'Talep oluşturulmadı' : ['pending' => 'İnceleme bekliyor', 'approved' => 'Onaylandı', 'rejected' => 'Düzeltme bekliyor'][$current['review_status']];
supply_start('Hesap ve yetki doğrulama', $active_module); ?>
<section class="verification-page"><article class="verification-status"><span class="eyeline">TEDARİKÇİ GÜVENLİK KONTROLÜ</span><h2><?= htmlspecialchars($statusLabel) ?></h2><p>İlan oluşturabilmeniz için gerçek kişi/işletme doğrulaması ile seçtiğiniz ürün türlerini tedarik etme yetkiniz NEXUS yöneticisi tarafından kontrol edilir.</p><?php if ($current && $current['review_note']): ?><p class="review-note"><b>Yönetici notu:</b> <?= htmlspecialchars($current['review_note']) ?></p><?php endif; ?><?php if ($approved): ?><p class="save-success">✓ İlan ekleme yetkiniz: <?= htmlspecialchars(implode(', ', array_map(fn($type) => $types[$type]['label'], $approved))) ?></p><?php endif; ?></article>
<?php if ($notice): ?><p class="save-success">✓ <?= htmlspecialchars($notice) ?></p><?php endif; ?><?php if ($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="supply-form verification-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><h2>Doğrulama talebi</h2><p>Otel seçildiğinde aşağıdaki resmî belgeler zorunludur. Belgeler herkese açık değildir; yalnızca yetkili yöneticiler inceleyebilir.</p><div class="form-row"><label>Başvuru türü<select name="legal_identity_type"><option value="company" <?= ($current['legal_identity_type'] ?? '') === 'company' ? 'selected' : '' ?>>Tüzel kişi / işletme</option><option value="individual" <?= ($current['legal_identity_type'] ?? '') === 'individual' ? 'selected' : '' ?>>Gerçek kişi</option></select></label><label>Yetki şekli<select name="authority_role"><option value="owner" <?= ($current['authority_role'] ?? '') === 'owner' ? 'selected' : '' ?>>İşletme sahibi</option><option value="authorized_representative" <?= ($current['authority_role'] ?? '') === 'authorized_representative' ? 'selected' : '' ?>>Yetkili temsilci</option><option value="contracted_supplier" <?= ($current['authority_role'] ?? '') === 'contracted_supplier' ? 'selected' : '' ?>>Sözleşmeli tedarikçi</option></select></label></div><label>Resmî ünvan / ad soyad<input name="legal_name" required maxlength="190" value="<?= htmlspecialchars((string) ($current['legal_name'] ?? $user['company_name'])) ?>"></label><label>Doğrulama referansı <small>(opsiyonel; sözleşme, ruhsat veya kayıt numarası)</small><input name="verification_reference" maxlength="120" value="<?= htmlspecialchars((string) ($current['verification_reference'] ?? '')) ?>"></label><fieldset><legend>Yetki istediğiniz ürün türleri</legend><div class="check-grid"><?php foreach ($types as $key => $type): ?><label><input type="checkbox" name="product_types[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $requested, true) ? 'checked' : '' ?>><?= htmlspecialchars($type['label']) ?></label><?php endforeach; ?></div></fieldset><section class="hotel-documents"><h3>Otel tedarikçisi belgeleri</h3><label>Otel belge numarası<input name="hotel_certificate_number" maxlength="120" value="<?= htmlspecialchars((string) ($current['hotel_certificate_number'] ?? '')) ?>" placeholder="Kültür ve Turizm Bakanlığı belge numarası"></label><?php foreach($hotelDocuments as $key=>$label):?><label><?=htmlspecialchars($label)?> <?=in_array($key,$existingDocuments,true)?'<small>Mevcut belge kayıtlı; değiştirmek için yeni dosya seçin.</small>':''?><input type="file" name="documents[<?=htmlspecialchars($key)?>]" accept="application/pdf,image/jpeg,image/png"></label><?php endforeach;?></section><label>Yöneticiye not <textarea name="request_note" rows="4" placeholder="İşletmeniz ve bu ürünleri satma/tedarik etme yetkiniz hakkında kısa bilgi verin."><?= htmlspecialchars((string) ($current['request_note'] ?? '')) ?></textarea></label><button type="submit">Doğrulama talebini gönder →</button></form>
</section><style>.verification-page{display:grid;gap:18px}.verification-status{padding:26px;border-radius:12px;background:#10211f;color:#fff}.verification-status h2{margin:8px 0;font-size:26px}.verification-status p{margin:0;color:#d7e1de;line-height:1.6}.verification-status .review-note{margin-top:14px;padding:12px;background:#ffffff14;color:#fff}.verification-form{max-width:760px}.verification-form h2{margin:0}.verification-form h3{margin:0 0 10px;font-size:16px}.verification-form>p{color:var(--muted);font-size:13px}.verification-form fieldset,.hotel-documents{border:1px solid var(--line);border-radius:8px;padding:14px}.verification-form legend{font-weight:700;font-size:13px}.verification-form textarea{border:1px solid var(--line);border-radius:6px;padding:10px;font:inherit;resize:vertical}.verification-form small{font-weight:400;color:var(--muted);display:block;margin-top:3px}.hotel-documents{display:grid;gap:11px}</style><?php supply_end(); ?>
