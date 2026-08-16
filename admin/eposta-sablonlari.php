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
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>E-posta şablonları | NEXUS Admin</title>
  <style>
    body{margin:0;font-family:Arial;background:#f7f7f2;color:#10211f}.w{width:min(960px,calc(100% - 32px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:15px 0}input,textarea,button{padding:9px;font:inherit;border:1px solid #d8ded8}textarea{width:100%;min-height:140px;box-sizing:border-box;font-family:monospace}label{display:block;margin:8px 0 4px;font-weight:700;font-size:13px}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}button{background:#10211f;color:#fff;border:0;cursor:pointer;margin-top:8px}.muted{color:#64716d;font-size:13px}
  </style>
</head>
<body>
<main class="w"><a href="/nexustraveltech/admin/">← Panel</a><h1>E-posta şablonları</h1>
<p class="muted">Şablonlar <code>{misafir_adi}</code>, <code>{referans}</code>, <code>{iade}</code>, <code>{neden}</code> gibi değişkenlerle doldurulur. Hazır kodlar: <b>booking_confirmation</b>, <b>booking_cancelled</b>, <b>welcome</b>.</p>
<?php if ($msg): ?><p class="ok"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="er"><?= htmlspecialchars($err) ?></p><?php endif; ?>

<section class="c"><h2>Yeni şablon</h2>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="save">
<label>Kod <input name="code" placeholder="welcome" required></label>
<label>Ad <input name="name" placeholder="Hoş geldiniz e-postası" required></label>
<label>Konu <input name="subject" placeholder="Hoş geldiniz {misafir_adi}!" required></label>
<label>HTML içerik <textarea name="body_html" placeholder="&lt;p&gt;Sayın {misafir_adi}, ...&lt;/p&gt;" required></textarea></label>
<label><input type="checkbox" name="is_active" checked> Aktif</label>
<button>Kaydet</button></form></section>

<?php foreach ($templates as $t): ?>
<section class="c"><h2><?= htmlspecialchars($t['code']) ?> — <?= htmlspecialchars($t['name']) ?><?= $t['is_active'] ? '' : ' <small>(pasif)</small>' ?></h2>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
<label>Kod <input name="code" value="<?= htmlspecialchars($t['code']) ?>" required></label>
<label>Ad <input name="name" value="<?= htmlspecialchars($t['name']) ?>" required></label>
<label>Konu <input name="subject" value="<?= htmlspecialchars($t['subject']) ?>" required></label>
<label>HTML içerik <textarea name="body_html" required><?= htmlspecialchars($t['body_html']) ?></textarea></label>
<label><input type="checkbox" name="is_active" <?= $t['is_active'] ? 'checked' : '' ?>> Aktif</label>
<button>Güncelle</button></form>
<form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="test"><input type="hidden" name="code" value="<?= htmlspecialchars($t['code']) ?>"><button style="background:#a86026">Test gönder</button></form>
<form method="post" style="display:inline" onsubmit="return confirm('Şablon silinsin mi?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button style="background:#8e2410">Sil</button></form>
</section>
<?php endforeach; ?>
</main>
<?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?></body>
</html>
