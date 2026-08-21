<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/mailer.php';
require __DIR__ . '/../config/audit.php';

require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['admin_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $err = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $code = strtolower(trim((string) ($_POST['code'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                $subject = trim((string) ($_POST['subject'] ?? ''));
                $body = (string) ($_POST['body_html'] ?? '');
                if ($code === '' || $name === '' || $subject === '' || $body === '') throw new RuntimeException('Kod, ad, konu ve içerik zorunludur.');
                $active = isset($_POST['is_active']);
                if ($id > 0) {
                    db()->prepare('UPDATE email_templates SET code=?,name=?,subject=?,body_html=?,is_active=?,updated_at=now() WHERE id=?')->execute([$code, $name, $subject, $body, $active, $id]);
                } else {
                    db()->prepare('INSERT INTO email_templates(code,name,subject,body_html,is_active) VALUES(?,?,?,?,?)')->execute([$code, $name, $subject, $body, $active]);
                }
                audit_log('email_template.save', 'template', $id ?: null, ['code' => $code]);
                $msg = 'Şablon kaydedildi.';
            }
            if ($action === 'delete') {
                db()->prepare('DELETE FROM email_templates WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
                audit_log('email_template.delete', 'template', (int) ($_POST['id'] ?? 0));
                $msg = 'Şablon silindi.';
            }
            if ($action === 'test') {
                $tpl = render_email_template(trim((string) ($_POST['code'] ?? '')), ['misafir_adi' => 'Test Misafiri', 'referans' => 'NXR-TEST-001', 'iade' => '100,00 EUR', 'neden' => 'Test']);
                $to = (string) platform_setting('admin_alert_email', '');
                if ($to === '') throw new RuntimeException('Test gönderimi için platform ayarlarında admin_alert_email tanımlı olmalı.');
                if ($tpl === null) throw new RuntimeException('Aktif şablon bulunamadı.');
                queue_email($to, '[TEST] ' . $tpl['subject'], $tpl['body_html'], 'template_test');
                $msg = 'Test e-postası kuyruğa alındı: ' . $to;
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$templates = db()->query('SELECT * FROM email_templates ORDER BY code')->fetchAll();
?>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('E-posta Şablonları & Bildirimler', 'eposta-sablonlari');
?>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:24px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">✉️ Yeni E-posta Şablonu Tanımla</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Değişkenler: <code>{misafir_adi}</code>, <code>{referans}</code>, <code>{iade}</code>, <code>{neden}</code> vb.
            </p>
        </div>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
        <input type="hidden" name="action" value="save">
        
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;margin-bottom:14px">
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Şablon Kodu *</label>
                <input name="code" class="sui-input" placeholder="booking_confirmation" required>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Şablon Adı *</label>
                <input name="name" class="sui-input" placeholder="Rezervasyon Onay E-postası" required>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Konu Başlığı *</label>
                <input name="subject" class="sui-input" placeholder="Rezervasyonunuz Onaylandı #{referans}" required>
            </div>
        </div>

        <div style="margin-bottom:14px">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">HTML İçerik *</label>
            <textarea name="body_html" class="sui-input" style="font-family:monospace;min-height:100px" placeholder="<p>Sayın {misafir_adi}, rezervasyonunuz onaylanmıştır...</p>" required></textarea>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center">
            <label style="font-size:13px;display:flex;align-items:center;gap:6px">
                <input type="checkbox" name="is_active" checked> Aktif Olarak Kaydet
            </label>
            <button class="sui-btn sui-btn-primary">Şablonu Ekle</button>
        </div>
    </form>
</div>

<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">📑 Kayıtlı E-posta Şablonları (<?= count($templates) ?>)</h2>
    </div>

    <div style="display:grid;gap:18px">
        <?php foreach ($templates as $t): ?>
            <div style="background:#fff;border:1px solid var(--sui-border);border-radius:var(--sui-radius-sm);padding:18px;box-shadow:var(--sui-shadow-sm)">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                        <div style="display:flex;gap:8px;align-items:center">
                            <b><?= htmlspecialchars($t['code']) ?></b>
                            <span class="sui-badge <?= $t['is_active'] ? 'sui-badge-success' : 'sui-badge-warning' ?>">
                                <?= $t['is_active'] ? 'Aktif' : 'Pasif' ?>
                            </span>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;margin-bottom:12px">
                        <div>
                            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Kod</label>
                            <input name="code" class="sui-input" value="<?= htmlspecialchars($t['code']) ?>" required>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Ad</label>
                            <input name="name" class="sui-input" value="<?= htmlspecialchars($t['name']) ?>" required>
                        </div>
                        <div>
                            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Konu</label>
                            <input name="subject" class="sui-input" value="<?= htmlspecialchars($t['subject']) ?>" required>
                        </div>
                    </div>

                    <div style="margin-bottom:12px">
                        <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">HTML İçerik</label>
                        <textarea name="body_html" class="sui-input" style="font-family:monospace;min-height:90px" required><?= htmlspecialchars($t['body_html']) ?></textarea>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px">
                            <input type="checkbox" name="is_active" <?= $t['is_active'] ? 'checked' : '' ?>> Aktif
                        </label>
                        <div style="display:flex;gap:6px">
                            <button class="sui-btn sui-btn-primary sui-btn-sm">Güncelle</button>
                        </div>
                    </div>
                </form>

                <div style="display:flex;gap:6px;margin-top:10px;border-top:1px solid var(--sui-border);padding-top:10px">
                    <form method="post" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                        <input type="hidden" name="action" value="test">
                        <input type="hidden" name="code" value="<?= htmlspecialchars($t['code']) ?>">
                        <button class="sui-btn sui-btn-outline sui-btn-sm">📨 Test Gönder</button>
                    </form>
                    <form method="post" style="margin:0" onsubmit="return confirm('Şablon silinsin mi?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button class="sui-btn sui-btn-danger sui-btn-sm">Sil</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

