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
try {
    $pdo = db();
    $sq = $pdo->prepare("SELECT COUNT(*) FROM channel_room_mappings m JOIN channel_connections c ON c.id=m.channel_connection_id WHERE c.supplier_id=? AND m.status='suggested'");
    $sq->execute([(int) $supplier_user['supplier_id']]);
    $pending_suggestions += (int) $sq->fetchColumn();
    $pq = $pdo->prepare("SELECT COUNT(*) FROM channel_rate_plan_mappings p JOIN channel_connections c ON c.id=p.channel_connection_id WHERE c.supplier_id=? AND p.status='suggested'");
    $pq->execute([(int) $supplier_user['supplier_id']]);
    $pending_suggestions += (int) $pq->fetchColumn();
} catch (Throwable $e) {
    $pending_suggestions = 0;
}
$GLOBALS['pending_suggestions'] = $pending_suggestions;
function supply_start(string $title, string $active): void { ?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title)?> | NEXUS Supply</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/nexustraveltech/assets/supply.css"><script defer src="/nexustraveltech/assets/supply.js"></script></head><body><div class="supply-app"><aside class="side"><a class="supply-brand" href="/nexustraveltech/">N<span>∿</span>XUS <small>SUPPLY</small></a><nav>
<?php $links=['dashboard'=>['','Genel bakış'],'verification'=>['hesap-dogrulama','Hesap doğrulama'],'properties'=>['tesisler','Tesisler & ürünler'],'inventory'=>['fiyat-kontenjan','Fiyat & kontenjan'],'rate_rules'=>['satis-kurallari','Satış kuralları'],'distribution'=>['dagitim-merkezi','Dağıtım & kanallar'],'ical'=>['ical-takvimler','iCal takvimler'],'hotel_ops'=>['otel-operasyon','Otel ön büro'],'hotel_daily'=>['otel-gunluk','Günlük ön büro'],'calendar'=>['rezervasyon-takvimi','Rezervasyon takvimi'],'room_rack'=>['otel-room-rack','Room Rack & gruplar'],'hotel_team'=>['otel-ekip-hizmet','Kat hizmetleri & servis'],'hotel_control'=>['otel-kontrol','Otel kontrol & gün sonu'],'hotel_finance'=>['otel-finans','Finans & night audit'],'hotel_revenue'=>['otel-gelir-crm','Gelir, CRM & sadakat'],'agency_requests'=>['acente-talepleri','Acente talepleri'],'agency_bookings'=>['acente-rezervasyonlari','Acente rezervasyonları'],'groups'=>['grup-rezervasyonlari','Grup rezervasyonları'],'bookings'=>['rezervasyonlar','Rezervasyonlar'],'payments'=>['odeme-ayarlari','Tahsilat & POS'],'pay_links'=>['odeme-linkleri','Ödeme linkleri'],'invoices'=>['faturalama','Muhasebe & fatura'],'ai'=>['yapay-zeka','NEXUS AI'],'chat_report'=>['sohbet-raporu','Sohbet raporu']]; foreach($links as $key=>[$path,$label]): ?><a class="<?=$active===$key?'active':''?>" href="/nexustraveltech/tedarikci/<?=$path?>"><?=htmlspecialchars($label)?></a><?php endforeach; ?>
</nav><div class="side-foot"><a class="<?=$active==='hotel_guest_crm'?'active':''?>" href="/nexustraveltech/tedarikci/otel-misafir-crm">Misafir CRM</a><a class="<?=$active==='hotel_staff'?'active':''?>" href="/nexustraveltech/tedarikci/otel-personel">Personel & roller</a><a class="<?=$active==='hotel_mobile'?'active':''?>" href="/nexustraveltech/tedarikci/kat-hizmetleri-mobil">Mobil kat hizmetleri</a><a class="<?=$active==='reviews'?'active':''?>" href="/nexustraveltech/tedarikci/otel-yorumlar">Misafir değerlendirmeleri</a><a class="<?=$active==='compliance'?'active':''?>" href="/nexustraveltech/tedarikci/kimlik-bildirimi">Kimlik bildirimi</a><span>PİLOT ORTAM</span><a href="/nexustraveltech/tedarikci/2fa">İki adımlı doğrulama</a><a href="/nexustraveltech/tedarikci/sifre-degistir">Şifre değiştir</a><a href="/nexustraveltech/tedarikci/logout">Güvenli çıkış</a></div></aside><main class="supply-main"><header class="supply-top"><div><span class="crumb">NEXUS SUPPLY / <?=strtoupper(htmlspecialchars($active))?></span><h1><?=htmlspecialchars($title)?></h1></div><div class="supply-top-actions"><a class="supply-bell" href="/nexustraveltech/tedarikci/bildirimler" style="color:#10211f;font-weight:700;text-decoration:none;margin-right:14px">Bildirimler<?php if((int)($GLOBALS['notification_unread']??0)>0):?> <b style="background:#e85f42;color:#fff;border-radius:10px;padding:1px 7px;font-size:12px"><?=(int)($GLOBALS['notification_unread']??0)?></b><?php endif;?><?php if((int)($GLOBALS['pending_suggestions']??0)>0):?> <b style="background:#b26a00;color:#fff;border-radius:10px;padding:1px 7px;font-size:12px" title="Webhook'ta tanınmayan kodlar için onay bekleyen eşleştirme önerileri"><?=(int)($GLOBALS['pending_suggestions']??0)?> onay bekleyen</b><?php endif;?></a><div class="user-chip"><b><?=htmlspecialchars($GLOBALS['supplier_user']['full_name'])?></b><span><?=htmlspecialchars($GLOBALS['supplier_user']['role'])?></span></div></div></header>
<?php }
function supply_end(): void { ?></main></div><?php require_once __DIR__.'/../config/ai_widget.php'; ai_widget('/nexustraveltech/tedarikci/ai-chat','supplier_csrf'); ?></body></html><?php }
