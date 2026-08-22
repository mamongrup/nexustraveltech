<?php
require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/mapping_suggestions.php';
$supplier_user = require_supplier();
$active_module = $active_module ?? 'dashboard';
$notification_unread = 0;
try {
    $notification_unread = unread_notification_count('supplier', (int) $supplier_user['id']);
} catch (Throwable $e) {}
$GLOBALS['notification_unread'] = $notification_unread;
// Onay bekleyen eşleştirme önerileri (oda + fiyat planı) — bildirim rozetinde de görünür.
// Dağıtım merkezi bölüm 3'teki sayımın aynısı; webhook'ta tanınmayan kod geldiğinde
// tedarikçinin zilinde "N onay bekleyen" olarak hatırlatılır.
$pending_suggestions = 0;
$pending_sug_room = 0;
$pending_sug_plan = 0;
try {
    $pdo = db();
    $sq = $pdo->prepare("SELECT COUNT(*) FROM channel_room_mappings m JOIN channel_connections c ON c.id=m.channel_connection_id WHERE c.supplier_id=? AND m.status='suggested'");
    $sq->execute([(int) $supplier_user['supplier_id']]);
    $pending_sug_room = (int) $sq->fetchColumn();
    $pending_suggestions += $pending_sug_room;
    $pq = $pdo->prepare("SELECT COUNT(*) FROM channel_rate_plan_mappings p JOIN channel_connections c ON c.id=p.channel_connection_id WHERE c.supplier_id=? AND p.status='suggested'");
    $pq->execute([(int) $supplier_user['supplier_id']]);
    $pending_sug_plan = (int) $pq->fetchColumn();
    $pending_suggestions += $pending_sug_plan;
} catch (Throwable $e) {
    $pending_suggestions = 0;
    $pending_sug_room = 0;
    $pending_sug_plan = 0;
}
$GLOBALS['pending_suggestions'] = $pending_suggestions;
$GLOBALS['pending_sug_room'] = $pending_sug_room;
$GLOBALS['pending_sug_plan'] = $pending_sug_plan;
$pending_sug_age = 0;
if ($pending_suggestions > 0) {
    try {
        $osq = $pdo->prepare("SELECT MIN(suggested_at) FROM channel_room_mappings m JOIN channel_connections c ON c.id=m.channel_connection_id WHERE c.supplier_id=? AND m.status='suggested'");
        $osq->execute([(int) $supplier_user['supplier_id']]);
        $osr = $osq->fetchColumn();
        $opq = $pdo->prepare("SELECT MIN(suggested_at) FROM channel_rate_plan_mappings p JOIN channel_connections c ON c.id=p.channel_connection_id WHERE c.supplier_id=? AND p.status='suggested'");
        $opq->execute([(int) $supplier_user['supplier_id']]);
        $opr = $opq->fetchColumn();
        $oa = null;
        if ($osr && $opr) { $oa = $osr < $opr ? $osr : $opr; }
        elseif ($osr) { $oa = $osr; }
        elseif ($opr) { $oa = $opr; }
        if ($oa) { $pending_sug_age = (int) floor((time() - strtotime($oa)) / 86400); }
    } catch (Throwable $e) {}
}
$GLOBALS['pending_sug_age'] = $pending_sug_age;
function supply_start(string $title, string $active): void { ?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title)?> | NEXUS Supply</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/nexustraveltech/assets/supply.css"><script defer src="/nexustraveltech/assets/supply.js"></script><script defer src="/nexustraveltech/assets/copy-btn.js"></script></head><body><div class="supply-app"><aside class="side"><a class="supply-brand" href="/nexustraveltech/">N<span>∿</span>XUS <small>SUPPLY</small></a><nav>
<?php $plLabels=panel_labels(); $links=['dashboard'=>['',$plLabels['dashboard']],'matrix'=>['fiyat-matrisi','Fiyat Matrisi (Matrix)'],'pricing_coach'=>['pricing-coach','Pricing Coach (Otopilot)'],'widget_embed'=>['rezervasyon-widget','Rezervasyon Widget'],'hotel_checkin'=>['mobil-kimlik-okur','Mobil Kimlik Okur & Check-in'],'hotel_pos'=>['restoran-pos','Restoran & Bar POS'],'liox_finans'=>['liox-finans','LioX Finans & %2 Vergi'],'verification'=>['hesap-dogrulama',$plLabels['verification']],'properties'=>['tesisler',$plLabels['properties']],'inventory'=>['fiyat-kontenjan',$plLabels['inventory']],'rate_rules'=>['satis-kurallari',$plLabels['rate_rules']],'distribution'=>['dagitim-merkezi',$plLabels['distribution']],'ical'=>['ical-takvimler',$plLabels['ical']],'hotel_ops'=>['otel-operasyon','Otel ön büro'],'hotel_daily'=>['otel-gunluk','Günlük ön büro'],'calendar'=>['rezervasyon-takvimi','Rezervasyon takvimi'],'room_rack'=>['otel-room-rack','Room Rack & gruplar'],'hotel_team'=>['otel-ekip-hizmet','Kat hizmetleri & servis'],'hotel_control'=>['otel-kontrol','Otel kontrol & gün sonu'],'hotel_finance'=>['otel-finans','Finans & night audit'],'hotel_revenue'=>['otel-gelir-crm','Gelir, CRM & sadakat'],'agency_requests'=>['acente-talepleri','Acente talepleri'],'agency_bookings'=>['acente-rezervasyonlari','Acente rezervasyonları'],'groups'=>['grup-rezervasyonlari','Grup rezervasyonları'],'bookings'=>['rezervasyonlar','Rezervasyonlar'],'payments'=>['odeme-ayarlari','Tahsilat & POS'],'pay_links'=>['odeme-linkleri','Ödeme linkleri'],'invoices'=>['faturalama','Muhasebe & fatura'],'ai'=>['yapay-zeka','NEXUS AI'],'chat_report'=>['sohbet-raporu','Sohbet raporu']]; foreach($links as $key=>[$path,$label]): ?><a class="<?=$active===$key?'active':''?>" href="/nexustraveltech/tedarikci/<?=$path?>"><?=htmlspecialchars($label)?></a><?php endforeach; ?>
</nav><div class="side-foot"><a class="<?=$active==='hotel_guest_crm'?'active':''?>" href="/nexustraveltech/tedarikci/otel-misafir-crm">Misafir CRM</a><a class="<?=$active==='hotel_staff'?'active':''?>" href="/nexustraveltech/tedarikci/otel-personel">Personel & roller</a><a class="<?=$active==='hotel_mobile'?'active':''?>" href="/nexustraveltech/tedarikci/kat-hizmetleri-mobil">Mobil kat hizmetleri</a><a class="<?=$active==='reviews'?'active':''?>" href="/nexustraveltech/tedarikci/otel-yorumlar">Misafir değerlendirmeleri</a><a class="<?=$active==='compliance'?'active':''?>" href="/nexustraveltech/tedarikci/kimlik-bildirimi">Kimlik bildirimi</a><span>PİLOT ORTAM</span><a href="/nexustraveltech/tedarikci/2fa">İki adımlı doğrulama</a><a href="/nexustraveltech/tedarikci/ayarlar">Hesap ayarları</a><a href="/nexustraveltech/tedarikci/sifre-degistir">Şifre değiştir</a><a href="/nexustraveltech/tedarikci/logout">Güvenli çıkış</a></div></aside><main class="supply-main"><header class="supply-top"><div><span class="crumb">NEXUS SUPPLY / <?=strtoupper(htmlspecialchars($active))?></span><h1><?=htmlspecialchars($title)?></h1></div><div class="supply-top-actions"><a class="supply-bell" href="/nexustraveltech/tedarikci/bildirimler" style="color:#10211f;font-weight:700;text-decoration:none;margin-right:14px">Bildirimler<?php if((int)($GLOBALS['notification_unread']??0)>0):?> <b style="background:#e85f42;color:#fff;border-radius:10px;padding:1px 7px;font-size:12px"><?=(int)($GLOBALS['notification_unread']??0)?></b><?php endif;?></a><?php if((int)($GLOBALS['pending_suggestions']??0)>0):?><a href="/nexustraveltech/tedarikci/dagitim-merkezi#sec-room-map" style="background:#<?= (int)($GLOBALS['pending_sug_age']??0)>=7?'b0301a':'b26a00' ?>;color:#fff;border-radius:10px;padding:1px 7px;font-size:12px;text-decoration:none;margin-left:10px" title="Onay bekleyen eşleştirme önerileri — Dağıtım merkezi → bölüm 3<?= (int)($GLOBALS['pending_sug_age']??0)>=7?' · ⚠ en eskisi '.(int)($GLOBALS['pending_sug_age']??0).' gündür':'' ?>"><?=(int)($GLOBALS['pending_suggestions']??0)?><span style="font-size:11px;opacity:.85"> (<?=(int)($GLOBALS['pending_sug_room']??0)?> o<?php if((int)($GLOBALS['pending_sug_plan']??0)>0):?> + <?=(int)($GLOBALS['pending_sug_plan']??0)?> p<?php endif;?>)</span> <?= $plLabels['pending_approval'] ?><?= (int)($GLOBALS['pending_sug_age']??0)>0?'<span style="font-size:10px;opacity:.7;margin-left:3px">· '.(int)($GLOBALS['pending_sug_age']??0).' gün</span>':'' ?></a><?php endif;?><div class="user-chip"><b><?=htmlspecialchars($GLOBALS['supplier_user']['full_name'])?></b><span><?=htmlspecialchars($GLOBALS['supplier_user']['role'])?></span></div></div></header>
<?php }
function supply_end(): void { ?></main></div><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/tedarikci/ai-chat','supplier_csrf'); ?></body></html><?php }
