<?php
declare(strict_types=1);
require __DIR__ . '/../config/auth.php'; require __DIR__ . '/../config/database.php';
require_admin(); if(empty($_SESSION['admin_csrf']))$_SESSION['admin_csrf']=bin2hex(random_bytes(32)); $message='';$error='';
try {
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($_SESSION['admin_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Güvenlik doğrulaması geçersiz.');
 $action=(string)($_POST['action']??'');
 if($action==='package'){
  $code=strtoupper(trim((string)$_POST['code']));$name=trim((string)$_POST['name']);$credits=(int)$_POST['credits'];$price=(float)$_POST['price'];$currency=strtoupper(trim((string)$_POST['currency']));
  if(!preg_match('/^[A-Z0-9_-]{2,40}$/',$code)||$name===''||$credits<1||$price<0||!preg_match('/^[A-Z]{3}$/',$currency))throw new RuntimeException('Paket alanlarını kontrol edin.');
  db()->prepare('INSERT INTO sms_packages(code,name,credit_count,price_amount,currency) VALUES(?,?,?,?,?)')->execute([$code,$name,$credits,$price,$currency]);$message='SMS paketi oluşturuldu.';
 }
 if($action==='entitlement'){
  $type=$_POST['account_type']==='agency'?'agency':'supplier';$id=(int)($_POST[$type === 'agency' ? 'agency_id' : 'supplier_id'] ?? 0);$credits=(int)$_POST['credits'];$phone=trim((string)$_POST['phone']);
  if($id<1||$credits<0||!preg_match('/^[0-9+ ()-]{10,22}$/',$phone))throw new RuntimeException('Hesap, kredi ve bildirim telefonu alanlarını kontrol edin.');
  db()->prepare('INSERT INTO sms_entitlements(account_type,account_id,is_enabled,credits_remaining,notification_phone,updated_at) VALUES(?,?,?,?,?,now()) ON CONFLICT(account_type,account_id) DO UPDATE SET is_enabled=EXCLUDED.is_enabled,credits_remaining=EXCLUDED.credits_remaining,notification_phone=EXCLUDED.notification_phone,updated_at=now()')->execute([$type,$id,isset($_POST['enabled']),$credits,$phone]);$message='SMS kullanım hakkı kaydedildi.';
 }
}
$suppliers=db()->query('SELECT id,company_name FROM suppliers ORDER BY company_name')->fetchAll();$agencies=db()->query('SELECT id,company_name FROM agencies ORDER BY company_name')->fetchAll();$packages=db()->query('SELECT * FROM sms_packages ORDER BY id DESC')->fetchAll();
$rights=db()->query("SELECT e.*,COALESCE(s.company_name,a.company_name) company FROM sms_entitlements e LEFT JOIN suppliers s ON e.account_type='supplier' AND s.id=e.account_id LEFT JOIN agencies a ON e.account_type='agency' AND a.id=e.account_id ORDER BY e.updated_at DESC")->fetchAll();
}catch(Throwable $e){$error=$e->getMessage();$suppliers=$suppliers??[];$agencies=$agencies??[];$packages=$packages??[];$rights=$rights??[];}

require_once __DIR__ . '/layout.php';
admin_layout_start('SMS & Bildirim Yönetimi', 'sms-yonetimi');
?>

<?php if ($message): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;margin-bottom:24px">
    <!-- Paket Oluştur -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">📦 Yeni SMS Paketi</h2>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="package">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Paket Kodu</label>
                    <input name="code" class="sui-input" placeholder="SMS-500" required>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Paket Adı</label>
                    <input name="name" class="sui-input" placeholder="500 SMS Başlangıç" required>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Kredi</label>
                    <input type="number" name="credits" class="sui-input" min="1" placeholder="500" required>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Fiyat</label>
                    <input type="number" name="price" class="sui-input" min="0" step="0.01" placeholder="250.00" required>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Para Birimi</label>
                    <input name="currency" class="sui-input" value="TRY" maxlength="3" required>
                </div>
            </div>

            <button class="sui-btn sui-btn-primary" style="width:100%">Paket Oluştur</button>
        </form>
    </div>

    <!-- Hesaba SMS Tanımla -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">💳 Hesaba SMS Kredisi Tanımla</h2>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
            <input type="hidden" name="action" value="entitlement">

            <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:10px">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Hesap Türü</label>
                    <select name="account_type" id="type" class="sui-input" onchange="document.querySelectorAll('.account').forEach(x=>x.hidden=x.dataset.type!==this.value)">
                        <option value="supplier">Tedarikçi</option>
                        <option value="agency">Acente</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Firma / Hesap</label>
                    <select name="supplier_id" class="sui-input account" data-type="supplier">
                        <?php foreach($suppliers as $item): ?>
                            <option value="<?=$item['id']?>"><?=htmlspecialchars($item['company_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="agency_id" class="sui-input account" data-type="agency" hidden>
                        <?php foreach($agencies as $item): ?>
                            <option value="<?=$item['id']?>"><?=htmlspecialchars($item['company_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Bildirim Telefonu (905...)</label>
                    <input name="phone" class="sui-input" placeholder="905xxxxxxxxx" required>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Kredi Miktarı</label>
                    <input type="number" name="credits" class="sui-input" min="0" placeholder="100" required>
                </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px">
                <label style="font-size:12px;display:flex;align-items:center;gap:6px">
                    <input type="checkbox" name="enabled" checked> Rezervasyon SMS aktif
                </label>
                <button class="sui-btn sui-btn-success">Kaydet</button>
            </div>
        </form>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px">
    <!-- Tanımlı Paketler -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">📋 Satıştaki Paketler</h2>
        </div>
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Kod</th>
                    <th>Paket</th>
                    <th>Kredi</th>
                    <th>Fiyat</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($packages as $item): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($item['code']) ?></code></td>
                        <td><b><?= htmlspecialchars($item['name']) ?></b></td>
                        <td><?= (int)$item['credit_count'] ?> SMS</td>
                        <td><?= number_format((float)$item['price_amount'], 2) ?> <?= htmlspecialchars($item['currency']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$packages): ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--sui-muted)">Henüz paket yok.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Aktif Haklar -->
    <div class="sui-card">
        <div class="sui-card-header">
            <h2 class="sui-card-title">📲 Aktif SMS Hakları</h2>
        </div>
        <table class="sui-table">
            <thead>
                <tr>
                    <th>Hesap / Firma</th>
                    <th>Telefon</th>
                    <th>Kalan Kredi</th>
                    <th>Durum</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rights as $item): ?>
                    <tr>
                        <td><b><?= htmlspecialchars($item['company'] ?: $item['account_type'].' #'.$item['account_id']) ?></b></td>
                        <td style="font-size:12px;color:var(--sui-muted)"><?= htmlspecialchars((string)($item['notification_phone'] ?? '—')) ?></td>
                        <td><span class="sui-badge sui-badge-info"><?= (int)$item['credits_remaining'] ?> SMS</span></td>
                        <td>
                            <span class="sui-badge <?= $item['is_enabled'] ? 'sui-badge-success' : 'sui-badge-danger' ?>">
                                <?= $item['is_enabled'] ? 'Açık' : 'Kapalı' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$rights): ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--sui-muted)">Kayıtlı SMS hakkı yok.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

