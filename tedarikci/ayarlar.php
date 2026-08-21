<?php
declare(strict_types=1);
$active_module = '';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/i18n.php';
if (empty($_SESSION['supplier_csrf'])) $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
$u = $supplier_user;
$message = '';
$error = '';

// Dil adları (seçeneklerde gösterilecek)
$langNames = [
    'tr' => 'Türkçe',
    'en' => 'English',
    'de' => 'Deutsch',
    'ru' => 'Русский',
    'ar' => 'العربية',
    'fr' => 'Français',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['supplier_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Güvenlik doğrulaması geçersiz.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'set_language') {
            $newLang = strtolower(trim((string) ($_POST['language'] ?? 'tr')));
            set_user_language($newLang);
            // Denetim kaydı
            try {
                $oldLang = $u['language'] ?? null;
                record_audit_event('supplier', (int) $u['id'], 'profile.language_change', 'supplier_user', (int) $u['id'], [
                    'old' => $oldLang,
                    'new' => $newLang,
                ]);
            } catch (Throwable $e) {}
            $message = 'Dil tercihiniz güncellendi: ' . ($langNames[$newLang] ?? $newLang) . '.';
            $u['language'] = $newLang;
            $_SESSION['supplier_user']['language'] = $newLang;
        }

        if ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');
            $q = db()->prepare('SELECT password_hash FROM supplier_users WHERE id=?');
            $q->execute([$u['id']]);
            $hash = $q->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) {
                $error = 'Mevcut şifreniz hatalı.';
            } elseif (strlen($new) < 10) {
                $error = 'Yeni şifre en az 10 karakter olmalıdır.';
            } elseif ($new !== $confirm) {
                $error = 'Yeni şifreler eşleşmiyor.';
            } else {
                db()->prepare('UPDATE supplier_users SET password_hash=? WHERE id=?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
                $message = 'Şifreniz güncellendi.';
            }
        }
    }
}

$currentLang = $u['language'] ?? null;

supply_start('Hesap ayarları', '');
?>

<section class="page-intro">
    <p>Dil tercihinizi ve şifrenizi yönetin. Seçtiğiniz dil, arayüzdeki menü, buton ve ipuçlarını etkiler.</p>
</section>

<?php if ($message): ?><p class="save-success">✓ <?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="login-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

<section class="next-module" style="max-width:520px">
    <h2>🌍 Arayüz dili</h2>
    <form method="post" class="supply-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
        <input type="hidden" name="action" value="set_language">
        <label>
            Dil seçin
            <select name="language">
                <?php foreach ($langNames as $code => $name): ?>
                    <option value="<?= $code ?>" <?= ($currentLang ?? 'tr') === $code ? 'selected' : '' ?>>
                        <?= $name ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p style="font-size:12px;color:#6b7b6e;margin-top:4px">
            <?php if ($currentLang): ?>
                Mevcut: <b><?= $langNames[$currentLang] ?? $currentLang ?></b>
            <?php else: ?>
                Şu an admin genel ayarı kullanılıyor: <b><?= $langNames[readiness_lang()] ?? readiness_lang() ?></b>
            <?php endif; ?>
        </p>
        <button>Dili güncelle →</button>
    </form>
</section>

<section class="next-module" style="max-width:520px">
    <h2>🔒 Şifre değiştir</h2>
    <form method="post" class="supply-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['supplier_csrf']) ?>">
        <input type="hidden" name="action" value="change_password">
        <label>Mevcut şifre<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>Yeni şifre (en az 10 karakter)<input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label>
        <label>Yeni şifre tekrar<input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required></label>
        <button>Şifreyi güncelle →</button>
    </form>
</section>

<?php supply_end(); ?>
