<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/listing_integrity.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/notifications.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $err = 'Güvenlik doğrulaması geçersiz.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'create') {
                $company = trim((string)$_POST['company_name']);
                $name = trim((string)$_POST['full_name']);
                $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
                $pass = (string)($_POST['password'] ?? '');
                if ($company === '' || $name === '' || !$email || strlen($pass) < 10) {
                    throw new RuntimeException('Ünvan, yetkili, geçerli e-posta ve en az 10 karakter şifre zorunludur.');
                }
                $pdo = db();
                $pdo->beginTransaction();
                $q = $pdo->prepare("INSERT INTO agencies(company_name,license_number,country_code,status) VALUES(?,?,?,'active') RETURNING id");
                $q->execute([$company, trim((string)$_POST['license_number']) ?: null, strtoupper(trim((string)($_POST['country_code'] ?? 'TR')))]);
                $id = (int)$q->fetchColumn();
                $pdo->prepare("INSERT INTO agency_users(agency_id,full_name,email,password_hash,role) VALUES(?,?,?,?, 'owner')")
                    ->execute([$id, $name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                $pdo->commit();
                record_audit_event('admin', null, 'agency.created', 'agency', $id);
                $msg = 'Acente hesabı başarıyla aktif oluşturuldu.';
            }
            if ($action === 'status') {
                db()->prepare('UPDATE agencies SET status=? WHERE id=?')->execute([$_POST['status'] === 'active' ? 'active' : 'suspended', (int)$_POST['id']]);
                $msg = 'Acente durumu güncellendi.';
            }
            if ($action === 'credit') {
                db()->prepare('UPDATE agencies SET credit_limit=?,payment_score=? WHERE id=?')->execute([
                    ($_POST['credit_limit'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['credit_limit']) : null,
                    max(0, min(100, (float)str_replace(',', '.', $_POST['payment_score'] ?? 0))),
                    (int)$_POST['id']
                ]);
                $msg = 'Kredi limiti ve ödeme skoru güncellendi.';
            }
            if ($action === 'approve') {
                $id = (int)$_POST['id'];
                $q = db()->prepare("UPDATE agencies SET status='active' WHERE id=? AND status='pending'");
                $q->execute([$id]);
                if ($q->rowCount() > 0) {
                    audit_log('agency.approved', 'agency', $id);
                    $ownerQ = db()->prepare("SELECT id FROM agency_users WHERE agency_id=? AND role='owner' ORDER BY id LIMIT 1");
                    $ownerQ->execute([$id]);
                    $ownerId = $ownerQ->fetchColumn();
                    if ($ownerId) {
                        notify_user('agency', (int)$ownerId, 'agency.approved', 'Acente hesabınız onaylandı; artık giriş yapabilirsiniz.', '/nexustraveltech/acente/login');
                    }
                    $msg = 'Acente onaylandı ve sahibine bildirim gönderildi.';
                } else {
                    $err = 'Onaylanacak bekleyen acente bulunamadı.';
                }
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $err = $e->getMessage();
        }
    }
}

$rows = db()->query("SELECT a.*,u.full_name,u.email FROM agencies a LEFT JOIN agency_users u ON u.agency_id=a.id AND u.role='owner' ORDER BY a.id DESC")->fetchAll();

admin_layout_start('Acente Yönetimi & B2B Ağı', 'acenteler');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <h2 class="sui-card-title">➕ Yeni Acente Tanımla</h2>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;margin-bottom:14px">
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--sui-muted);display:block;margin-bottom:4px">Acente Ünvanı *</label>
                <input name="company_name" class="sui-input" placeholder="Örn: Tatil Turizm A.Ş." required>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--sui-muted);display:block;margin-bottom:4px">TÜRSAB / Lisans No</label>
                <input name="license_number" class="sui-input" placeholder="Örn: 12345">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--sui-muted);display:block;margin-bottom:4px">Yetkili Adı Soyadı *</label>
                <input name="full_name" class="sui-input" placeholder="Örn: Ahmet Yılmaz" required>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--sui-muted);display:block;margin-bottom:4px">E-posta Adresi *</label>
                <input name="email" type="email" class="sui-input" placeholder="acente@sirket.com" required>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--sui-muted);display:block;margin-bottom:4px">İlk Giriş Şifresi *</label>
                <input name="password" type="password" class="sui-input" placeholder="En az 10 karakter" required>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--sui-muted);display:block;margin-bottom:4px">Ülke Kodu</label>
                <input name="country_code" class="sui-input" value="TR" maxlength="2">
            </div>
        </div>
        <button class="sui-btn sui-btn-primary">Aktif Acente Oluştur</button>
    </form>
</div>

<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">🧳 Kayıtlı Acenteler (<?= count($rows) ?>)</h2>
    </div>
    <div style="overflow-x:auto">
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Acente Ünvanı</th>
                    <th>Yetkili / İletişim</th>
                    <th>Durum</th>
                    <th>Ödeme Skoru</th>
                    <th>Kredi Limiti (EUR)</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <b><?= htmlspecialchars($r['company_name']) ?></b>
                            <?php if ($r['self_registered']): ?>
                                <span class="sui-badge sui-badge-warning" style="font-size:10px">Self-servis</span>
                            <?php endif; ?>
                            <div style="font-size:11px;color:var(--sui-muted)"><?= htmlspecialchars($r['license_number'] ?: 'Lisans belirtilmedi') ?></div>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($r['full_name'] ?: '—') ?></div>
                            <div style="font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars($r['email'] ?: '—') ?></div>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'active'): ?>
                                <span class="sui-badge sui-badge-success">Aktif</span>
                            <?php elseif ($r['status'] === 'pending'): ?>
                                <span class="sui-badge sui-badge-warning">Onay Bekliyor</span>
                            <?php else: ?>
                                <span class="sui-badge sui-badge-danger">Askıda</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <b><?= htmlspecialchars((string)($r['payment_score'] ?? 0)) ?> / 100</b>
                        </td>
                        <td>
                            <b><?= $r['credit_limit'] !== null ? number_format((float)$r['credit_limit'], 2) . ' EUR' : 'Limitsiz' ?></b>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <?php if ($r['status'] === 'pending' && $r['verified_at']): ?>
                                    <form method="post" style="margin:0">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button class="sui-btn sui-btn-success sui-btn-sm">✓ Onayla</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" style="margin:0;display:flex;gap:4px">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <select name="status" class="sui-input" style="padding:4px 8px;font-size:12px;width:auto">
                                        <option value="active" <?= $r['status']==='active'?'selected':'' ?>>Aktif</option>
                                        <option value="suspended" <?= $r['status']==='suspended'?'selected':'' ?>>Askıya al</option>
                                    </select>
                                    <button class="sui-btn sui-btn-outline sui-btn-sm">Kaydet</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>
