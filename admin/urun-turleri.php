<?php
declare(strict_types=1); require __DIR__.'/../config/auth.php'; require __DIR__.'/../config/database.php'; require __DIR__.'/../config/product_types.php'; require_once __DIR__.'/../config/listing_integrity.php'; require_admin(); if(empty($_SESSION['admin_csrf']))$_SESSION['admin_csrf']=bin2hex(random_bytes(32));seed_product_type_catalog();$msg='';$err='';if($_SERVER['REQUEST_METHOD']==='POST'){if(!hash_equals($_SESSION['admin_csrf'],(string)($_POST['csrf']??'')))$err='Güvenlik doğrulaması geçersiz.';else{$action=(string)($_POST['action']??'save');try{if($action==='delete'){$code=(string)($_POST['code']??'');$usedSt=db()->prepare('SELECT COUNT(*) FROM properties WHERE property_type=?');$usedSt->execute([$code]);$used=(int)$usedSt->fetchColumn();if($used>0)throw new RuntimeException('Bu türde '.$used.' ilan var — silemezsiniz; önce pasifleştirin.');db()->prepare('DELETE FROM product_type_catalog WHERE code=?')->execute([$code]);record_audit_event('admin',null,'product_type.delete','product_type',0,['code'=>$code]);$msg='Ürün türü silindi.';}elseif($action==='toggle'){$code=(string)($_POST['code']??'');$rowSt=db()->prepare('SELECT is_active FROM product_type_catalog WHERE code=?');$rowSt->execute([$code]);$row=$rowSt->fetch();if(!$row)throw new RuntimeException('Ürün türü bulunamadı.');$new=(bool)$row['is_active']?0:1;db()->prepare('UPDATE product_type_catalog SET is_active=?,updated_at=now() WHERE code=?')->execute([$new,$code]);record_audit_event('admin',null,'product_type.toggle','product_type',0,['code'=>$code,'is_active'=>(bool)$new]);$msg=$new?'Ürün türü aktifleştirildi.':'Ürün türü pasifleştirildi.';}elseif($action==='reset_steps'){$code=(string)($_POST['code']??'');$def=default_product_types()[$code]??null;if(!$def)throw new RuntimeException('Varsayılan şablon bulunamadı: '.$code);$curSt=db()->prepare('SELECT room_setup FROM product_type_catalog WHERE code=?');$curSt->execute([$code]);$cur=(bool)($curSt->fetch()['room_setup']??false);$targets=default_step_targets($code,$cur);db()->prepare('UPDATE product_type_catalog SET steps=?::jsonb,step_targets=?::jsonb,fields=?::jsonb,updated_at=now() WHERE code=?')->execute([json_encode($def['steps'],JSON_UNESCAPED_UNICODE),json_encode($targets,JSON_UNESCAPED_UNICODE),json_encode($def['fields'],JSON_UNESCAPED_UNICODE),$code]);record_audit_event('admin',null,'product_type.reset_steps','product_type',0,['code'=>$code]);$msg='Adımlar ve alanlar varsayılana sıfırlandı.';}else{$stepNames=is_array($_POST['steps']??null)?array_values(array_filter(array_map(fn($s)=>trim((string)$s),$_POST['steps']),fn($s)=>$s!=='')):[];$stepTargets=is_array($_POST['step_targets']??null)?array_values(array_map(fn($s)=>trim((string)$s),$_POST['step_targets'])):[];$fields=json_decode((string)$_POST['fields'],true);if(!$stepNames||!is_array($fields))throw new RuntimeException('En az bir adım gerekli; alanlar geçerli JSON olmalıdır.');$targets=[];foreach($stepNames as $i=>$n)$targets[]=(string)($stepTargets[$i]??'');db()->prepare('UPDATE product_type_catalog SET label=?,unit=?,steps=?::jsonb,fields=?::jsonb,step_targets=?::jsonb,room_setup=?,hint=?,is_active=?,sort_order=?,updated_at=now() WHERE code=?')->execute([trim((string)$_POST['label']),trim((string)$_POST['unit']),json_encode($stepNames,JSON_UNESCAPED_UNICODE),json_encode($fields,JSON_UNESCAPED_UNICODE),json_encode($targets,JSON_UNESCAPED_UNICODE),isset($_POST['room_setup']),trim((string)$_POST['hint']),isset($_POST['is_active']),(int)$_POST['sort_order'],$_POST['code']]);$msg='Şablon kaydedildi.';}}catch(Throwable $e){$err=$e->getMessage();}}}$rows=db()->query('SELECT * FROM product_type_catalog ORDER BY sort_order,code')->fetchAll();
$propMap=[];
$pQ=db()->prepare('SELECT id,property_type,name FROM properties WHERE property_type=? AND status=\'active\' ORDER BY id LIMIT 1');
foreach($rows as $r){$pQ->execute([$r['code']]);$propMap[$r['code']]=$pQ->fetch()?:null;}
/* property counts per type */
$delCounts=[];
$dQ=db()->prepare('SELECT status,count(*) AS cnt FROM properties WHERE property_type=? GROUP BY status');
foreach($rows as $r){$dQ->execute([$r['code']]);$delCounts[$r['code']]=$dQ->fetchAll(PDO::FETCH_KEY_PAIR);}

require_once __DIR__ . '/layout.php';
admin_layout_start('Ürün Türleri ve Şablon Yönetimi', 'urun-turleri');
?>

<style>
.steps-editor { display: grid; gap: 8px; }
.step-row { display: grid; grid-template-columns: auto 1fr 200px 36px 36px 36px; gap: 8px; align-items: center; background: #fff; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--sui-border); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
.step-row input, .step-row select { padding: 8px 12px; border: 1px solid var(--sui-border); border-radius: 6px; font: inherit; background: var(--sui-bg); }
.step-grip { cursor: grab; color: #8392ab; font-size: 14px; user-select: none; text-align: center; }
.step-mv { background: #f8f9fa; border: 1px solid var(--sui-border); color: #344767; font-weight: bold; cursor: pointer; padding: 6px; border-radius: 6px; height: 34px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.step-mv:hover:not(:disabled) { background: #e9ecef; color: #1a1f36; }
.step-mv:disabled { opacity: .25; cursor: default; }
.step-del { background: #fff1f2; border: 1px solid #fecdd3; color: #e11d48; font-weight: bold; cursor: pointer; border-radius: 6px; height: 34px; display: flex; align-items: center; justify-content: center; font-size: 14px; transition: all 0.2s; }
.step-del:hover { background: #ffe4e6; }
.c-inactive { opacity: .7; }
</style>

<?php if ($msg): ?><div class="sui-alert sui-alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sui-alert sui-alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div style="display:grid;gap:20px;margin-bottom:24px">
    <?php foreach ($rows as $x): ?>
        <div class="sui-card <?= $x['is_active'] ? '' : 'c-inactive' ?>">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="code" value="<?= htmlspecialchars($x['code']) ?>">
                
                <div class="sui-card-header">
                    <div>
                        <h2 class="sui-card-title" style="display:inline-block"><?= htmlspecialchars($x['code']) ?></h2>
                        <?php if (!$x['is_active']): ?>
                            <span class="sui-badge sui-badge-warning" style="margin-left:8px">Pasif</span>
                        <?php else: ?>
                            <span class="sui-badge sui-badge-success" style="margin-left:8px">Aktif</span>
                        <?php endif; ?>
                    </div>
                    <span class="sui-badge sui-badge-info">Sıra: #<?= (int)$x['sort_order'] ?></span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;margin-bottom:14px">
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Görünen Başlık</label>
                        <input name="label" class="sui-input" value="<?= htmlspecialchars($x['label']) ?>" required>
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Birim Adı (örn: Gece / Hafta)</label>
                        <input name="unit" class="sui-input" value="<?= htmlspecialchars($x['unit']) ?>" required>
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Sıralama</label>
                        <input name="sort_order" type="number" class="sui-input" value="<?= (int)$x['sort_order'] ?>">
                    </div>
                </div>

                <div style="margin-bottom:14px">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Açıklama / İpucu</label>
                    <input name="hint" class="sui-input" value="<?= htmlspecialchars($x['hint']) ?>">
                </div>

                <div style="margin-bottom:14px">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">Kurulum Adımları</label>
                    <div id="steps-<?= htmlspecialchars($x['code']) ?>" class="steps-editor" data-code="<?= htmlspecialchars($x['code']) ?>">
                        <?php 
                        $sList = json_decode((string)$x['steps'], true) ?: [];
                        $tList = json_decode((string)$x['step_targets'], true) ?: [];
                        foreach ($sList as $si => $sName): 
                        ?>
                            <div class="step-row" draggable="true">
                                <span class="step-grip"><i class="fa-solid fa-grip-vertical"></i></span>
                                <input name="steps[]" value="<?= htmlspecialchars((string)$sName) ?>" placeholder="Adım adı">
                                <select name="step_targets[]">
                                    <option value="">Bölüm yok</option>
                                    <?php foreach (['sec-01' => 'Temel bilgiler', 'sec-02' => 'Oda / birim', 'sec-03' => 'Olanaklar & hizmetler', 'sec-04' => 'Envanter & fiyat', 'sec-05' => 'Görseller', 'sec-06' => 'Komisyon & tahsilat', 'sec-07' => 'İptal & iade'] as $v => $l): ?>
                                        <option value="<?= $v ?>" <?= ($tList[$si] ?? '') === $v ? 'selected' : '' ?>><?= $l ?> (<?= $v ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="step-mv" data-dir="up" title="Yukarı Taşı"><i class="fa-solid fa-arrow-up"></i></button>
                                <button type="button" class="step-mv" data-dir="down" title="Aşağı Taşı"><i class="fa-solid fa-arrow-down"></i></button>
                                <button type="button" class="step-del" onclick="this.parentElement.remove()" title="Sil"><i class="fa-solid fa-trash-can"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="sui-btn sui-btn-outline sui-btn-sm" style="margin-top:10px" onclick="addStepRow('<?= htmlspecialchars($x['code']) ?>')">
                        <i class="fa-solid fa-plus"></i> Yeni Adım Ekle
                    </button>
                </div>

                <div style="margin-bottom:14px">
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Alan Yapısı (JSON)</label>
                    <textarea name="fields" class="sui-input" style="font-family:monospace;min-height:80px"><?= htmlspecialchars($x['fields']) ?></textarea>
                </div>

                <div style="display:flex;gap:18px;align-items:center;margin-bottom:14px">
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px">
                        <input type="checkbox" name="room_setup" <?= $x['room_setup'] ? 'checked' : '' ?>> Oda/Birim Kurulumu Var
                    </label>
                    <label style="font-size:13px;display:flex;align-items:center;gap:6px">
                        <input type="checkbox" name="is_active" <?= $x['is_active'] ? 'checked' : '' ?>> Aktif
                    </label>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                    <button class="sui-btn sui-btn-primary">Şablonu Kaydet</button>
                </div>
            </form>

            <div style="display:flex;gap:8px;margin-top:10px;border-top:1px solid var(--sui-border);padding-top:10px">
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                    <input type="hidden" name="action" value="reset_steps">
                    <input type="hidden" name="code" value="<?= htmlspecialchars($x['code']) ?>">
                    <button type="submit" class="sui-btn sui-btn-outline sui-btn-sm" title="Adımlar varsayılan şablona döner">↺ Varsayılana Sıfırla</button>
                </form>
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="code" value="<?= htmlspecialchars($x['code']) ?>">
                    <button type="submit" class="sui-btn sui-btn-outline sui-btn-sm"><?= $x['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
window.addStepRow = function(code) {
    var c = document.getElementById('steps-' + code);
    if (!c) return;
    var d = document.createElement('div');
    d.className = 'step-row';
    d.draggable = true;
    d.innerHTML = '<span class="step-grip"><i class="fa-solid fa-grip-vertical"></i></span>' +
        '<input name="steps[]" placeholder="Adım adı">' +
        '<select name="step_targets[]">' +
        '<option value="">Bölüm yok</option>' +
        '<option value="sec-01">Temel bilgiler (sec-01)</option>' +
        '<option value="sec-02">Oda / birim (sec-02)</option>' +
        '<option value="sec-03">Olanaklar & hizmetler (sec-03)</option>' +
        '<option value="sec-04">Envanter & fiyat (sec-04)</option>' +
        '<option value="sec-05">Görseller (sec-05)</option>' +
        '<option value="sec-06">Komisyon & tahsilat (sec-06)</option>' +
        '<option value="sec-07">İptal & iade (sec-07)</option>' +
        '</select>' +
        '<button type="button" class="step-mv" data-dir="up" title="Yukarı Taşı"><i class="fa-solid fa-arrow-up"></i></button>' +
        '<button type="button" class="step-mv" data-dir="down" title="Aşağı Taşı"><i class="fa-solid fa-arrow-down"></i></button>' +
        '<button type="button" class="step-del" onclick="this.parentElement.remove()" title="Sil"><i class="fa-solid fa-trash-can"></i></button>';
    c.appendChild(d);
    if (window.nexusStepsRefresh) window.nexusStepsRefresh(c);
};

window.nexusStepsRefresh = function(wrap) {
    var rows = wrap.querySelectorAll('.step-row');
    rows.forEach(function(r, i) {
        var u = r.querySelector('.step-mv[data-dir="up"]'),
            d = r.querySelector('.step-mv[data-dir="down"]');
        if (u) u.disabled = (i === 0);
        if (d) d.disabled = (i === rows.length - 1);
    });
};

document.addEventListener('click', function(e) {
    var mv = e.target.closest ? e.target.closest('.step-mv') : null;
    if (mv) {
        e.preventDefault();
        var wrap = mv.closest('.steps-editor');
        if (!wrap) return;
        var row = mv.closest('.step-row'),
            dir = mv.getAttribute('data-dir'),
            sib = (dir === 'up') ? row.previousElementSibling : row.nextElementSibling;
        if (!sib) return;
        if (dir === 'up') wrap.insertBefore(row, sib);
        else wrap.insertBefore(sib, row);
        window.nexusStepsRefresh(wrap);
        return;
    }
    var dl = e.target.closest ? e.target.closest('.step-del') : null;
    if (dl) {
        var w2 = dl.closest('.steps-editor');
        if (w2) window.nexusStepsRefresh(w2);
    }
});
</script>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

