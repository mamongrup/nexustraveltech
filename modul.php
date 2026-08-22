<?php
$active_page = 'solutions';
$seo_page = 'solutions';
require_once __DIR__ . '/config/i18n.php';
$current_lang = readiness_lang();

// Modül Veri Tabanı
$modules = [
    'mobil-kimlik-okur' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'Mobil Kimlik Okur ve Check-in',
        'subtitle' => 'Otelinizde Temassız ve Hızlı Misafir Girişi Artık Cebinizde!',
        'description' => 'NEXUS Mobil Kimlik Okur ve Check-in, otellerin cep telefonu veya tablet kamerası aracılığıyla misafirlerin kimlik ve pasaportlarının fotoğrafını çekmeden ve dijital kopyasını almadan sadece üzerindeki MRZ (Machine Readable Zone) kodlarını okuyarak 1 saniyeden kısa sürede %100\'e yakın doğrulukla misafir kaydı yapmanızı sağlar.',
        'icon' => 'fa-solid fa-id-card',
        'features' => [
            'Resepsiyonda kuyrukları sıfırlayan saniyeler içinde self check-in',
            'Emniyet ve Jandarma AKBS sistemine otomatik anlık XML veri aktarımı',
            'KVKK ve GDPR uyumlu; görüntü depolamadan güvenli MRZ okuma',
            'Tüm akıllı telefon ve tablet kameralarıyla %100 uyumlu çalışma',
            'Misafir folyosuna otomatik profil oluşturma ve oda kartı eşleştirme'
        ],
        'app_screen_title' => 'Smart ID Reader',
        'badge' => 'TEMASSIZ & OCR'
    ],
    'on-buro' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'Ön Büro Yönetimi Yazılımı (PMS)',
        'subtitle' => 'Otelinizin Kalbi: Hızlı, Bulut Tabanlı ve Kusursuz Ön Büro Otomasyonu',
        'description' => 'NEXUS Ön Büro (PMS) sistemi; interaktif oda planı (Room Rack), anlık doluluk grafiği, misafir folyoları, erken giriş/geç çıkış yönetimi ve gün sonu (Night Audit) işlemlerini tek ekranda toplar. Kuruluma gerek kalmadan dilediğiniz cihazdan 7/24 erişebilirsiniz.',
        'icon' => 'fa-solid fa-desktop',
        'features' => [
            'Sürükle-bırak interaktif oda planı ve renk kodlu blokaj takvimi',
            'Gelişmiş folyo yönetimi: ekstra harcamalar, parçalı ödeme ve acente faturası',
            'Otomatik Night Audit ve resmi gün sonu istatistik raporları',
            'Oda tipi ve pansiyon bazında dinamik kontenjan kontrolü',
            'Tüm satış kanallarıyla anlık iki yönlü senkronizasyon'
        ],
        'app_screen_title' => 'Room Rack & PMS',
        'badge' => 'BULUT PMS'
    ],
    'online-rezervasyon' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'Online Rezervasyon Motoru (IBE)',
        'subtitle' => 'Kendi Web Sitenizden Sıfır Komisyonla Doğrudan Rezervasyon Alın',
        'description' => 'Web sitenize dakikalar içinde entegre olan NEXUS Rezervasyon Motoru, mobil uyumlu arayüzü, çok dilli yapısı ve 3D Secure sanal POS entegrasyonuyla ziyaretçilerinizi anında ödeme yapan misafirlere dönüştürür.',
        'icon' => 'fa-solid fa-calendar-check',
        'features' => [
            'Web sitenize özel komisyonsuz doğrudan rezervasyon widget\'ı',
            'Tüm bankalar ve ödeme kuruluşlarıyla 3D Secure anlık tahsilat',
            'Çoklu para birimi (TRY, EUR, USD, GBP) ve 6 dilde yerelleştirme',
            'Erken rezervasyon indirimleri, promosyon kodları ve paket teklifler',
            'Rezervasyon onayının anında SMS, E-posta ve WhatsApp ile iletilmesi'
        ],
        'app_screen_title' => 'Booking Engine',
        'badge' => '%0 KOMİSYON'
    ],
    'kanal-yonetimi' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'Kanal Yönetimi (Channel Manager)',
        'subtitle' => '100+ OTA Kanalıyla 2 Yönlü (2-Way) Anlık Fiyat ve Kontenjan Eşitleme',
        'description' => 'Booking.com, Airbnb, Expedia, Agoda, Trip.com ve yüzlerce acente kanalındaki fiyat ve müsaitliklerinizi tek merkezden yönetin. Çifte rezervasyon (overbooking) riskini tamamen ortadan kaldırın.',
        'icon' => 'fa-solid fa-network-wired',
        'features' => [
            '2 saniyeden kısa sürede 2-Way çift yönlü anlık güncelleme',
            'Gelişmiş kanal kuralları: Kanala özel fiyat çarpanı ve minimum konaklama',
            'iCal ve XML API protokolleriyle tam senkronizasyon',
            'Kanal bazlı gelir ve performans analiz paneli',
            'Tek tıkla tüm kanallarda satış durdurma (Stop Sale)'
        ],
        'app_screen_title' => 'Channel Manager',
        'badge' => '2-WAY SYNC'
    ],
    'dinamik-fiyat' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'Dinamik Fiyatlandırma & AI Gelir Yönetimi',
        'subtitle' => 'Yapay Zekâ ile Doluluğunuzu ve RevPAR Gelirinizi Zirveye Taşıyın',
        'description' => 'NEXUS Pricing Coach, otelinizin geçmiş doluluk verilerini, rakip tesis fiyatlarını, uçuş doluluklarını ve yerel etkinlikleri yapay zekâ algoritmalarıyla analiz ederek her oda için en karlı satış fiyatını otomatik belirler.',
        'icon' => 'fa-solid fa-brain',
        'features' => [
            'Otopilot Fiyatlandırma: Belirlenen kurallarla otomatik fiyat güncelleme',
            'Bölgesel rakip analizi ve anlık fiyat karşılaştırmaları',
            'Doluluk baremlerine göre otomatik kademeli fiyat artışı',
            'RevPAR, ADR ve doluluk tahminleme grafikleri',
            'Yüksek sezon ve tatil dönemlerinde gelir maksimizasyonu'
        ],
        'app_screen_title' => 'AI Pricing Coach',
        'badge' => 'YAPAY ZEKÂ'
    ],
    'kbs-bildirim' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'KBS Kimlik Bildirimi (Emniyet & Jandarma)',
        'subtitle' => 'Yasal Kimlik Bildirimi Zorunluluğunda Sıfır Hata ve Otomatik XML Aktarımı',
        'description' => 'Konaklayan misafirlerinizin kimlik ve pasaport bilgilerini Emniyet Genel Müdürlüğü (AKBS) ve Jandarma KBS sistemlerine tek tıkla veya check-in anında otomatik olarak iletir, ceza riskini ortadan kaldırır.',
        'icon' => 'fa-solid fa-shield-halved',
        'features' => [
            'AKBS ve Jandarma web servisleriyle %100 mevzuat uyumu',
            'Giriş ve çıkışlarda tek tıkla otomatik XML oluşturma ve gönderim',
            'TCKN doğrulama algoritması ile hatalı kimlik girişlerini engelleme',
            'Gönderim logları ve resmi onay makbuzlarının arşivlenmesi',
            'Günübirlik tesisler, villalar ve oteller için tam destek'
        ],
        'app_screen_title' => 'KBS AKBS Sync',
        'badge' => 'MEVZUAT UYUMLU'
    ],
    'kat-hizmetleri' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'Kat Hizmetleri (Housekeeping & Arıza)',
        'subtitle' => 'Kat Görevlileri ve Resepsiyon Arasında Anlık Mobil İletişim',
        'description' => 'Temizlenen odaların anında resepsiyon ekranında "Satışa Hazır" durumuna geçmesini sağlar. Kat şefleri mobil telefonlarından görev atayabilir, oda arızalarını fotoğraflı olarak teknik servise iletebilir.',
        'icon' => 'fa-solid fa-broom',
        'features' => [
            'Mobil kat görevlisi ekranı: Temiz, Kirli, DND, Arızalı oda statüleri',
            'Minibar tüketimlerinin odadan anında misafir folyosuna işlenmesi',
            'Fotoğraflı arıza ve teknik servis talep takibi',
            'Kat görevlisi bazında oda temizlik performansı ve süre takibi',
            'Resepsiyon ile katlar arasında anlık bildirim trafiği'
        ],
        'app_screen_title' => 'Mobile HK',
        'badge' => 'MOBİL SERVİS'
    ],
    'sadakat-crm' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'Sadakat ve Misafir CRM Yönetimi',
        'subtitle' => 'Misafirlerinizi Tanıyın, Özel Hissettirin ve Tekrar Rezervasyon Alın',
        'description' => 'Misafirlerinizin önceki konaklama geçmişini, oda tercihlerini, alerjilerini ve harcama alışkanlıklarını saklayın. VIP misafirlerinize özel puan ve sadakat indirimleri tanımlayarak doğrudan satışlarınızı artırın.',
        'icon' => 'fa-solid fa-users-gear',
        'features' => [
            'Kapsamlı misafir profili: Doğum günleri, özel günler ve tercihler',
            'Sadakat Puanı & Tier Sistemi (Silver, Gold, Platinum VIP)',
            'Gelişmiş filtreleme ile hedefli SMS ve E-posta pazarlama',
            'Kara liste (Blacklist) ve sorunlu misafir uyarı mekanizması',
            'Doğrudan rezervasyonlarda otomatik sadakat indirimi uygulama'
        ],
        'app_screen_title' => 'Guest CRM',
        'badge' => 'MİSAFİR BAĞLILIĞI'
    ],
    'whatsapp-api' => [
        'category' => 'Otel ve Konaklama',
        'title' => 'WhatsApp API & Otomatik Bildirimler',
        'subtitle' => 'Misafirinizle En Sevdiği İletişim Kanalından Anında İletişim Kurun',
        'description' => 'Rezervasyon onayını, otel konumunu, Wi-Fi şifresini ve dijital oda anahtarını misafirinize resmi WhatsApp Business API üzerinden otomatik olarak gönderin. Konaklama süresince memnuniyet anketleri iletin.',
        'icon' => 'fa-brands fa-whatsapp',
        'features' => [
            'Meta Cloud / WhatsApp Business API resmi altyapısı',
            'Rezervasyon anında otomatik onay ve yol tarifi mesajı',
            'Check-in öncesi online check-in formu linki gönderme',
            'Konaklama sırası oda servisi ve istekleri WhatsApp üzerinden alma',
            'Check-out sonrası otomatik TripAdvisor / Google değerlendirme daveti'
        ],
        'app_screen_title' => 'WhatsApp Bot',
        'badge' => 'OTOMASYON'
    ],
    'restoran-pos' => [
        'category' => 'POS & Restoran',
        'title' => 'Restoran POS Satış Programı',
        'subtitle' => 'Dokunmatik Adisyon, Mutfak Ekranı ve Odaya Folyo Aktarımı',
        'description' => 'Otel restoranları, barlar, kafeler ve plaj işletmeleri için dokunmatik adisyon sistemi. Masaları krokiden yönetin, siparişleri anında mutfak ekranına (KDS) düşürün ve hesabı tek tıkla misafirin oda folyosuna aktarın.',
        'icon' => 'fa-solid fa-cash-register',
        'features' => [
            'Dokunmatik hızlı sipariş ekranı ve interaktif masa krokisi',
            'Mutfak ve bar için dijital sipariş ekranları (KDS) ve termal fiş yazıcı',
            'Odaya folyo aktarımı: Misafir oda no ve soyadı doğrulamasıyla güvenli aktarım',
            'Parçalı ödeme, ikram, indirim ve masa birleştirme/bölme',
            'Reçete bazlı stoktan otomatik hammadde düşümü'
        ],
        'app_screen_title' => 'Restaurant POS',
        'badge' => 'DOKUNMATİK POS'
    ],
    'online-masa' => [
        'category' => 'POS & Restoran',
        'title' => 'Masa & Online Restoran Rezervasyonu',
        'subtitle' => 'Alakart Restoranlarınızda Kapasiteyi Yönetin, Masa Boş Kalmasın',
        'description' => 'Otel içi alakart restoranlar veya bağımsız mekanlar için online masa rezervasyon motoru. Misafirleriniz web sitenizden veya QR koddan diledikleri saat için masa ayırtabilir.',
        'icon' => 'fa-solid fa-chair',
        'features' => [
            'Saat ve kişi sayısına göre dinamik masa kapasite kontrolü',
            'Alakart otel misafirleri için ücretsiz hak takibi',
            'Özel gün ve kutlama talepleri için rezervasyon notları',
            'SMS ve WhatsApp ile otomatik masa rezervasyon konfirmasyonu',
            'No-show oranlarını düşüren hatırlatma bildirimleri'
        ],
        'app_screen_title' => 'Table Booking',
        'badge' => 'MASA YÖNETİMİ'
    ],
    'qr-menu' => [
        'category' => 'POS & Restoran',
        'title' => 'Akıllı Dijital Menü (QR Menü & Sipariş)',
        'subtitle' => 'Çok Dilli, Fotoğraflı ve Masadan Temassız Sipariş Alabilen Yeni Nesil Menü',
        'description' => 'Baskı maliyetlerini sıfırlayın. Masadaki QR kodu okutan misafirler yemeklerin yüksek kaliteli fotoğraflarını, alerjen uyarılarını ve çok dilli açıklamalarını görerek doğrudan sipariş verebilir.',
        'icon' => 'fa-solid fa-qrcode',
        'features' => [
            '6 dilde anlık otomatik menü çevirisi ve dövizli fiyat gösterimi',
            'Alerjen ve kalori bilgileriyle zenginleştirilmiş ürün detayları',
            'Masadan doğrudan mutfağa sipariş verme ve garson çağırma',
            'Anlık fiyat ve stok güncelleme: Tükenen ürünü tek tıkla gizleme',
            'Kredi kartı ile masadan temassız online ödeme imkanı'
        ],
        'app_screen_title' => 'Smart QR Menu',
        'badge' => 'TEMASSIZ MENÜ'
    ],
    'spa-fitness' => [
        'category' => 'POS & Restoran',
        'title' => 'SPA, Masaj ve Spor Salonu Yönetimi',
        'subtitle' => 'Terapist Randevuları, Masaj Odası Blokajı ve Üyelik Otomasyonu',
        'description' => 'Otelinizin SPA, wellness ve fitness merkezini randevulu ve hatasız yönetin. Terapistlerin müsaitlik takvimlerini planlayın, seans paketleri satın ve turnike geçişlerini kontrol edin.',
        'icon' => 'fa-solid fa-spa',
        'features' => [
            'Terapist ve masaj odası bazında renkli randevu takvimi',
            'Kontörlü ve seanslı SPA paketleri satış ve takip modülü',
            'Harcamaların tek tıkla oda folyosuna veya kredi kartına aktarılması',
            'Fitness üyeleri için RFID / QR turnike geçiş kontrolü',
            'Terapist prim ve performans hak ediş hesaplama'
        ],
        'app_screen_title' => 'SPA & Wellness',
        'badge' => 'RANDEVU TAKVİMİ'
    ],
    'muhasebe' => [
        'category' => 'ERP & Finans',
        'title' => 'Ön Muhasebe, Folyo & Cari Yönetimi',
        'subtitle' => 'Acenteler, Tedarikçiler ve Resmi Defterlerle %100 Uyumlu Finans Çözümü',
        'description' => 'Tüm gelir ve giderlerinizi, acente cari hesaplarını, kasa hareketlerini ve banka hesaplarını tek sistemde toplayın. Mali müşavirinizle uyumlu resmi muhasebe raporları alın.',
        'icon' => 'fa-solid fa-scale-balanced',
        'features' => [
            'Acente ve tedarikçi bazında cari hesap ekstresi ve mutabakat',
            'Kasa, banka, POS ve döviz kasası anlık nakit akış takibi',
            'Konaklama vergisi, KDV ve resmi kesintilerin otomatik hesaplanması',
            'Gelir-Gider, Mizan, Bilanço ve Yaşlandırma analiz raporları',
            'e-Fatura ve e-Arşiv entegratörleriyle anlık fiş entegrasyonu'
        ],
        'app_screen_title' => 'LioX ERP Finance',
        'badge' => 'TAM MUHASEBE'
    ],
    'e-donusum' => [
        'category' => 'ERP & Finans',
        'title' => 'e-Fatura, e-Arşiv ve e-İrsaliye',
        'subtitle' => 'GİB Onaylı Özel Entegratörlerle Tek Tıkla Resmi Faturalandırma',
        'description' => 'Check-out yapan misafirlerinize veya acentelere saniyeler içinde e-Fatura veya e-Arşiv faturası kesin. GİB standartlarındaki tüm vergileri otomatik hesaplayıp e-posta ile misafire ulaştırın.',
        'icon' => 'fa-solid fa-file-invoice',
        'features' => [
            'Nilvera, EDM, Paraşüt, Foriba ve tüm GİB entegratörleriyle uyumlu',
            '%2 Konaklama Vergisi ve tevkifatlı faturaları otomatik hesaplama',
            'Toplu acente faturası kesme ve e-posta ile gönderme',
            'Gelen e-Faturaları otomatik satın alma ve stok fişine dönüştürme',
            '10 yıl yasal arşivleme ve sorgulama güvencesi'
        ],
        'app_screen_title' => 'e-Invoice GİB',
        'badge' => 'GİB ONAYLI'
    ],
    'tur-operatoru' => [
        'category' => 'Özel Çözümler',
        'title' => 'Tur Operatörü & Paket Tur Yönetimi',
        'subtitle' => 'Günübirlik Turlar, Geziler ve Acente Kontenjan Dağıtımı',
        'description' => 'Tur operatörleri ve seyahat acenteleri için paket tur oluşturma, kontenjan tahsisi, rehber ve araç planlaması ile B2B acente satış portalı.',
        'icon' => 'fa-solid fa-route',
        'features' => [
            'Tarihli ve saatli tur envanteri ve kişi başı fiyatlandırma',
            'B2B acentelere özel komisyonlu kontenjan tanımlama',
            'Rehber, şoför ve transfer aracı operasyonel iş çizelgesi',
            'Voucher oluşturma ve misafire dijital bilet iletimi',
            'Canlı gelir, acente hak ediş ve karlılık raporları'
        ],
        'app_screen_title' => 'Tour Operator',
        'badge' => 'B2B & B2C TUR'
    ],
    'marina-yat' => [
        'category' => 'Özel Çözümler',
        'title' => 'Marina & Yat İşletim Sistemi',
        'subtitle' => 'Yat Kiralama, Bağlama Sözleşmeleri ve Çekek Yeri Planlaması',
        'description' => 'Tekne ve yat işletmeleri ile marinalar için bağlama planı, çekek alanı takibi, elektrik ve su sayaç okuma ile transit log süreçlerini dijitalleştirin.',
        'icon' => 'fa-solid fa-ship',
        'features' => [
            'Görsel marina haritası üzerinde tekne bağlama yeri planlama',
            'Günlük, aylık ve yıllık bağlama sözleşmeleri ve otomatik fatura',
            'Elektrik ve su tüketim sayaçlarının folyoya otomatik aktarımı',
            'Yat kiralama takvimi, mürettebat ve kumanya operasyonları',
            'Liman başkanlığı ve resmi transit log evrak takibi'
        ],
        'app_screen_title' => 'Marina System',
        'badge' => 'DENİZCİLİK'
    ],
    'villa-devremulk' => [
        'category' => 'Özel Çözümler',
        'title' => 'Villa, Devremülk ve Devre Tatil Yönetimi',
        'subtitle' => 'Müstakil Villalar ve Devre Tatil Tesisleri İçin Özel Portföy Yönetimi',
        'description' => 'Müstakil villa portföyü olan acenteler veya devre tatil işletmeleri için dönem takvimleri, hissedar hakları, temizlik periyotları ve ev sahibi kazanç raporlama portalı.',
        'icon' => 'fa-solid fa-house-chimney',
        'features' => [
            'Ev Sahibi Portalı: Mülk sahiplerinin takvim ve gelirlerini izlemesi',
            'Devre mülk dönem takvimleri, kırmızı/beyaz dönem hakları ve aidat',
            'Giriş-çıkış temizlik ve havuz bakım ekipleri rota planlaması',
            'Airbnb, Vrbo ve villa portallarıyla anlık iCal/API eşitlemesi',
            'Hasar depozitosu tahsilatı ve iade süreç yönetimi'
        ],
        'app_screen_title' => 'Villa & Timeshare',
        'badge' => 'VİLLA & DEVRE'
    ],
    'tga-entegrasyon' => [
        'category' => 'Özel Çözümler',
        'title' => 'TGA (Türkiye Turizm Tanıtım Ajansı) Entegrasyonu',
        'subtitle' => 'Resmi TGA Payı Bildirimi ve İstatistik Raporlaması',
        'description' => 'Kültür ve Turizm Bakanlığı ile Türkiye Turizm Tanıtım ve Geliştirme Ajansı (TGA) payı bildirimlerini cironuz üzerinden otomatik hesaplayın ve beyannamenizi hatasız oluşturun.',
        'icon' => 'fa-solid fa-landmark',
        'features' => [
            'Aylık konaklama ve yeme-içme cirosu üzerinden resmi TGA payı hesabı',
            'Bakanlık standartlarında istatistik ve doluluk raporlaması',
            'Muhasebe beyannamesine hazır resmi döküm',
            'Cezai yaptırımları önleyen otomatik uyarı sistemi'
        ],
        'app_screen_title' => 'TGA Bakanlık',
        'badge' => 'TGA PAYI'
    ],
    'hotspot-loglama' => [
        'category' => 'Özel Çözümler',
        'title' => 'Hotspot İnternet & 5651 Loglama Sistemi',
        'subtitle' => 'Misafir Wi-Fi Girişi ve 5651 Sayılı Kanuna Uygun Zaman Damgalı Loglama',
        'description' => 'Otelinize gelen misafirlerin oda numarası ve TCKN/Pasaport bilgileriyle güvenli Wi-Fi kullanmasını sağlar. 5651 sayılı kanun gereği tüm internet trafiğini zaman damgasıyla şifreleyip arşivler.',
        'icon' => 'fa-solid fa-wifi',
        'features' => [
            'Mikrotik, Cisco ve tüm router donanımlarıyla tam uyumlu',
            'Ön büro check-in bilgisiyle anlık Wi-Fi yetkilendirmesi',
            '5651 sayılı kanuna tam uyumlu TÜBİTAK zaman damgalı loglama',
            'Misafir hız ve bant genişliği sınırlama (Bandwidth Limiting)',
            'Otel promosyonları ve duyurularını gösteren karşılama ekranı (Splash Page)'
        ],
        'app_screen_title' => 'Hotspot 5651',
        'badge' => '5651 LOG'
    ]
];

$slug = $_GET['slug'] ?? 'mobil-kimlik-okur';
if (!isset($modules[$slug])) {
    $slug = 'mobil-kimlik-okur';
}
$mod = $modules[$slug];
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang) ?>" dir="<?= $current_lang === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($mod['title']) ?> | NEXUS TravelTech</title>
  <?php require __DIR__ . '/partials/seo.php'; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500;600&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <link rel="stylesheet" href="/nexustraveltech/assets/styles.css?v=<?= filemtime(__DIR__ . '/assets/styles.css') ?>" />
  <style>
    .module-portal-layout {
      display: grid;
      grid-template-columns: 310px 1fr;
      gap: 36px;
      padding: 40px 0 80px;
      align-items: start;
    }
    @media (max-width: 992px) {
      .module-portal-layout { grid-template-columns: 1fr; }
    }
    .portal-sidebar {
      background: #ffffff;
      border: 1px solid #e2e8e2;
      border-radius: 14px;
      padding: 18px 12px;
      position: sticky;
      top: 90px;
      max-height: calc(100vh - 120px);
      overflow-y: auto;
    }
    .portal-sidebar h4 {
      font-size: 11px;
      font-family: "DM Mono", monospace;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #7b8e8a;
      padding: 8px 12px;
      margin: 12px 0 4px;
    }
    .portal-sidebar h4:first-child { margin-top: 0; }
    .portal-nav-link {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      font-size: 13.5px;
      font-weight: 600;
      color: #2c3e3a;
      text-decoration: none;
      border-radius: 8px;
      transition: all 0.15s;
    }
    .portal-nav-link i {
      font-size: 14px;
      width: 20px;
      color: #7b8e8a;
    }
    .portal-nav-link:hover {
      background: #f1f6f1;
      color: #071412;
    }
    .portal-nav-link.active {
      background: #071412;
      color: #d7ff48;
    }
    .portal-nav-link.active i {
      color: #d7ff48;
    }
    .portal-content {
      background: #ffffff;
      border: 1px solid #e2e8e2;
      border-radius: 16px;
      padding: 40px;
    }
    .portal-category-tag {
      display: inline-block;
      font: 700 11px "DM Mono", monospace;
      text-transform: uppercase;
      padding: 5px 12px;
      background: #eef4ee;
      color: #2b5443;
      border-radius: 99px;
      margin-bottom: 14px;
    }
    .portal-title {
      font-size: clamp(28px, 4vw, 42px);
      letter-spacing: -0.04em;
      line-height: 1.15;
      margin: 0 0 12px;
      color: #071412;
    }
    .portal-subtitle {
      font-size: 18px;
      font-weight: 600;
      color: #e85f42;
      margin: 0 0 24px;
      line-height: 1.4;
    }
    .portal-desc {
      font-size: 16px;
      line-height: 1.7;
      color: #4a5955;
      margin-bottom: 36px;
    }
    .portal-mockup-row {
      display: grid;
      grid-template-columns: 260px 1fr;
      gap: 32px;
      align-items: center;
      background: #f8faf8;
      border: 1px solid #e7ece7;
      border-radius: 16px;
      padding: 30px;
      margin-bottom: 36px;
    }
    @media (max-width: 768px) {
      .portal-mockup-row { grid-template-columns: 1fr; }
    }
    .phone-mockup {
      width: 220px;
      height: 380px;
      background: #071412;
      border-radius: 32px;
      border: 6px solid #1e332f;
      padding: 20px 16px;
      color: #fff;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 20px 40px rgba(0,0,0,0.2);
      margin: 0 auto;
    }
    .phone-top-bar {
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      color: #7b8e8a;
    }
    .phone-screen-content {
      text-align: center;
      margin: auto 0;
    }
    .phone-icon-box {
      width: 60px;
      height: 60px;
      border-radius: 16px;
      background: rgba(215, 255, 72, 0.15);
      color: #d7ff48;
      display: grid;
      place-items: center;
      font-size: 26px;
      margin: 0 auto 16px;
    }
    .phone-title {
      font-size: 14px;
      font-weight: 700;
      margin-bottom: 4px;
    }
    .phone-badge {
      font: 600 10px "DM Mono", monospace;
      background: #e85f42;
      color: #fff;
      padding: 3px 8px;
      border-radius: 4px;
    }
    .features-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    .features-list li {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      font-size: 15px;
      color: #2c3e3a;
      margin-bottom: 14px;
      line-height: 1.5;
    }
    .features-list li i {
      color: #2e7d32;
      margin-top: 4px;
      font-size: 16px;
    }
    .portal-actions {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      margin-top: 30px;
      border-top: 1px solid #e7ece7;
      padding-top: 24px;
    }
  </style>
</head>
<body class="inner-page">
  <main>
    <?php require __DIR__ . '/partials/header.php'; ?>

    <div class="shell">
      <div class="module-portal-layout">
        
        <!-- SOL MENÜ: TÜM MODÜLLER -->
        <aside class="portal-sidebar">
          <h4>OTEL & KONAKLAMA</h4>
          <a href="/nexustraveltech/modul?slug=mobil-kimlik-okur" class="portal-nav-link <?= $slug === 'mobil-kimlik-okur' ? 'active' : '' ?>"><i class="fa-solid fa-id-card"></i> Mobil Kimlik Okur</a>
          <a href="/nexustraveltech/modul?slug=on-buro" class="portal-nav-link <?= $slug === 'on-buro' ? 'active' : '' ?>"><i class="fa-solid fa-desktop"></i> Ön Büro (PMS)</a>
          <a href="/nexustraveltech/modul?slug=online-rezervasyon" class="portal-nav-link <?= $slug === 'online-rezervasyon' ? 'active' : '' ?>"><i class="fa-solid fa-calendar-check"></i> Rezervasyon Motoru</a>
          <a href="/nexustraveltech/modul?slug=kanal-yonetimi" class="portal-nav-link <?= $slug === 'kanal-yonetimi' ? 'active' : '' ?>"><i class="fa-solid fa-network-wired"></i> Kanal Yönetimi</a>
          <a href="/nexustraveltech/modul?slug=dinamik-fiyat" class="portal-nav-link <?= $slug === 'dinamik-fiyat' ? 'active' : '' ?>"><i class="fa-solid fa-brain"></i> Dinamik Fiyatlandırma</a>
          <a href="/nexustraveltech/modul?slug=kbs-bildirim" class="portal-nav-link <?= $slug === 'kbs-bildirim' ? 'active' : '' ?>"><i class="fa-solid fa-shield-halved"></i> KBS Kimlik Bildirimi</a>
          <a href="/nexustraveltech/modul?slug=kat-hizmetleri" class="portal-nav-link <?= $slug === 'kat-hizmetleri' ? 'active' : '' ?>"><i class="fa-solid fa-broom"></i> Kat Hizmetleri</a>
          <a href="/nexustraveltech/modul?slug=sadakat-crm" class="portal-nav-link <?= $slug === 'sadakat-crm' ? 'active' : '' ?>"><i class="fa-solid fa-users-gear"></i> Sadakat & CRM</a>
          <a href="/nexustraveltech/modul?slug=whatsapp-api" class="portal-nav-link <?= $slug === 'whatsapp-api' ? 'active' : '' ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp API</a>

          <h4>POS & RESTORAN</h4>
          <a href="/nexustraveltech/modul?slug=restoran-pos" class="portal-nav-link <?= $slug === 'restoran-pos' ? 'active' : '' ?>"><i class="fa-solid fa-cash-register"></i> Restoran POS</a>
          <a href="/nexustraveltech/modul?slug=online-masa" class="portal-nav-link <?= $slug === 'online-masa' ? 'active' : '' ?>"><i class="fa-solid fa-chair"></i> Masa Rezervasyonu</a>
          <a href="/nexustraveltech/modul?slug=qr-menu" class="portal-nav-link <?= $slug === 'qr-menu' ? 'active' : '' ?>"><i class="fa-solid fa-qrcode"></i> Akıllı QR Menü</a>
          <a href="/nexustraveltech/modul?slug=spa-fitness" class="portal-nav-link <?= $slug === 'spa-fitness' ? 'active' : '' ?>"><i class="fa-solid fa-spa"></i> SPA & Fitness</a>

          <h4>ERP & FİNANS</h4>
          <a href="/nexustraveltech/modul?slug=muhasebe" class="portal-nav-link <?= $slug === 'muhasebe' ? 'active' : '' ?>"><i class="fa-solid fa-scale-balanced"></i> Ön Muhasebe & Cari</a>
          <a href="/nexustraveltech/modul?slug=e-donusum" class="portal-nav-link <?= $slug === 'e-donusum' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice"></i> e-Fatura / e-Arşiv</a>

          <h4>ÖZEL & SEKTÖREL</h4>
          <a href="/nexustraveltech/modul?slug=tur-operatoru" class="portal-nav-link <?= $slug === 'tur-operatoru' ? 'active' : '' ?>"><i class="fa-solid fa-route"></i> Tur Operatörü</a>
          <a href="/nexustraveltech/modul?slug=marina-yat" class="portal-nav-link <?= $slug === 'marina-yat' ? 'active' : '' ?>"><i class="fa-solid fa-ship"></i> Marina & Yat</a>
          <a href="/nexustraveltech/modul?slug=villa-devremulk" class="portal-nav-link <?= $slug === 'villa-devremulk' ? 'active' : '' ?>"><i class="fa-solid fa-house-chimney"></i> Villa & Devremülk</a>
          <a href="/nexustraveltech/modul?slug=tga-entegrasyon" class="portal-nav-link <?= $slug === 'tga-entegrasyon' ? 'active' : '' ?>"><i class="fa-solid fa-landmark"></i> TGA Bildirimi</a>
          <a href="/nexustraveltech/modul?slug=hotspot-loglama" class="portal-nav-link <?= $slug === 'hotspot-loglama' ? 'active' : '' ?>"><i class="fa-solid fa-wifi"></i> Hotspot & 5651</a>
        </aside>

        <!-- SAĞ PANEL: MODÜL DETAYI -->
        <article class="portal-content">
          <span class="portal-category-tag"><?= htmlspecialchars($mod['category']) ?></span>
          <h1 class="portal-title"><?= htmlspecialchars($mod['title']) ?></h1>
          <p class="portal-subtitle"><?= htmlspecialchars($mod['subtitle']) ?></p>
          <p class="portal-desc"><?= htmlspecialchars($mod['description']) ?></p>

          <div class="portal-mockup-row">
            <div class="phone-mockup">
              <div class="phone-top-bar">
                <span>09:41</span>
                <span>5G 100%</span>
              </div>
              <div class="phone-screen-content">
                <div class="phone-icon-box"><i class="<?= htmlspecialchars($mod['icon']) ?>"></i></div>
                <div class="phone-title"><?= htmlspecialchars($mod['app_screen_title']) ?></div>
                <span class="phone-badge"><?= htmlspecialchars($mod['badge']) ?></span>
              </div>
              <div style="text-align:center;font-size:10px;color:#7b8e8a">NEXUS CLOUD SUITE</div>
            </div>

            <div>
              <h3 style="margin:0 0 16px;font-size:18px;font-weight:700">Öne Çıkan Özellikler & Faydalar</h3>
              <ul class="features-list">
                <?php foreach ($mod['features'] as $feat): ?>
                  <li><i class="fa-solid fa-circle-check"></i> <span><?= htmlspecialchars($feat) ?></span></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>

          <div class="portal-actions">
            <a href="/nexustraveltech/fiyat-listesi" class="button button-lime" style="padding:12px 24px;font-weight:700">
              <i class="fa-solid fa-calculator" style="margin-right:6px"></i> Pakete Ekle & Fiyat Hesapla
            </a>
            <a href="/nexustraveltech/#erken-erisim" class="button button-dark" style="padding:12px 24px;font-weight:700">
              Ücretsiz Demo İste →
            </a>
          </div>
        </article>

      </div>
    </div>
  </main>
  <?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
