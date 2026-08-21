<?php
declare(strict_types=1); require __DIR__.'/../config/auth.php'; require __DIR__.'/../config/database.php'; require __DIR__.'/../config/product_types.php'; require_once __DIR__.'/../config/listing_integrity.php'; require_admin(); if(empty($_SESSION['admin_csrf']))$_SESSION['admin_csrf']=bin2hex(random_bytes(32));seed_product_type_catalog();$msg='';$err='';if($_SERVER['REQUEST_METHOD']==='POST'){if(!hash_equals($_SESSION['admin_csrf'],(string)($_POST['csrf']??'')))$err='Güvenlik doğrulaması geçersiz.';else{$action=(string)($_POST['action']??'save');try{if($action==='delete'){$code=(string)($_POST['code']??'');$usedSt=db()->prepare('SELECT COUNT(*) FROM properties WHERE property_type=?');$usedSt->execute([$code]);$used=(int)$usedSt->fetchColumn();if($used>0)throw new RuntimeException('Bu türde '.$used.' ilan var — silemezsiniz; önce pasifleştirin.');db()->prepare('DELETE FROM product_type_catalog WHERE code=?')->execute([$code]);record_audit_event('admin',null,'product_type.delete','product_type',0,['code'=>$code]);$msg='Ürün türü silindi.';}elseif($action==='toggle'){$code=(string)($_POST['code']??'');$rowSt=db()->prepare('SELECT is_active FROM product_type_catalog WHERE code=?');$rowSt->execute([$code]);$row=$rowSt->fetch();if(!$row)throw new RuntimeException('Ürün türü bulunamadı.');$new=(bool)$row['is_active']?0:1;db()->prepare('UPDATE product_type_catalog SET is_active=?,updated_at=now() WHERE code=?')->execute([$new,$code]);record_audit_event('admin',null,'product_type.toggle','product_type',0,['code'=>$code,'is_active'=>(bool)$new]);$msg=$new?'Ürün türü aktifleştirildi.':'Ürün türü pasifleştirildi.';}elseif($action==='reset_steps'){$code=(string)($_POST['code']??'');$def=default_product_types()[$code]??null;if(!$def)throw new RuntimeException('Varsayılan şablon bulunamadı: '.$code);$curSt=db()->prepare('SELECT room_setup FROM product_type_catalog WHERE code=?');$curSt->execute([$code]);$cur=(bool)($curSt->fetch()['room_setup']??false);$targets=default_step_targets($code,$cur);db()->prepare('UPDATE product_type_catalog SET steps=?::jsonb,step_targets=?::jsonb,fields=?::jsonb,updated_at=now() WHERE code=?')->execute([json_encode($def['steps'],JSON_UNESCAPED_UNICODE),json_encode($targets,JSON_UNESCAPED_UNICODE),json_encode($def['fields'],JSON_UNESCAPED_UNICODE),$code]);record_audit_event('admin',null,'product_type.reset_steps','product_type',0,['code'=>$code]);$msg='Adımlar ve alanlar varsayılana sıfırlandı.';}else{$stepNames=is_array($_POST['steps']??null)?array_values(array_filter(array_map(fn($s)=>trim((string)$s),$_POST['steps']),fn($s)=>$s!=='')):[];$stepTargets=is_array($_POST['step_targets']??null)?array_values(array_map(fn($s)=>trim((string)$s),$_POST['step_targets'])):[];$fields=json_decode((string)$_POST['fields'],true);if(!$stepNames||!is_array($fields))throw new RuntimeException('En az bir adım gerekli; alanlar geçerli JSON olmalıdır.');$targets=[];foreach($stepNames as $i=>$n)$targets[]=(string)($stepTargets[$i]??'');db()->prepare('UPDATE product_type_catalog SET label=?,unit=?,steps=?::jsonb,fields=?::jsonb,step_targets=?::jsonb,room_setup=?,hint=?,is_active=?,sort_order=?,updated_at=now() WHERE code=?')->execute([trim((string)$_POST['label']),trim((string)$_POST['unit']),json_encode($stepNames,JSON_UNESCAPED_UNICODE),json_encode($fields,JSON_UNESCAPED_UNICODE),json_encode($targets,JSON_UNESCAPED_UNICODE),isset($_POST['room_setup']),trim((string)$_POST['hint']),isset($_POST['is_active']),(int)$_POST['sort_order'],$_POST['code']]);$msg='Şablon kaydedildi.';}}catch(Throwable $e){$err=$e->getMessage();}}}$rows=db()->query('SELECT * FROM product_type_catalog ORDER BY sort_order,code')->fetchAll();
$propMap=[];
$pQ=db()->prepare('SELECT id,property_type,name FROM properties WHERE property_type=? AND status=\'active\' ORDER BY id LIMIT 1');
foreach($rows as $r){$pQ->execute([$r['code']]);$propMap[$r['code']]=$pQ->fetch()?:null;}
/* property counts per type */
$delCounts=[];
$dQ=db()->prepare('SELECT status,count(*) AS cnt FROM properties WHERE property_type=? GROUP BY status');
foreach($rows as $r){$dQ->execute([$r['code']]);$delCounts[$r['code']]=$dQ->fetchAll(PDO::FETCH_KEY_PAIR);}?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ürün şablonları</title><style>body{font-family:Arial;background:#f7f7f2;color:#10211f;margin:0}.w{width:min(1000px,calc(100% - 30px));margin:35px auto}.c{background:#fff;border:1px solid #ddd;padding:18px;margin:16px 0}.f{display:grid;gap:9px}.r{display:grid;grid-template-columns:1fr 1fr 100px;gap:9px}input,textarea,button{padding:9px;font:inherit;border:1px solid #ddd}textarea{min-height:100px;font-family:monospace}button{background:#10211f;color:#fff;font-weight:bold}.ok{background:#e6f8c7;padding:9px}.er{background:#ffe2de;padding:9px}.steps-editor{display:grid;gap:7px}.step-row{display:grid;grid-template-columns:auto 1fr 190px 34px 34px 34px;gap:7px;align-items:center}.step-row input,.step-row select{padding:8px;border:1px solid #ccc;font:inherit}.step-grip{cursor:grab;color:#8a9aa0;font-size:16px;user-select:none;text-align:center}.step-mv{background:#1f4e6e;border:none;color:#fff;font-weight:bold;cursor:pointer;padding:8px;line-height:1}.step-mv:disabled{opacity:.35;cursor:default}.step-mv:not(:disabled):hover{background:#174a72}.step-row.dragging{opacity:.45;outline:2px dashed #1f4e6e}.step-row.drag-over{outline:2px solid #1f4e6e;background:#eef4fb}.step-del{background:#b0301a;border:none;color:#fff;font-weight:bold;cursor:pointer}.step-add{background:#1f4e6e;border:none;color:#fff;font-weight:bold;cursor:pointer;margin-top:6px}.c{position:relative}.catalog-grip{cursor:grab;color:#8a9aa0;font-size:18px;user-select:none;position:absolute;top:8px;right:40px;line-height:1}.c.dragging{opacity:.4;outline:2px dashed #1f4e6e}.c.drag-over{outline:2px solid #1f4e6e;background:#eef4fb}.step-target-link{display:inline-block;margin-left:6px;padding:2px 6px;font-size:11px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;color:#2e7d32;text-decoration:none;cursor:pointer;white-space:nowrap}.step-target-link:hover{background:#c8e6c9}.step-count{font-size:11px;color:#888;margin-left:4px}.del-impact{display:block;font-size:11px;color:#b0301a;margin-top:4px}.c-inactive{opacity:.65;background:#f5f5f2}.c-inactive .sort-order-badge{background:#e0e0e0;border-color:#ccc;color:#999}.inactive-badge{display:inline-block;font-size:11px;font-weight:700;background:#e0e0e0;color:#666;padding:2px 8px;border-radius:4px;margin-left:8px;vertical-align:middle}.c .sort-order-badge{position:absolute;top:10px;right:12px;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;padding:2px 6px;font-size:11px;color:#555;pointer-events:none}</style></head><body><main class="w"><a href="/nexustraveltech/admin/kontrol-merkezi">← Kontrol merkezi</a><h1>Ürün türleri ve form şablonları</h1><p>Bu ekran, tedarikçi ilan formundaki adımlar ve alanları veritabanından yönetir.</p><?php if($msg):?><p class="ok"><?=htmlspecialchars($msg)?></p><?php endif;?><?php if($err):?><p class="er"><?=htmlspecialchars($err)?></p><?php endif;?><?php foreach($rows as $x):?><form class="c f <?= $x['is_active'] ? '' : 'c-inactive' ?>" method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="code" value="<?=htmlspecialchars($x['code'])?>"><h2><?=htmlspecialchars($x['code'])?><?php if (!$x['is_active']): ?><span class="inactive-badge">Pasif</span><?php endif; ?></h2><div class="r"><input name="label" value="<?=htmlspecialchars($x['label'])?>"><input name="unit" value="<?=htmlspecialchars($x['unit'])?>"><input name="sort_order" type="number" value="<?=$x['sort_order']?>"></div><input name="hint" value="<?=htmlspecialchars($x['hint'])?>"><label>Kurulum adımları (her satır: ad + hedef bölüm çapası)<div id="steps-<?=htmlspecialchars($x['code'])?>" class="steps-editor" data-code="<?=htmlspecialchars($x['code'])?>"><?php $sList=json_decode((string)$x['steps'],true)?:[];$tList=json_decode((string)$x['step_targets'],true)?:[];foreach($sList as $si=>$sName):?><div class="step-row" draggable="true"><span class="step-grip" title="Sürükleyerek sırala">⠿</span><input name="steps[]" value="<?=htmlspecialchars((string)$sName)?>" placeholder="Adım adı"><select name="step_targets[]"><option value="">Bölüm yok</option><?php foreach(['sec-01'=>'Temel bilgiler','sec-02'=>'Oda / birim','sec-03'=>'Olanaklar & hizmetler','sec-04'=>'Envanter & fiyat','sec-05'=>'Görseller','sec-06'=>'Komisyon & tahsilat','sec-07'=>'İptal & iade'] as $v=>$l):?><option value="<?=$v?>" <?=($tList[$si]??'')===$v?'selected':''?>><?=$l?> (<?=$v?>)</option><?php endforeach;?></select><button type="button" class="step-mv" data-dir="up" title="Yukarı taşı">↑</button><button type="button" class="step-mv" data-dir="down" title="Aşağı taşı">↓</button><button type="button" class="step-del" onclick="this.parentElement.remove()">×</button></div><?php endforeach;?></div><button type="button" class="step-add" onclick="var c=document.getElementById('steps-<?=htmlspecialchars($x['code'])?>');var d=document.createElement('div');d.className='step-row';d.innerHTML='<span class=\"step-grip\" title=\"Sürükleyerek sırala\">⠿</span><input name=\"steps[]\" placeholder=\"Adım adı\"><select name=\"step_targets[]\"><option value=\"\">Bölüm yok</option><option value=\"sec-01\">Temel bilgiler (sec-01)</option><option value=\"sec-02\">Oda / birim (sec-02)</option><option value=\"sec-03\">Olanaklar & hizmetler (sec-03)</option><option value=\"sec-04\">Envanter & fiyat (sec-04)</option><option value=\"sec-05\">Görseller (sec-05)</option><option value=\"sec-06\">Komisyon & tahsilat (sec-06)</option><option value=\"sec-07\">İptal & iade (sec-07)</option></select><button type=\"button\" class=\"step-mv\" data-dir=\"up\" title=\"Yukarı taşı\">↑</button><button type=\"button\" class=\"step-mv\" data-dir=\"down\" title=\"Aşağı taşı\">↓</button><button type=\"button\" class=\"step-del\" onclick=\"this.parentElement.remove()\">×</button>';d.draggable=true;c.appendChild(d);window.nexusStepsRefresh&&nexusStepsRefresh(c)">+ Adım ekle</button></label><label>Alanlar (JSON)<textarea name="fields"><?=htmlspecialchars($x['fields'])?></textarea></label><label><input type="checkbox" name="room_setup" <?=$x['room_setup']?'checked':''?>> Oda/villa kurulumu</label><label><input type="checkbox" name="is_active" <?=$x['is_active']?'checked':''?>> Aktif</label><button>Kaydet</button></form><div class="type-actions"><form method="post" class="mini"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="reset_steps"><input type="hidden" name="code" value="<?=htmlspecialchars($x['code'])?>"><button type="submit" class="btn-reset" title="Adımlar, hedef bölümler ve alanlar varsayılan şablona döner">↺ Adımları sıfırla</button></form><form method="post" class="mini"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="code" value="<?=htmlspecialchars($x['code'])?>"><button type="submit" class="btn-toggle"><?= $x['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button></form><form method="post" class="mini" onsubmit="return confirm('«<?=htmlspecialchars($x['code'])?>» ürün türü kalıcı olarak silinsin mi? Bu türe bağlı doğrulama gereksinimleri de silinir.');"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['admin_csrf'])?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="code" value="<?=htmlspecialchars($x['code'])?>"><button type="submit" class="btn-del">Sil</button></form></div><?php endforeach;?></main><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/admin/ai-chat','admin_csrf'); ?><script>window.nexusStepsRefresh=function(wrap){var rows=wrap.querySelectorAll('.step-row');rows.forEach(function(r,i){var u=r.querySelector('.step-mv[data-dir="up"]'),d=r.querySelector('.step-mv[data-dir="down"]');if(u)u.disabled=i===0;if(d)d.disabled=i===rows.length-1;})};document.addEventListener('click',function(e){var mv=e.target.closest?e.target.closest('.step-mv'):null;if(mv){e.preventDefault();var wrap=mv.closest('.steps-editor');if(!wrap)return;var row=mv.closest('.step-row'),dir=mv.getAttribute('data-dir'),sib=dir==='up'?row.previousElementSibling:row.nextElementSibling;if(!sib)return;if(dir==='up')wrap.insertBefore(row,sib);else wrap.insertBefore(sib,row);window.nexusStepsRefresh(wrap);return;}var dl=e.target.closest?e.target.closest('.step-del'):null;if(dl){var w2=dl.closest('.steps-editor');if(w2)window.nexusStepsRefresh(w2);}});document.addEventListener('dragstart',function(e){var t=e.target;if(t.closest&&t.closest('button,input,select,textarea'))return;var row=t.closest?t.closest('.step-row'):null;if(!row)return;row.classList.add('dragging');try{e.dataTransfer.setData('text/plain','step')}catch(err){}});document.addEventListener('dragend',function(e){var row=e.target.closest?e.target.closest('.step-row'):null;if(row){row.classList.remove('dragging');var w=row.closest('.steps-editor');if(w)w.querySelectorAll('.step-row').forEach(function(r){r.classList.remove('drag-over')});}});document.addEventListener('dragover',function(e){var row=e.target.closest?e.target.closest('.step-row'):null;if(!row)return;e.preventDefault();var w=row.closest('.steps-editor');if(!w)return;w.querySelectorAll('.step-row').forEach(function(r){r.classList.remove('drag-over')});row.classList.add('drag-over');try{e.dataTransfer.dropEffect='move'}catch(err){}});document.addEventListener('drop',function(e){e.preventDefault();var row=e.target.closest?e.target.closest('.step-row'):null;if(!row)return;var w=row.closest('.steps-editor');if(!w)return;var from=w.querySelector('.step-row.dragging');if(!from||from===row)return;var rows=Array.prototype.slice.call(w.querySelectorAll('.step-row'));w.insertBefore(from,rows[rows.indexOf(row)+1]||null);w.querySelectorAll('.step-row').forEach(function(r){r.classList.remove('drag-over')});window.nexusStepsRefresh(w);});document.querySelectorAll('.steps-editor').forEach(window.nexusStepsRefresh);<script>(function(){var main=document.querySelector("main.w");if(!main)return;var cards=main.querySelectorAll("form.c");cards.forEach(function(card){var ci=card.querySelector("input[name=\"code\"]");if(ci)card.setAttribute("data-code",ci.value);card.setAttribute("draggable","true");var g=document.createElement("span");g.className="catalog-grip";g.title="S\u00fcr\u00fckleyerek s\u0131rala";g.textContent="\u2807";card.insertBefore(g,card.firstChild);var so=card.querySelector("input[name=\"sort_order\"]");if(so){var b=document.createElement("span");b.className="sort-order-badge";b.textContent="#"+so.value;card.appendChild(b);}});var _dc=null;main.addEventListener("dragstart",function(e){var c=e.target.closest?e.target.closest("form.c"):null;if(!c||e.target.closest("input,select,textarea,button,.step-row,.steps-editor"))return;_dc=c;c.classList.add("dragging");e.dataTransfer.effectAllowed="move";try{e.dataTransfer.setData("text/plain","card")}catch(x){}});main.addEventListener("dragover",function(e){var c=e.target.closest?e.target.closest("form.c"):null;if(!c||c===_dc)return;e.preventDefault();e.dataTransfer.dropEffect="move";main.querySelectorAll("form.c").forEach(function(x){x.classList.remove("drag-over")});c.classList.add("drag-over");});main.addEventListener("drop",function(e){e.preventDefault();var c=e.target.closest?e.target.closest("form.c"):null;main.querySelectorAll("form.c").forEach(function(x){x.classList.remove("drag-over","dragging")});if(!c||!_dc||c===_dc)return;var all=Array.prototype.slice.call(main.querySelectorAll("form.c"));var di=all.indexOf(_dc),ti=all.indexOf(c);if(di<ti)main.insertBefore(_dc,c.nextSibling);else main.insertBefore(_dc,c);reindexCatalog();_dc=null;});main.addEventListener("dragend",function(){_dc=null;main.querySelectorAll("form.c").forEach(function(x){x.classList.remove("drag-over","dragging")});});function reindexCatalog(){main.querySelectorAll("form.c").forEach(function(card,i){var so=card.querySelector("input[name=\"sort_order\"]");if(so)so.value=i+1;var b=card.querySelector(".sort-order-badge");if(b)b.textContent="#"+(i+1);});}})();</script><script>window.__delCounts=<?=json_encode($delCounts,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
<?php
require_once __DIR__ . '/layout.php';
admin_layout_start('Ürün Türleri ve Şablon Yönetimi', 'urun-turleri');
?>

<style>
.steps-editor{display:grid;gap:7px}
.step-row{display:grid;grid-template-columns:auto 1fr 190px 34px 34px 34px;gap:7px;align-items:center}
.step-row input,.step-row select{padding:6px 10px;border:1px solid var(--sui-border);border-radius:6px;font:inherit}
.step-grip{cursor:grab;color:#8a9aa0;font-size:16px;user-select:none;text-align:center}
.step-mv{background:#7928ca;border:none;color:#fff;font-weight:bold;cursor:pointer;padding:6px;border-radius:4px}
.step-mv:disabled{opacity:.35;cursor:default}
.step-del{background:var(--sui-danger);border:none;color:#fff;font-weight:bold;cursor:pointer;border-radius:4px;padding:6px}
.step-add{background:#7928ca;border:none;color:#fff;font-weight:bold;cursor:pointer;margin-top:6px;padding:6px 12px;border-radius:6px}
.step-target-link{display:inline-block;margin-left:6px;padding:2px 6px;font-size:11px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;color:#2e7d32;text-decoration:none;cursor:pointer;white-space:nowrap}
.step-target-link:hover{background:#c8e6c9}
.c-inactive{opacity:.65;background:#f5f5f2}
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
                                <span class="step-grip">⠿</span>
                                <input name="steps[]" value="<?= htmlspecialchars((string)$sName) ?>" placeholder="Adım adı">
                                <select name="step_targets[]">
                                    <option value="">Bölüm yok</option>
                                    <?php foreach (['sec-01' => 'Temel bilgiler', 'sec-02' => 'Oda / birim', 'sec-03' => 'Olanaklar & hizmetler', 'sec-04' => 'Envanter & fiyat', 'sec-05' => 'Görseller', 'sec-06' => 'Komisyon & tahsilat', 'sec-07' => 'İptal & iade'] as $v => $l): ?>
                                        <option value="<?= $v ?>" <?= ($tList[$si] ?? '') === $v ? 'selected' : '' ?>><?= $l ?> (<?= $v ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="step-mv" data-dir="up">↑</button>
                                <button type="button" class="step-mv" data-dir="down">↓</button>
                                <button type="button" class="step-del" onclick="this.parentElement.remove()">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="step-add" onclick="var c=document.getElementById('steps-<?= htmlspecialchars($x['code']) ?>');var d=document.createElement('div');d.className='step-row';d.innerHTML='<span class=\"step-grip\">⠿</span><input name=\"steps[]\" placeholder=\"Adım adı\"><select name=\"step_targets[]\"><option value=\"\">Bölüm yok</option><option value=\"sec-01\">Temel bilgiler (sec-01)</option><option value=\"sec-02\">Oda / birim (sec-02)</option><option value=\"sec-03\">Olanaklar & hizmetler (sec-03)</option><option value=\"sec-04\">Envanter & fiyat (sec-04)</option><option value=\"sec-05\">Görseller (sec-05)</option><option value=\"sec-06\">Komisyon & tahsilat (sec-06)</option><option value=\"sec-07\">İptal & iade (sec-07)</option></select><button type=\"button\" class=\"step-mv\" data-dir=\"up\">↑</button><button type=\"button\" class=\"step-mv\" data-dir=\"down\">↓</button><button type=\"button\" class=\"step-del\" onclick=\"this.parentElement.remove()\">×</button>';d.draggable=true;c.appendChild(d);window.nexusStepsRefresh&&nexusStepsRefresh(c)">+ Adım Ekle</button>
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
window.nexusStepsRefresh=function(wrap){var rows=wrap.querySelectorAll('.step-row');rows.forEach(function(r,i){var u=r.querySelector('.step-mv[data-dir="up"]'),d=r.querySelector('.step-mv[data-dir="down"]');if(u)u.disabled=i===0;if(d)d.disabled=i===rows.length-1;})};
document.addEventListener('click',function(e){var mv=e.target.closest?e.target.closest('.step-mv'):null;if(mv){e.preventDefault();var wrap=mv.closest('.steps-editor');if(!wrap)return;var row=mv.closest('.step-row'),dir=mv.getAttribute('data-dir'),sib=dir==='up'?row.previousElementSibling:row.nextElementSibling;if(!sib)return;if(dir==='up')wrap.insertBefore(row,sib);else wrap.insertBefore(sib,row);window.nexusStepsRefresh(wrap);return;}var dl=e.target.closest?e.target.closest('.step-del'):null;if(dl){var w2=dl.closest('.steps-editor');if(w2)window.nexusStepsRefresh(w2);}});
</script>

<?php 
require_once __DIR__ . '/../config/ai_widget.php'; 
ai_widget('/nexustraveltech/admin/ai-chat', 'admin_csrf'); 
admin_layout_end(); 
?>

