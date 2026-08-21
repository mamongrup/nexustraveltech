<?php
declare(strict_types=1);
require __DIR__.'/../config/auth.php';
require __DIR__.'/../config/database.php';
require __DIR__.'/../config/platform_settings.php';
require __DIR__.'/../config/i18n.php';
require __DIR__.'/../config/chat_topics.php';
require __DIR__.'/../config/audit.php';

// Manuel cop kutusu temizleme handler
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['purge_trash_action']??'')==='1' && hash_equals($_SESSION['admin_csrf']??'', (string)($_POST['csrf']??''))) {
    require_once __DIR__.'/../config/feature_lists.php';
    $pTtl=max(7,(int)platform_setting('feature_trash_ttl_days',30));
    $stale=db()->query("SELECT id,label FROM property_feature_catalog WHERE deleted_at IS NOT NULL AND ((purge_at IS NOT NULL AND purge_at<=now()) OR (purge_at IS NULL AND deleted_at<now()-interval '{$pTtl} days')) ORDER BY deleted_at")->fetchAll();
    $purged=0; $names=[];
    foreach($stale as $ps){
        $fid=(int)$ps['id'];
        db()->prepare('DELETE FROM feature_delete_backups WHERE feature_id=?')->execute([$fid]);
        db()->prepare('DELETE FROM pending_trash_purges WHERE feature_id=?')->execute([$fid]);
        db()->prepare('DELETE FROM property_feature_catalog WHERE id=?')->execute([$fid]);
        $purged++; $names[]=(string)$ps['label'];
    }
    if($purged>0){audit_log('feature.trash_purge','feature_catalog',null,['count'=>$purged,'feature_names'=>array_slice($names,0,50),'trigger'=>'manual_panel']);$_SESSION['purge_result']=$purged.' ozellik silindi: '.implode(', ',array_slice($names,0,10)).(count($names)>10?' ...':'');}
    else $_SESSION['purge_result']='Kalici silinecek ozellik yok.';
    header('Location: /nexustraveltech/admin/kontrol-merkezi#trash-section'); exit;
}

function audit_saved_setting(string $key, mixed $new): void {$old=platform_setting($key,null);$a=json_encode($old,JSON_UNESCAPED_UNICODE);$b=json_encode($new,JSON_UNESCAPED_UNICODE);if($a!==$b)audit_log('platform.setting_change','platform_settings',null,['key'=>$key,'old'=>$old,'new'=>$new]);}

require_admin();
if(empty($_SESSION['admin_csrf']))$_SESSION['admin_csrf']=bin2hex(random_bytes(32));

$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['admin_csrf'],(string)($_POST['csrf']??'')))
        $error='Güvenlik doğrulaması geçersiz.';
    elseif(($_POST['digest_remove']??'')!==''){
        $parts=explode(':',(string)$_POST['digest_remove'],2);
        $digest=(array)platform_setting('panel_weekly_digest',[]);
        if(isset($parts[1])&&in_array($parts[0],['supplier','agency'],true)){
            $digestOld=$digest;unset($digest[$parts[0]][$parts[1]]);
            save_platform_setting('panel_weekly_digest',$digest);
            audit_log('platform.setting_change','platform_settings',null,['key'=>'panel_weekly_digest','old'=>$digestOld,'new'=>$digest]);
            $message='Katılımcı haftalık özetten çıkarıldı.';
        }else{$error='Katılımcı kaldırma isteği geçersiz.';}
    }else{
        // Tüm ayar kaydetme işlemleri (mevcut kod aynen korunur)
        $threshold=(int)($_POST['gemini_visual_similarity_threshold']??90);
        $email=trim((string)($_POST['admin_alert_email']??''));
        if($threshold<50||$threshold>100)$error='Görsel benzerlik eşiği 50 ile 100 arasında olmalıdır.';
        elseif($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$error='Uyarı e-posta adresi geçerli değil.';
        else{
            save_platform_setting('gemini_visual_similarity_threshold',$threshold);
            audit_saved_setting('gemini_visual_similarity_threshold',$threshold);
            save_platform_setting('gemini_auto_pause_duplicate',isset($_POST['gemini_auto_pause_duplicate']));
            audit_saved_setting('gemini_auto_pause_duplicate',isset($_POST['gemini_auto_pause_duplicate']));
            save_platform_setting('kps_identity_verification_enabled',isset($_POST['kps_identity_verification_enabled']));
            audit_saved_setting('kps_identity_verification_enabled',isset($_POST['kps_identity_verification_enabled']));
            save_platform_setting('admin_alert_email',$email);
            audit_saved_setting('admin_alert_email',$email);
            $blocklistRaw=(string)($_POST['chat_blocklist']??'');
            $blocklist=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$blocklistRaw)),fn($l)=>$l!==''));
            save_platform_setting('chat_blocklist',$blocklist);
            audit_saved_setting('chat_blocklist',$blocklist);
            $minLenRaw=(int)($_POST['chat_min_length']??5);
            $minLenRaw=max(1,min(100,$minLenRaw));
            save_platform_setting('chat_min_length',$minLenRaw);
            audit_saved_setting('chat_min_length',$minLenRaw);
            save_platform_setting('chat_require_space',isset($_POST['chat_require_space']));
            audit_saved_setting('chat_require_space',isset($_POST['chat_require_space']));
            save_platform_setting('chat_topic_instant',isset($_POST['chat_topic_instant']));
            audit_saved_setting('chat_topic_instant',isset($_POST['chat_topic_instant']));
            $topicRaw=(array)($_POST['chat_topic_responses']??[]);
            $topicSaved=[];
            foreach(array_keys(chat_topic_defs()) as $t){
                $text=trim((string)($topicRaw[$t]['text']??''));
                $link=trim((string)($topicRaw[$t]['link']??''));
                if($text===''&&$link==='')continue;
                $topicSaved[$t]=['text'=>mb_substr($text,0,500),'link'=>mb_substr($link,0,190)];
            }
            save_platform_setting('chat_topic_responses',$topicSaved);
            audit_saved_setting('chat_topic_responses',$topicSaved);
            save_platform_setting('ical_url_published_only',isset($_POST['ical_url_published_only']));
            audit_saved_setting('ical_url_published_only',isset($_POST['ical_url_published_only']));
            $webhookCurrency=strtoupper(trim((string)($_POST['channel_webhook_default_currency']??'EUR')));
            if(!preg_match('/^[A-Z]{3}$/',$webhookCurrency))$webhookCurrency='EUR';
            save_platform_setting('channel_webhook_default_currency',$webhookCurrency);
            audit_saved_setting('channel_webhook_default_currency',$webhookCurrency);
            save_platform_setting('channel_webhook_auto_map',isset($_POST['channel_webhook_auto_map']));
            audit_saved_setting('channel_webhook_auto_map',isset($_POST['channel_webhook_auto_map']));
            save_platform_setting('supplier_notify_email',isset($_POST['supplier_notify_email']));
            audit_saved_setting('supplier_notify_email',isset($_POST['supplier_notify_email']));
            save_platform_setting('orphan_cleanup_require_password',isset($_POST['orphan_cleanup_require_password']));
            audit_saved_setting('orphan_cleanup_require_password',isset($_POST['orphan_cleanup_require_password']));
            $webhookLoopThreshold=max(3,min(100,(int)($_POST['channel_webhook_loop_threshold']??3)));
            save_platform_setting('channel_webhook_loop_threshold',$webhookLoopThreshold);
            audit_saved_setting('channel_webhook_loop_threshold',$webhookLoopThreshold);
            $icalRepeatThreshold=max(3,min(100,(int)($_POST['ical_repeat_threshold']??3)));
            save_platform_setting('ical_repeat_threshold',$icalRepeatThreshold);
            audit_saved_setting('ical_repeat_threshold',$icalRepeatThreshold);
            save_platform_setting('ical_auto_pause_repeat',isset($_POST['ical_auto_pause_repeat']));
            audit_saved_setting('ical_auto_pause_repeat',isset($_POST['ical_auto_pause_repeat']));
            $webhookMaxRetries=max(2,min(10,(int)($_POST['channel_webhook_max_retries']??3)));
            save_platform_setting('channel_webhook_max_retries',$webhookMaxRetries);
            audit_saved_setting('channel_webhook_max_retries',$webhookMaxRetries);
            $simThreshold=max(1,min(100,(int)($_POST['channel_webhook_similarity_threshold']??45)));
            save_platform_setting('channel_webhook_similarity_threshold',$simThreshold);
            audit_saved_setting('channel_webhook_similarity_threshold',$simThreshold);
            $trashTtl=max(7,min(365,(int)($_POST['feature_trash_ttl_days']??30)));
            save_platform_setting('feature_trash_ttl_days',$trashTtl);
            audit_saved_setting('feature_trash_ttl_days',$trashTtl);
            $warnDays=max(1,min(30,(int)($_POST['trash_upcoming_warning_days']??3)));
            save_platform_setting('trash_upcoming_warning_days',$warnDays);
            audit_saved_setting('trash_upcoming_warning_days',$warnDays);
            save_platform_setting('readiness_all_auto_open',isset($_POST['readiness_all_auto_open']));
            audit_saved_setting('readiness_all_auto_open',isset($_POST['readiness_all_auto_open']));
            $readinessThreshold=max(1,min(100,(int)($_POST['readiness_all_auto_open_threshold']??70)));
            save_platform_setting('readiness_all_auto_open_threshold',$readinessThreshold);
            audit_saved_setting('readiness_all_auto_open_threshold',$readinessThreshold);
            $healthWarnLog=max(5,min(500,(int)($_POST['health_warn_error_logs']??20)));
            save_platform_setting('health_warn_error_logs',$healthWarnLog);
            audit_saved_setting('health_warn_error_logs',$healthWarnLog);
            $healthWarnEmail=max(5,min(1000,(int)($_POST['health_warn_email_queue']??50)));
            save_platform_setting('health_warn_email_queue',$healthWarnEmail);
            audit_saved_setting('health_warn_email_queue',$healthWarnEmail);
            $healthWarnWebhook=max(1,min(200,(int)($_POST['health_warn_webhook_fail']??10)));
            save_platform_setting('health_warn_webhook_fail',$healthWarnWebhook);
            audit_saved_setting('health_warn_webhook_fail',$healthWarnWebhook);
            $healthWarnIcal=max(1,min(100,(int)($_POST['health_warn_ical_fail']??3)));
            save_platform_setting('health_warn_ical_fail',$healthWarnIcal);
            audit_saved_setting('health_warn_ical_fail',$healthWarnIcal);
            $sugTtl=max(7,min(365,(int)($_POST['channel_suggestion_ttl_days']??30)));
            save_platform_setting('channel_suggestion_ttl_days',$sugTtl);
            audit_saved_setting('channel_suggestion_ttl_days',$sugTtl);
            $tooltipLangRaw=strtolower(trim((string)($_POST['tooltip_language']??'tr')));
            $tooltipLang=in_array($tooltipLangRaw,['tr','en','de','ru','ar','fr'],true)?$tooltipLangRaw:'tr';
            save_platform_setting('tooltip_language',$tooltipLang);
            audit_saved_setting('tooltip_language',$tooltipLang);
            $occCrit=max(50,min(100,(int)($_POST['inventory_occ_critical']??90)));
            save_platform_setting('inventory_occ_critical',$occCrit);
            audit_saved_setting('inventory_occ_critical',$occCrit);
            $occWarn=max(10,min(99,(int)($_POST['inventory_occ_warn']??70)));
            save_platform_setting('inventory_occ_warn',$occWarn);
            audit_saved_setting('inventory_occ_warn',$occWarn);
            foreach(['rooms','rates','inventory','media','description','location','channel','pool','home_port','crew','ical'] as $wk){
                $wv=max(1,min(10,(int)($_POST['readiness_weight_'.$wk]??0)));
                if($wv>0){save_platform_setting('readiness_weight_'.$wk,$wv);audit_saved_setting('readiness_weight_'.$wk,$wv);}
            }
            $message='Kontrol merkezi ayarları kaydedildi.';
        }
    }
}

$threshold=(int)platform_setting('gemini_visual_similarity_threshold',90);
$autoPause=(bool)platform_setting('gemini_auto_pause_duplicate',true);
$kpsEnabled=(bool)platform_setting('kps_identity_verification_enabled',false);
$email=(string)platform_setting('admin_alert_email','');
$icalPublishedOnly=(bool)platform_setting('ical_url_published_only',false);

require_once __DIR__.'/layout.php';
admin_layout_start('Platform Kontrol Merkezi', 'kontrol-merkezi');
?>

<?php $purgeMsg=$_SESSION['purge_result']??''; unset($_SESSION['purge_result']); if($purgeMsg): ?>
<div class="sui-alert success">🧹 <b>Çöp kutusu temizleme:</b> <?= htmlspecialchars($purgeMsg) ?></div>
<?php endif; ?>

<?php if($message): ?><div class="sui-alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if($error): ?><div class="sui-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="post" class="sui-card">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
    
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">⚙️ Platform Ayarları</h2>
            <p class="sui-card-subtitle">İş kuralları veritabanında tutulur ve kod değiştirmeden yönetilir.</p>
        </div>
    </div>

    <div class="sui-grid-2" style="margin-bottom:20px">
        <div class="sui-form-group">
            <label class="sui-label">Gemini Görsel Benzerlik Eşiği (%)</label>
            <input type="number" name="gemini_visual_similarity_threshold" min="50" max="100" value="<?= $threshold ?>" class="sui-input">
        </div>
        <div class="sui-form-group">
            <label class="sui-label">Yönetici Uyarı E-postası</label>
            <input type="email" name="admin_alert_email" value="<?= htmlspecialchars($email) ?>" placeholder="operasyon@sirketiniz.com" class="sui-input">
        </div>
    </div>

    <div class="sui-grid-2" style="margin-bottom:20px">
        <label class="sui-toggle"><input type="checkbox" name="gemini_auto_pause_duplicate" <?= $autoPause?'checked':''?>> Gemini yüksek eşleşmede ilanı otomatik duraklatsın</label>
        <label class="sui-toggle"><input type="checkbox" name="kps_identity_verification_enabled" <?= $kpsEnabled?'checked':''?>> KPS kimlik doğrulama entegrasyonu aktif</label>
        <label class="sui-toggle"><input type="checkbox" name="ical_url_published_only" <?= $icalPublishedOnly?'checked':''?>> iCal URL yalnızca yayındaki ilanlarda</label>
        <label class="sui-toggle"><input type="checkbox" name="supplier_notify_email" <?=(bool)platform_setting('supplier_notify_email',false)?'checked':''?>> Öneri bildirimlerini e-posta ile de gönder</label>
        <label class="sui-toggle"><input type="checkbox" name="orphan_cleanup_require_password" <?=(bool)platform_setting('orphan_cleanup_require_password',false)?'checked':''?>> Yetim temizlemede admin parolası iste</label>
        <label class="sui-toggle"><input type="checkbox" name="channel_webhook_auto_map" <?=(bool)platform_setting('channel_webhook_auto_map',true)?'checked':''?>> Tanınmayan kod gelince onay bekleyen öneri oluştur</label>
    </div>

    <!-- Hazırlık Ağırlıkları -->
    <div class="sui-section-group">
        <p class="sui-section-group-title">⚖ Hazırlık Ağırlık Tablosu</p>
        <p class="sui-section-group-desc">Her kalemin hesaplamadaki ham puanı. Toplam 100'e normalize edilir.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
            <?php foreach(['rooms'=>'Oda envanteri','rates'=>'Fiyat','inventory'=>'Stok','media'=>'Görseller','description'=>'Satış içeriği','location'=>'Konum','channel'=>'Kanal','pool'=>'Havuz','home_port'=>'Liman','crew'=>'Mürettebat','ical'=>'iCal'] as $wk=>$wl): ?>
            <div class="sui-form-group" style="margin:0">
                <label class="sui-label" style="font-size:11px"><?= $wl ?></label>
                <input type="number" name="readiness_weight_<?= $wk ?>" min="1" max="10" value="<?= (int) platform_setting('readiness_weight_'.$wk, 3) ?>" class="sui-input" style="padding:7px 10px;font-size:12px">
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Webhook Ayarları -->
    <div class="sui-section-group">
        <p class="sui-section-group-title">🔄 Webhook & Kanal Ayarları</p>
        <div class="sui-grid-2">
            <div class="sui-form-group">
                <label class="sui-label">Varsayılan Para Birimi</label>
                <input name="channel_webhook_default_currency" maxlength="3" value="<?= htmlspecialchars((string)platform_setting('channel_webhook_default_currency','EUR')) ?>" class="sui-input" style="text-transform:uppercase">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Benzerlik Eşiği (%)</label>
                <input type="number" name="channel_webhook_similarity_threshold" min="1" max="100" value="<?= (int)platform_setting('channel_webhook_similarity_threshold',45) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Döngü Uyarı Eşiği</label>
                <input type="number" name="channel_webhook_loop_threshold" min="3" max="100" value="<?= (int)platform_setting('channel_webhook_loop_threshold',3) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Maks Yeniden Deneme</label>
                <input type="number" name="channel_webhook_max_retries" min="2" max="10" value="<?= (int)platform_setting('channel_webhook_max_retries',3) ?>" class="sui-input">
            </div>
        </div>
    </div>

    <!-- iCal Ayarları -->
    <div class="sui-section-group">
        <p class="sui-section-group-title">📅 iCal Ayarları</p>
        <div class="sui-grid-2">
            <div class="sui-form-group">
                <label class="sui-label">Tekrar Hata Eşiği</label>
                <input type="number" name="ical_repeat_threshold" min="3" max="100" value="<?= (int)platform_setting('ical_repeat_threshold',3) ?>" class="sui-input">
            </div>
            <label class="sui-toggle"><input type="checkbox" name="ical_auto_pause_repeat" <?=(bool)platform_setting('ical_auto_pause_repeat',false)?'checked':''?>> Tekrar eşiği aşılınca otomatik duraklat</label>
        </div>
    </div>

    <!-- Sağlık Kontrolü Eşikleri -->
    <div class="sui-section-group">
        <p class="sui-section-group-title">🩺 Sağlık Kontrolü Uyarı Eşikleri</p>
        <p class="sui-section-group-desc">Son 24 saatteki sayaçlar bu eşiği aşarsa uyarı e-postası gider.</p>
        <div class="sui-grid-2">
            <div class="sui-form-group">
                <label class="sui-label">Hata Logu Eşiği</label>
                <input type="number" name="health_warn_error_logs" min="5" max="500" value="<?= (int)platform_setting('health_warn_error_logs',20) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">E-posta Kuyruğu Eşiği</label>
                <input type="number" name="health_warn_email_queue" min="5" max="1000" value="<?= (int)platform_setting('health_warn_email_queue',50) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Başarısız Webhook Eşiği</label>
                <input type="number" name="health_warn_webhook_fail" min="1" max="200" value="<?= (int)platform_setting('health_warn_webhook_fail',10) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">iCal Hata Eşiği</label>
                <input type="number" name="health_warn_ical_fail" min="1" max="100" value="<?= (int)platform_setting('health_warn_ical_fail',3) ?>" class="sui-input">
            </div>
        </div>
    </div>

    <!-- Çöp Kutusu -->
    <div class="sui-section-group">
        <p class="sui-section-group-title">🗑 Çöp Kutusu</p>
        <div class="sui-grid-2">
            <div class="sui-form-group">
                <label class="sui-label">TTL (gün)</label>
                <input type="number" name="feature_trash_ttl_days" min="7" max="365" value="<?= (int)platform_setting('feature_trash_ttl_days',30) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Uyarı Penceresi (gün)</label>
                <input type="number" name="trash_upcoming_warning_days" min="1" max="30" value="<?= (int)platform_setting('trash_upcoming_warning_days',3) ?>" class="sui-input">
            </div>
        </div>
        <?php
        $trashStats=null;
        try{$pdoT=db();$trashStats=['count'=>(int)$pdoT->query('SELECT COUNT(*) FROM property_feature_catalog WHERE deleted_at IS NOT NULL')->fetchColumn()];
        $oldQ=$pdoT->query('SELECT label,deleted_at,purge_at FROM property_feature_catalog WHERE deleted_at IS NOT NULL ORDER BY deleted_at ASC LIMIT 1');$old=$oldQ->fetch();
        if($old){$ttlTmp=max(7,(int)platform_setting('feature_trash_ttl_days',30));$dTs=strtotime((string)$old['deleted_at'])?:time();$custom=!empty($old['purge_at']);$pTs=$custom?(strtotime((string)$old['purge_at'])?:0):0;if($pTs<=0)$pTs=$dTs+$ttlTmp*86400;$trashStats['oldest']=['label'=>(string)$old['label'],'deleted_at'=>(string)$old['deleted_at'],'purge_date'=>date('Y-m-d',$pTs),'remain_days'=>max(0,(int)ceil(($pTs-time())/86400)),'custom'=>$custom];}}catch(Throwable $e){$trashStats=null;}
        ?>
        <?php if($trashStats!==null && (int)$trashStats['count'] > 0): ?>
        <div class="sui-alert warning" style="margin-top:8px">
            🗑 <b><?= (int)$trashStats['count'] ?> özellik</b> bekliyor · en eskisi: <b><?= htmlspecialchars((string)($trashStats['oldest']['label']??'')) ?></b>
            <small>(silindi <?= htmlspecialchars(mb_substr((string)($trashStats['oldest']['deleted_at']??''),0,10)) ?> · <?= (int)($trashStats['oldest']['remain_days']??0) ?> gün kaldı)</small>
            · <a href="/nexustraveltech/admin/ozellik-listeleri#trash" style="color:#1a5e0a;font-weight:bold;text-decoration:none">kataloğa git →</a>
            <?php if((int)($trashStats['oldest']['remain_days']??99)<=0): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Vadesi dolan özellikleri kalıcı olarak silecektir. Emin misiniz?')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                <input type="hidden" name="purge_trash_action" value="1">
                <button class="sui-btn sui-btn-danger sui-btn-xs" style="margin-left:8px">🧹 Şimdi temizle</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Hazırlık & Sağlık -->
    <div class="sui-section-group">
        <p class="sui-section-group-title">📋 Hazırlık & Sağlık</p>
        <div class="sui-grid-2">
            <label class="sui-toggle"><input type="checkbox" name="readiness_all_auto_open" <?=(bool)platform_setting('readiness_all_auto_open',false)?'checked':''?>> Düşük skorda tüm kalemler otomatik açık</label>
            <div class="sui-form-group">
                <label class="sui-label">Otomatik Açık Eşiği</label>
                <input type="number" name="readiness_all_auto_open_threshold" min="1" max="100" value="<?= (int)platform_setting('readiness_all_auto_open_threshold',70) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Öneri TTL (gün)</label>
                <input type="number" name="channel_suggestion_ttl_days" min="7" max="365" value="<?= (int)platform_setting('channel_suggestion_ttl_days',30) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Doluluk Eşiği — Kritik (%)</label>
                <input type="number" name="inventory_occ_critical" min="50" max="100" value="<?= (int)platform_setting('inventory_occ_critical',90) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Doluluk Eşiği — Uyarı (%)</label>
                <input type="number" name="inventory_occ_warn" min="10" max="99" value="<?= (int)platform_setting('inventory_occ_warn',70) ?>" class="sui-input">
            </div>
            <div class="sui-form-group">
                <label class="sui-label">Arayüz Dili (ipuçları)</label>
                <select name="tooltip_language" class="sui-select">
                    <?php $tl=readiness_lang(); foreach(['tr'=>'Türkçe','en'=>'English','de'=>'Deutsch','ru'=>'Русский','ar'=>'العربية','fr'=>'Français'] as $tlCode=>$tlName): ?>
                    <option value="<?= $tlCode ?>" <?= $tl===$tlCode?'selected':''?>><?= $tlName ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Sohbet Ayarları -->
    <div class="sui-section-group">
        <p class="sui-section-group-title">💬 Sohbet Ayarları</p>
        <div class="sui-grid-2">
            <div class="sui-form-group">
                <label class="sui-label">Yasak Kelimeler <small>(her satıra bir tane)</small></label>
                <textarea name="chat_blocklist" rows="3" class="sui-textarea" style="font-family:monospace;font-size:12px"><?= htmlspecialchars(implode("\n",(array)platform_setting('chat_blocklist',[]))) ?></textarea>
            </div>
            <div>
                <div class="sui-form-group">
                    <label class="sui-label">Min Soru Uzunluğu</label>
                    <input type="number" name="chat_min_length" min="1" max="100" value="<?= (int)platform_setting('chat_min_length',5) ?>" class="sui-input">
                </div>
                <label class="sui-toggle"><input type="checkbox" name="chat_require_space" <?=(bool)platform_setting('chat_require_space',true)?'checked':''?>> Tek kelimeli soruları engelle</label>
                <label class="sui-toggle"><input type="checkbox" name="chat_topic_instant" <?=(bool)platform_setting('chat_topic_instant',true)?'checked':''?>> Konuya göre anında yanıtlar aktif</label>
            </div>
        </div>
    </div>

    <div style="text-align:right;margin-top:20px">
        <button class="sui-btn sui-btn-primary"><i class="fas fa-save"></i> Kuralları Kaydet</button>
    </div>
</form>

<!-- Dağıtım Sağlığı Özeti -->
<?php
$dmOrphan=0;$dmPending=0;$dmPlanMissing=0;
try{$p=db();
if((bool)$p->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='channel_room_mappings'")->fetchColumn()){
$dmOrphan=(int)$p->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN room_types rt ON rt.id=m.room_type_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.room_type_id>0 AND (rt.id IS NULL OR c.id IS NULL OR rt.property_id<>m.property_id OR (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)))")->fetchColumn();
$dmOrphan+=(int)$p->query("SELECT COUNT(*) FROM channel_rate_plan_mappings m LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE (m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.property_id<>m.property_id)) OR c.id IS NULL")->fetchColumn();
$dmOrphan+=(int)$p->query("SELECT COUNT(*) FROM channel_property_mappings m LEFT JOIN properties p ON p.id=m.property_id LEFT JOIN channel_connections c ON c.id=m.channel_connection_id WHERE p.id IS NULL OR c.id IS NULL")->fetchColumn();}
if((bool)$p->query("SELECT 1 FROM pg_tables WHERE schemaname='public' AND tablename='ical_connections'")->fetchColumn()){$dmOrphan+=(int)$p->query("SELECT COUNT(*) FROM ical_connections c LEFT JOIN properties p ON p.id=c.property_id WHERE p.id IS NULL")->fetchColumn();}
$pr=$p->query("SELECT COALESCE(SUM(rs),0) AS rs,COALESCE(SUM(ps),0) AS ps FROM (SELECT supplier_id,COUNT(*) FILTER(WHERE status='suggested' AND room_type_id>0) AS rs,COUNT(*) FILTER(WHERE status='suggested' AND room_type_id IS NULL) AS ps FROM channel_room_mappings GROUP BY supplier_id UNION ALL SELECT supplier_id,COUNT(*) FILTER(WHERE status='suggested') AS rs,0 AS ps FROM channel_rate_plan_mappings GROUP BY supplier_id) sub")->fetch();
if($pr){$dmPending=(int)$pr['rs']+(int)$pr['ps'];}
$pm=$p->query("SELECT COUNT(*) FROM channel_room_mappings m LEFT JOIN rate_plans rp ON rp.id=m.rate_plan_id WHERE m.rate_plan_id IS NOT NULL AND (rp.id IS NULL OR rp.status<>'active')")->fetchColumn();
$dmPlanMissing=(int)($pm?:0);}catch(Throwable $e){}
$pw=date('o-W',time()-7*86400);
$oh=(array)platform_setting('distribution_health_orphan_history',[]);
$ph=(array)platform_setting('distribution_health_pending_history',[]);
$pmh=(array)platform_setting('distribution_health_plan_missing_history',[]);
function _tl(?int $p,int $c):string{if($p===null)return'';$d=$c-$p;if($d>0)return' <span style="color:#f31260;font-size:11px">▲+'.$d.'</span>';if($d<0)return' <span style="color:#17c964;font-size:11px">▼'.$d.'</span>';return' <span style="color:#6b7280;font-size:11px">=</span>';}
?>

<div class="sui-card">
    <div class="sui-card-header">
        <div>
            <h2 class="sui-card-title">📊 Dağıtım Sağlığı Özeti</h2>
            <p class="sui-card-subtitle">Son haftalık dağıtım sağlık özetindeki değerler</p>
        </div>
        <a href="/nexustraveltech/admin/orphan-mappings" class="sui-btn sui-btn-outline sui-btn-sm">Yönetim →</a>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php if($dmOrphan>0): ?>
        <a href="/nexustraveltech/admin/orphan-mappings" class="sui-badge red" style="text-decoration:none;padding:8px 14px;font-size:13px">🧹 Yetim: <?= $dmOrphan ?><?= _tl($oh[$pw]??null,$dmOrphan) ?></a>
        <?php endif; ?>
        <?php if($dmPending>0): ?>
        <a href="/nexustraveltech/admin/orphan-mappings" class="sui-badge yellow" style="text-decoration:none;padding:8px 14px;font-size:13px">⏳ Öneri: <?= $dmPending ?><?= _tl($ph[$pw]??null,$dmPending) ?></a>
        <?php endif; ?>
        <?php if($dmPlanMissing>0): ?>
        <a href="/nexustraveltech/admin/orphan-mappings" class="sui-badge yellow" style="text-decoration:none;padding:8px 14px;font-size:13px">⚠ Plan eksik: <?= $dmPlanMissing ?><?= _tl($pmh[$pw]??null,$dmPlanMissing) ?></a>
        <?php endif; ?>
        <?php if($dmOrphan===0 && $dmPending===0 && $dmPlanMissing===0): ?>
        <span class="sui-badge green">✓ Tüm eşleştirmeler tutarlı</span>
        <?php endif; ?>
    </div>
</div>

<!-- Kritik Uyarılar -->
<?php
$critItems = [];
try {
    $lockCheck = db()->prepare("SELECT pid, state_change, now()-state_change AS age FROM pg_locks l JOIN pg_stat_activity a ON a.pid=l.pid WHERE l.locktype='advisory' AND l.classid=0 AND l.objid=424242 AND l.granted=true AND a.pid<>pg_backend_pid()");
    $lockCheck->execute(); $lockRow = $lockCheck->fetch();
    if ($lockRow) { $critItems[] = ['icon'=>'🔒','label'=>'Advisory Kilit','value'=>'Tutuluyor (PID '.(int)$lockRow['pid'].')','ok'=>false]; }
    else { $critItems[] = ['icon'=>'🔒','label'=>'Advisory Kilit','value'=>'Serbest','ok'=>true]; }
} catch (Throwable $e) { $critItems[] = ['icon'=>'🔒','label'=>'Advisory Kilit','value'=>'Kontrol edilemedi','ok'=>false]; }

$migTotal = (int) db()->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
$critCols = db()->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='public' AND (table_name='channel_room_mappings' AND column_name='channel_connection_id' OR table_name='channel_rate_plan_mappings' AND column_name='channel_connection_id' OR table_name='property_feature_catalog' AND column_name='previous_purge_at')")->fetchAll();
$migFail = count($critCols);
$critItems[] = ['icon'=>'📊','label'=>'Migration','value'=>$migTotal.($migFail > 0 ? ' · '.$migFail.' eksik' : ''),'ok'=>$migFail===0 && $migTotal>=55];

$tokBad = (int) db()->query("SELECT COUNT(*) FROM channel_connections WHERE status='active' AND (access_token IS NULL OR access_token='')")->fetchColumn();
$critItems[] = ['icon'=>'🔑','label'=>'Kanal Tokenı','value'=>$tokBad > 0 ? $tokBad.' eksik' : 'Tümü tamam','ok'=>$tokBad===0];

$errCount = (int) db()->query("SELECT COUNT(*) FROM error_logs WHERE level IN ('error','critical') AND created_at >= now()-interval '24 hours'")->fetchColumn();
$critItems[] = ['icon'=>'⚠️','label'=>'Hata Logu (24s)',$errCount.' hata','ok'=>$errCount < (int)platform_setting('health_warn_error_logs',20)];

$emailQ = (int) db()->query("SELECT COUNT(*) FROM email_outbox WHERE status='pending'")->fetchColumn();
$critItems[] = ['icon'=>'📧','label'=>'E-posta Kuyruğu',$emailQ.' bekliyor','ok'=>$emailQ < (int)platform_setting('health_warn_email_queue',50)];

$whFail = (int) db()->query("SELECT COUNT(*) FROM channel_sync_logs WHERE status='failed' AND direction='pull' AND created_at>=now()-interval '24 hours'")->fetchColumn();
$critItems[] = ['icon'=>'🔄','label'=>'Webhook Hata (24s)',$whFail.' başarısız','ok'=>$whFail < (int)platform_setting('health_warn_webhook_fail',10)];

$icalFail = (int) db()->query("SELECT COUNT(*) FROM ical_sync_logs WHERE status='failed' AND created_at>=now()-interval '24 hours'")->fetchColumn();
$critItems[] = ['icon'=>'📅','label'=>'iCal Hata (24s)',$icalFail.' hata','ok'=>$icalFail < (int)platform_setting('health_warn_ical_fail',3)];

$pausedIcal = (int) db()->query("SELECT COUNT(*) FROM ical_connections WHERE status='paused'")->fetchColumn();
$critItems[] = ['icon'=>'⏸','label'=>'Duraklatılmış iCal',$pausedIcal.' bağlantı','ok'=>$pausedIcal===0];

$totalPending = (int) db()->query("SELECT COUNT(*) FROM channel_room_mappings WHERE status='suggested'")->fetchColumn() + (int) db()->query("SELECT COUNT(*) FROM channel_rate_plan_mappings WHERE status='suggested'")->fetchColumn();
$critItems[] = ['icon'=>'🛏','label'=>'Onay Bekleyen',$totalPending.' öneri','ok'=>$totalPending===0];

$trashCount = (int) db()->query("SELECT COUNT(*) FROM property_feature_catalog WHERE deleted_at IS NOT NULL")->fetchColumn();
$critItems[] = ['icon'=>'🗑','label'=>'Çöp Kutusu',$trashCount.' özellik','ok'=>$trashCount===0];
?>

<div class="sui-card" style="border-color:#f3c4ba;background:#fffbf9">
    <div class="sui-card-header">
        <h2 class="sui-card-title">🚨 Kritik Uyarılar</h2>
    </div>
    <div class="sui-stats" style="margin-bottom:0">
        <?php foreach ($critItems as $ci): ?>
        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:<?= $ci['ok'] ? '#e6f8c7' : '#fff3f1' ?>;border:1px solid <?= $ci['ok'] ? '#bcd98a' : '#f3c4ba' ?>;border-radius:var(--sui-radius-sm)">
            <span style="font-size:22px;flex-shrink:0"><?= $ci['icon'] ?></span>
            <div style="min-width:0">
                <div class="sui-stat-label"><?= htmlspecialchars($ci['label']) ?></div>
                <div style="font-size:14px;font-weight:700;color:<?= $ci['ok'] ? '#17c964' : '#f31260' ?>;margin-top:2px"><?= htmlspecialchars((string)$ci['value']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <p style="margin:14px 0 0;font-size:13px;font-weight:600;color:<?= empty(array_filter($critItems,fn($i)=>!$i['ok'])) ? '#17c964' : '#f31260' ?>">
        <?= empty(array_filter($critItems,fn($i)=>!$i['ok'])) ? '✓ Tüm sistemler normal.' : '⚠ Bazı sistemlerde sorun var.' ?>
    </p>
</div>

<!-- Bağlantılar -->
<div class="sui-card">
    <div class="sui-card-header">
        <h2 class="sui-card-title">⚡ Hızlı Erişim</h2>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="ai-ayarlari" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fa-solid fa-brain"></i> DeepSeek AI</a>
        <a href="gemini-ayarlari" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fa-solid fa-wand-magic-sparkles"></i> Gemini AI</a>
        <a href="tedarikci-onaylari" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fa-solid fa-clipboard-check"></i> Tedarikçi Onayları</a>
        <a href="ozellik-listeleri" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fa-solid fa-list-check"></i> Katalog Yönetimi</a>
        <a href="denetim-kayitlari" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fa-solid fa-shield-halved"></i> Denetim Kayıtları</a>
        <a href="timerlar" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fa-solid fa-stopwatch"></i> Zamanlayıcılar</a>
        <a href="dagitim-sagligi" class="sui-btn sui-btn-outline sui-btn-sm"><i class="fa-solid fa-network-wired"></i> Dağıtım Sağlığı</a>
    </div>
</div>

<?php admin_layout_end(); ?>
