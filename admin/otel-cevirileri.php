<?php
declare(strict_types=1);
require __DIR__ . '/../config/auth.php'; require __DIR__ . '/../config/database.php'; require_admin();
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$locales=['tr'=>'Türkçe','en'=>'English','de'=>'Deutsch','ru'=>'Русский','ar'=>'العربية','fr'=>'Français'];
$locale=$_GET['locale'] ?? 'en'; if(!isset($locales[$locale])) $locale='en'; $notice='';
if($_SERVER['REQUEST_METHOD']==='POST' && hash_equals($_SESSION['admin_csrf'],(string)($_POST['csrf']??''))){$locale=$_POST['locale']??'en'; foreach(($_POST['translation']??[]) as $id=>$name){$name=trim((string)$name); if($name!=='') db()->prepare('INSERT INTO hotel_taxonomy_translations (taxonomy_id,locale,name) VALUES (?,?,?) ON CONFLICT (taxonomy_id,locale) DO UPDATE SET name=EXCLUDED.name')->execute([(int)$id,$locale,$name]);} $notice='Çeviriler kaydedildi.';}
$rows=db()->prepare('SELECT h.*,COALESCE(t.name, h.name) display_name FROM hotel_taxonomies h LEFT JOIN hotel_taxonomy_translations t ON t.taxonomy_id=h.id AND t.locale=? ORDER BY h.taxonomy_type,h.sort_order,h.name'); $rows->execute([$locale]); $rows=$rows->fetchAll();
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Otel Taksonomi ve Özellik Çevirileri', 'ozellik-listeleri');
?>

<?php if ($notice): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

<div class="sui-card" style="margin-bottom:20px">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">🌐 Dil Seçimi</h2>
            <p style="color:var(--sui-muted);font-size:13px;margin:4px 0 0 0">
                Otel özellikleri ve taksonomilerini uluslararası dillerde yerelleştirin. Boş bırakılan alanlar varsayılan dilde kalır.
            </p>
        </div>
        <form method="get" style="margin:0">
            <select name="locale" class="sui-input" onchange="this.form.submit()" style="font-weight:700">
                <?php foreach($locales as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $locale === $key ? 'selected' : '' ?>><?= $label ?> (<?= strtoupper($key) ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="sui-card">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
        <input type="hidden" name="locale" value="<?= $locale ?>">

        <div style="display:grid;gap:12px;margin-bottom:20px">
            <?php foreach($rows as $row): ?>
                <div style="display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:center;padding:12px;border-bottom:1px solid var(--sui-border)">
                    <div>
                        <span class="sui-badge sui-badge-info" style="font-size:10px"><?= htmlspecialchars($row['taxonomy_type']) ?></span>
                        <div style="font-weight:700;font-size:14px;margin-top:4px"><?= htmlspecialchars($row['name']) ?></div>
                    </div>
                    <div>
                        <input name="translation[<?= (int)$row['id'] ?>]" class="sui-input" value="<?= htmlspecialchars($row['display_name']) ?>" placeholder="<?= htmlspecialchars($row['name']) ?>">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <button class="sui-btn sui-btn-primary" style="padding:12px 24px;font-size:14px">Tüm Çevirileri Kaydet</button>
    </form>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

