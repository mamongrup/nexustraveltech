NEXUS TravelTech - yerel ve üretim kurulumu

Bu sürüm PostgreSQL kullanır. phpMyAdmin / MariaDB ile kurulmaz.

YEREL GELİŞTİRME
1. PostgreSQL 16+ kurun ve servisin 5432 portunda çalıştığını doğrulayın.
2. PostgreSQL'de kullanıcı ve veritabanı oluşturun:
   createuser --pwprompt --no-superuser --no-createdb --no-createrole nexus_app
   createdb --owner=nexus_app nexus_traveltech
3. config/secrets.example.php dosyasını config/secrets.php olarak kopyalayın.
   db_* değerlerini girin ve app_encryption_key için 32 karakterden uzun rastgele bir anahtar kullanın.
4. İlk kurulumda database/postgresql-schema.sql dosyasını çalıştırın.
5. Ardından PostgreSQL migration'larını (009 ve sonrası) sıra ile çalıştırın (025+ arası login throttling, B2B rezervasyon talepleri, misafir değerlendirme, kimlik bildirimi, e-posta kuyruğu, webhook abonelikleri, iptal akışı, hata izleme, denetim kaydı, ödeme linkleri, döviz kuru, panel bildirimleri, acente self-servis kayıt, iptal politikası, depozito, 2FA, kredi limiti ve e-posta şablonlarını içerir):
   Get-ChildItem database/migrations/0*-*-postgres.sql | Sort-Object Name | ForEach-Object { Get-Content $_ | psql -U nexus_app -d nexus_traveltech -v ON_ERROR_STOP=1 }
6. Laragon'da Apache'yi başlatın. Site: http://localhost/nexustraveltech/

ÜRETİM DEPLOY (Plesk / SSH)
1. Git/Plesk deploy tamamlandıktan sonra proje köküne geçin:
   cd /var/www/vhosts/nexustraveltech.com/httpdocs
2. secrets.php dosyasının Git'e dahil edilmediğini, PostgreSQL erişim ve app_encryption_key değerlerinin doğru olduğunu kontrol edin.
3. PostgreSQL migration'larını çalıştırın (009 ve sonrası; komut tekrar çalıştırılabilir):
   for f in database/migrations/0*-*-postgres.sql; do echo "Çalışıyor: $f"; sudo -u postgres psql -d nexus_traveltech -v ON_ERROR_STOP=1 -f "$f" || exit 1; done
4. Şema ve uygulama bağımlılıklarını doğrulayın:
   /opt/plesk/php/8.5/bin/php scripts/verify-platform.php
5. Plesk Scheduled Tasks'a TEK nabız cron'u ekleyin (görev tanımları artık panelden yönetilir — /nexustraveltech/admin/timerlar):
   * * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/tick.php >/dev/null 2>&1

   Alternatif (shell gerekmez): Plesk Scheduled Tasks → "Request a URL" → admin panelindeki
   Zamanlayıcılar sayfasında gösterilen token'lı adres (https://nexustraveltech.com/nexustraveltech/timer-tick.php?token=...).

   Tek komutla (root): bash scripts/install-crons.sh — eski 8 görevi kaldırır, tek nabzı idempotent kurar.

   Görev zamanlamaları admin → Zamanlayıcılar'dan düzenlenir (aç/kapat, şimdi çalıştır, son durum/çıktı).

6. Doğrulama ve test:
   /opt/plesk/php/8.5/bin/php scripts/verify-platform.php
   /opt/plesk/php/8.5/bin/php scripts/test-booking-flow.php   (10 bölüm, tek transaction, veri bırakmaz)
   /opt/plesk/php/8.5/bin/php scripts/audit-performance.php  (eksik indeks raporu)

7. Deploy sonrası aktifleştirme kontrol listesi:
   - Admin → İki adımlı doğrulama: QR ile 2FA etkinleştir (tedarikçi/acente menülerinde de var).
   - Fiyat & kontenjan → İptal politikası: her fiyat planına ücretsiz iptal günü + ücret % girin (boşsa iade "tanımsız").
   - Rezervasyonlar: depozito tanımla → "Alındı olarak işaretle" (folyoya işlenir, acenteye bildirim).
   - Otel ön büro (günlük): check-in → çıkış yapacaklar → Check-out (folyo bakiyesi sıfırlanınca, sadakat puanı otomatik).
   - AI asistan: Admin → DeepSeek metin AI sayfasından API anahtarı girin; üç panelde de sağ alttaki
     "NEXUS AI" butonuyla açılan chatbox soruları yanıtlar, sayfalara yönlendirir ve güvenli işlemleri yapar.

YAPAY ZEKA ASİSTANI
- Motor: config/ai_assistant.php (DeepSeek tool-calling). Rol başına (admin/tedarikçi/acente) yalnızca
  güvenli araçlar tanımlıdır: okuma sorguları + küçük geri alınabilir eylemler (örn. zamanlayıcıyı şimdi
  çalıştır, ödeme linki üret). Silme/iptal gibi yıkıcı işlemler yapılmaz; kullanıcı ilgili sayfaya yönlendirilir.
- Arayüz: config/ai_widget.php → yüzen chatbox; endpoint'ler admin/ai-chat.php, tedarikci/ai-chat.php,
  acente/ai-chat.php. Sayfalar </body> öncesi ai_widget() çağrısıyla widget'ı gösterir (login/kayıt/çıkış
  sayfaları hariç). CSRF korumalı, oturum bazlı.
- Kurulum: yalnızca admin → DeepSeek metin AI sayfasından API anahtarı + model. Veritabanı migration'ı gerekmez.
- Örnek sorular: "Bugün kaç misafir geliyor?", "REF-1234 durumu nedir?", "Son hataları göster",
  "Antalya'da 15-18 Ağustos 2 yetişkin müsaitlik var mı?", "e-posta görevini şimdi çalıştır".

KAMUYA AÇIK (ÖNYÜZ) AI ASİSTAN
- Ziyaretçileri karşılayan, platform hakkındaki soruları yanıtlayan ve doğru sayfaya yönlendiren
  chatbox: tüm genel sayfalarda sağ altta (partials/footer.php üzerinden). Oturum gerekmez.
- Endpoint: api/public-chat.php — IP başına 5 dakikada 10 soru hız sınırı, mesaj uzunluk sınırı,
  sorgu+yanıt kaydı (public_chat_messages, migration 035). İç veriye erişimi yoktur; fiyat/müsaitlik
  gibi canlı bilgi uydurmaz, ilgili sayfaya yönlendirir.
- Kurulum: DeepSeek API anahtarı admin → DeepSeek metin AI'dan girildiğinde otomatik çalışır.
- Günlük özet: zamanlayıcı görevi nexus-chat-digest (varsayılan 45 8 * * *) son 24 saatin en çok
  sorulan 5 sorusunu e-postayla gönderir (alıcı: admin → Kontrol merkezi'ndeki admin_alert_email;
  gönderim process-emails kuyruğu üzerinden, günde bir kez idempotent).
- IP engelleme: admin → Ziyaretçi sohbet kayıtları sayfasından tek tıkla engelle/bayrakla/kaldır
  (blocked_ips tablosu, migration 036); engellenen IP'ler endpoint'ten 403 alır, bayraklananlar izlenir.
- Suiistimal taraması: nexus-flag-abusive-ips (06 3 * * *) son 24 saatte hız sınırını aşan
  (red >= 5), aşırı soru gönderen (>= 40) veya aynı soruyu tekrarlayan (>= 10 kez)
  IP'leri otomatik bayraklar; 7 gün içinde tekrar kötü davranan bayraklı IP'leri tam
  engellemeye yükseltir. Yeni bayrak/yükseltmeleri  admin_alert_email'e e-postalar
  (endpoint 429 reddini error_logs'a kaydeder).
- Kalitesiz girdi filtresi: minimum soru uzunluğu (varsayılan 5 karakter) ve tek
  kelime engeli (varsayılan açık) admin → Kontrol merkezi'nden ayarlanır; eşleşen
  sorular AI'ya gitmez, admin sayfasında varsayılan gizlidir ve günlük özete dahil edilmez.
- Yasak kelime listesi: admin → Kontrol merkezi'nde düzenlenir (her satıra bir tane,
  /regex/ biçimi de desteklenir); eşleşen sorular AI'ya gitmez ve isabetler suiistimal
  taramasına sinyal verir (24 saatte >= 3 isabet → otomatik bayrak).
- Konu etiketleme: config/chat_topics.php ortak sınıflandırıcı (anahtar kelime tabanlı,
  Türkçe karakter normalizasyonlu); admin ana sayfasında popüler konular ve ziyaretçi
  sohbet listesinde konu filtresi (SQL tarafı aynı normalizasyonu kullanır) olarak geçer.

OTOMATİK TESTLER
- Test bağımlılıklarını yükleyin (yalnızca test ortamında; üretimde gerekmez):
   composer install --no-interaction --prefer-dist
- Birim testlerini çalıştırın (veritabanı gerektirmez):
   vendor/bin/phpunit
- GitHub Actions (CI) her push'ta tüm PHP dosyalarında sözdizimi kontrolü + birim testleri çalıştırır.
- Rezervasyon akışı entegrasyon testi (veritabanı gerekir; tek transaction içinde koşar ve veri bırakmaz):
   php scripts/test-booking-flow.php

DEMO VERİSİ
- Satış demosu için gerçekçi veri üretin (yalnızca geliştirme ve demo ortamlarında; üretimde çalıştırmayın):
   php scripts/seed-demo-data.php
  Otel, oda tipleri, 90 günlük takvim, satış kuralı, demo acente + API anahtarı, geçmiş rezervasyon
  ve bekleyen talep oluşturur; demo giriş bilgileri komut çıktısında listelenir.
  Demo tesisi zaten varsa (DEMO — Demir Otel) hiçbir şey değiştirmez.

CANLIYA AÇMADAN ÖNCE
- Gerçek POS, e-fatura, KPS/nüfus doğrulama ve OTA bağlantıları için ilgili kurumların sözleşmesi ile API anahtarı gerekir.
- API anahtarlarını yalnızca yönetim panelindeki şifreli sağlayıcı ayarlarına girin; Git'e veya secrets.example.php içine yazmayın.
- Online check-in kimlik verisi app_encryption_key ile şifrelenir. Bu anahtarı değiştirmek eski kayıtları çözülemez hale getirir.
- Şemada seed edilen pilot tedarikçi hesabı bilinçli olarak kilitlidir; şifresi bilinmeyen bir değere ayarlıdır ve login sayfasında gösterilmez. İlk giriş için operatör şifre atamalıdır:
    1) Yeni şifrenin bcrypt hash'ini üretin:
       /opt/plesk/php/8.5/bin/php -r "echo password_hash('YENI_GUCLU_SIFRE', PASSWORD_DEFAULT);"
    2) Çıktıyı kopyalayıp şu komutla kaydedin:
       psql -U nexus_app -d nexus_traveltech -c "UPDATE supplier_users SET password_hash='<üretilen-hash>' WHERE email='pilot@nexustraveltech.com';"
- Eski MySQL şemaları (database/legacy/) artık kullanılmaz; yalnızca arşiv amaçlıdır.

ZAMANLAYICI ÇALIŞMA GEÇMİŞİ
- Migration 039: scheduled_job_runs tablosu — her çalıştırma (nabız / manuel / AI) ayrı satır olarak
  kaydedilir: durum, çıktı, süre (ms), tetikleyen. 90 günden eski kayıtlar otomatik temizlenir.
- admin/zamanlayici-gecmisi: görev ve durum filtresi, son 24s/7g hata kartları, ortalama süre, çıktı görüntüleme.
  Ayrıca seçili göreve göre son 30 günün günlük ortalama süre grafiği (saf CSS çubuklar, araç ipucuyla).
- Görev hata uyarıları (migration 040 + nexus-job-fail-alerts, her 15 dk): arka arkaya 3 kez hata veren
  görevi admin_alert_email'e bildirir — aynı hata serisi için tek e-posta (scheduled_jobs.last_fail_alert_at);
  araya giren başarı bayrağı sıfırlar, sonraki seri tekrar uyarır. E-posta: görev, komut, zamanlama,
  son hata zamanı ve son çıktı özeti + geçmişe bağlantı. Ek olarak son 24 saatin hata özeti eklenir:
  toplam hata sayısı ve en sık hata veren 3 görev (bağlam için). Görev sonraki başarılı çalışmasında
  kurtarılırsa admin'e bilgi e-postası gider (uyarı zamanı, kurtarma zamanı, kesinti süresi).
- Günlük görev sağlık raporu (nexus-job-status-digest, her gün 09:00): tüm görevlerin son 24 saatteki
  durumunu tek tabloda e-postayla gönderir — hata verenler, vadesi geldiği halde çalışmayanlar ve nabız
  (tick) sağlığı uyarısı; her şey sorunsuzsa yeşil onay mesajı. Günde bir kez idempotent.

PANEL SOHBET RAPORU (TEDARİKÇİ / ACENTE)
- Panel AI sohbetleri artık panel_chat_messages tablosuna kaydedilir (migration 038) — admin, tedarikçi
  ve acente asistanlarının tüm konuşmaları (rol + hesap kimliğiyle).
- tedarikci/sohbet-raporu ve acente/sohbet-raporu sayfaları: ay seçici, toplam/kaliteli mesaj ve aktif gün
  kartları, konu bazında haftalık trend ve en çok sorulan 5 soru; CSV/PDF dışa aktarma. Veri yalnızca
  kendi hesabına aittir (role + supplier_id/agency_id filtresi), ortak fonksiyon config/chat_report.php.

KONUYA GÖRE ANINDA YANITLAR (ZİYARETÇİ ASİSTANI)
- Admin → Kontrol merkezi'nde her konu (Tedarikçi, Acente, API, Rezervasyon, Fiyat, Kayıt/Giriş, İletişim)
  için özel karşılama metni + yönlendirme bağlantısı tanımlanabilir. Metinde {link} yazılırsa yerine mutlak
  bağlantı konur. Eşleşen konu için tanımlı yanıt varsa AI çağrısı yapılmaz (API maliyeti korunur, DeepSeek
  anahtarı olmasa bile çalışır); ikisi de boşsa o konu için AI yanıtlar. chat_topic_instant ayarıyla topluca
  aç/kapat yapılabilir. Yanıtlar yine kayıt altına alınır ve hız sınırına sayılır.

HAFTALIK SOHBET ÖZETİ (E-POSTA)
- Zamanlayıcı görevi nexus-chat-weekly (varsayılan: pazartesi 08:00) son 7 günün özetini admin_alert_email'e
  gönderir: en çok sorulan 5 soru, konu dağılımı (bu hafta vs geçen hafta değişim), yönlendirme/red sayıları
  ve yanıtlanamayan oranı. Haftada bir kez idempotent (ISO hafta anahtarı).
- Panel katılımı: tedarikçi/acente Sohbet raporu sayfalarındaki "Haftalık sohbet özetimi e-postama gönder"
  onay kutusu (platform ayarı panel_weekly_digest) ile kendi hesapları için haftalık panel asistanı özeti
  alabilirler: mesaj sayısı, aktif gün, en çok sorulan 5 soru ve konu dağılımı; mesaj sayısı ve her konu
  için geçen haftayla karşılaştırma (▲/▼ % veya 'yeni') eklenir. Özetin başında şirket adı ve bağlı
  tesis sayısı görünür (tedarikçide kendi tesisleri, acentede iş yaptığı tesisler). Alıcı, kayıt anındaki
  kullanıcının e-postasıdır ve yalnızca kendi verisini görür. Admin → Kontrol merkezi'nde katılımcı listesi (şirket
  adı + e-posta) görünür ve tek tıkla çıkarılabilir (CSRF korumalı; diğer ayarlara dokunmaz).

AYLIK SOHBET RAPORU (E-POSTA)
- Zamanlayıcı görevi nexus-monthly-report (varsayılan: her ayın 1'i 07:00) bir önceki ayın raporunu
  admin_alert_email adresine gönderir: kayıtlı/kaliteli soru, IP, yönlendirme/red, konu trendi (H1-H5),
  en çok sorulan 10 soru. TCPDF kuruluysa PDF eki olarak; değilse HTML gövde olarak gider.
- Migration 037 e-posta kuyruğuna ek (attachment_name, attachment_base64) sütunlarını ekler.
- Veri üreten tek kaynak config/chat_report.php'tir: hem admin/sohbet-raporu sayfası hem cron bu fonksiyonu kullanır.
- Rapor yalnızca ayda kayıt varsa gönderilir ve aynı ay için yalnızca bir kez kuyruğa eklenir (idempotent).
- Aylık rapor sayfasında ve PDF/CSV'de "Gün bazında trafik" tablosu: her günün soru sayısı, yönlendirme
  ve red değerleri tek tabloda (günlük toplamlar özet kartlarıyla birebir tutarlı).

İLAN YAYINLAMA (TEDARİKÇİ)
- config/listing_integrity.php → listing_readiness(): ilanı yayına açmadan önce 7 kalemlik hazırlık
  kontrolü (aktif oda tipi, aktif fiyat planı, gelecek tarihli fiyatlı takvim, en az 1 görsel,
  satış açıklaması, konum, opsiyonel satış kuralı) ve 0-100 skor üretir. Skor yalnızca 6 çekirdek
  kalem üzerinden hesaplanır (kural paydaya girmez); 6'sı tamamsa ready=true ve skor %100 olur.
- tedarikci/tesisler.php: her ilanda skor çubuğu + eksik kalem listesi; hazır olan ilanlar "Yayına al"
  (draft/paused → active), yayındakiler "Duraklat" (active → paused) yapılabilir. CSRF korumalı,
  sahiplik doğrulamalı ve audit_logs'a kaydedilir. Acente müsaitlik sorguları yalnızca status='active'
  ilanları gördüğü için bu adım oteli satışa açan tek kapıdır.
- tedarikci/yapay-zeka.php: sabit "68/100" yerine tedarikçinin tüm ilanlarının gerçek ortalama hazırlık
  skorunu ve yayına hazır ilan sayısını gösterir.
- otel-detay.php bölüm numaraları elle değil, $editorSections dizisinden türetilir (id=sec-XX, span numarası,
  sol içindekiler menüsü). Yeni bölüm eklerken yalnızca diziye satır ekleyip bölüm bloğunu aynı sayaç
  deseniyle (<?php $sec = $editorToc[$editorN++]; ?>) kopyalamak yeterlidir — numaralar bozulmaz.
- tedarikci/villa-detay.php: villa ve yat ilanları için otel-detay ile aynı şemada detay düzenleyici
  (otomatik numaralı 7 bölüm + içindekiler menüsü, hazırlık skoru, yayına al/duraklat, birim çoğaltma,
  görsel yükleme, komisyon/tahsilat ve iptal/iade). Tesisler listesindeki villa/yat kartları "İlanı
  tamamla →" ile bu sayfaya gider. Tür bazlı alanlar: villa (yatak odası, misafir, havuz, m², yapı tipi),
  yat (kabin, kapasite, uzunluk, liman, mürettebat, yıl).
- Ürün kurulumu (tesis-ekle): villa ve yat şablonları genişletildi — villa (yatak odası, maks. misafir,
  havuz tipi, m², kat, yapı tipi), yat (kabin, misafir kapasitesi, uzunluk, liman, mürettebat, yıl).
  Sayısal alanlarda aralık doğrulaması (min/max), zorunlu alan kontrolü (villa: misafir, yat: kapasite +
  uzunluk) POST tarafında yapılır. Villa/yat artık kurulumda birim tiplerini (tür bazlı özellik listesiyle)
  ve otomatik fiyat planını da oluşturur, oluşturma sonrası villa-detay sayfasına gider.
  Migration 041, mevcut veritabanındaki product_type_catalog villa/yat kayıtlarını bu şablonlarla günceller.
- Hazırlık kontrolü villa/yat türlerine uyarlandı: "Müsaitlik verisi" kalemi NEXUS takvimindeki fiyatlı
  gelecek günleri VEYA içe aktarılmış gelecek iCal bloklarını (ical_events) kabul eder. Villa/yat için
  "Aktif iCal bağlantısı" kalemi çekirdektir: en az bir aktif içe/dışa aktarma bağlantısı olmadan ilan
  yayına alınamaz.
- Tür bazlı zorunlu hazırlık kalemleri: villa → "Havuz bilgisi" + iCal bağlantısı, yat → "Bağlama limanı"
  + "Mürettebat" + iCal bağlantısı. Bunlar çekirdek kalemlerdir (skor paydasına girer); villa 8,
  yat 9 çekirdek kalemle %100'e ulaşır.
- Villa/yat özellik listeleri admin panelinden yönetilir (admin/ozellik-listeleri): özellik ekleme,
  silme, aktif/pasifleştirme. config/feature_lists.php → property_feature_lists() katalogdan okur,
  tablo yoksa/boşsa varsayılan listelere döner. Migration 042 (property_feature_catalog) mevcut
  listeleri doldurur; villa-detay sayfası artık bu katalogu kullanır.
- Zamanlayıcı görevi nexus-ical-inactive-alerts (varsayılan: 15 dakikada bir): yayındaki villa/yat
  ilanlarında en az bir iCal bağlantısı tanımlı ama hiçbiri aktif değilse tedarikçiye panel bildirimi
  (ical-takvimler sayfasına bağlantılı) ve admin_alert_email'e e-posta (ilan, tedarikçi, son senkron,
  son hatalar) gönderir. Aynı ilan için 24 saatte bir kez kuyruklanır (notifications tipi
  ical_inactive_{ilanId}).
- Hazırlık kontrolü sarı uyarı durumunu destekler: aktif iCal bağlantısı var ama son senkron 7 günden
  eskiyse (veya hiç senkron yoksa) kalem ✓ yerine ⚠ sarı gösterilir — skoru düşürmez, yayına engel
  değildir. Villa/yat detay sayfasında "İncele →" iCal takvimler sayfasına gider; tesisler kartında
  ayrı sarı rozet listesi görünür.
- Hazırlık kontrol listesinde aktif iCal dışa aktarma bağlantılarının URL'leri kopyalanabilir gösterilir
  (listing_readiness ical kalemindeki urls alanı → villa-detay paneli): "Kopyala" butonu panoya yazar,
  onay geri bildirimi gösterir.
- Özellik silme etki denetimi (admin/ozellik-listeleri): silmeden önce o özelliği service_pricing veya
  amenities alanında kullanan kayıtlı villa/yat ilanları JSONB sorgusuyla bulunur ve admin'e listelenir;
  onay sonrası özellik katalogdan silinir ve aynı işlemde bu ilanların verisinden de kaldırılır
  (jsonb_set + jsonb '-' operatörü).
- Otel olanak/aktivite/etkinlik grupları da özellik kataloğuna taşındı (migration 043): config/hotel_form.php
  artık property_feature_groups() ile katalogdan okur (kodlar amenity/activity/event, grup bilgisi
  group_label sütununda; tablo boşsa hotel_form varsayılanlarına döner). admin/ozellik-listeleri beş
  bölüm yönetir (Villa, Yat, Otel olanakları, Otel aktiviteleri, Otel etkinlikleri); otel hizmetlerinde
  grup adı zorunludur. Silme etki denetimi otelleri de kapsar (service_pricing/amenities/activities/events
  JSONB alanlarından temizler).
- Katalogda elle sıralama: admin/ozellik-listeleri'nde her özellik chip'inde ↑/↓ butonları; taşıma aynı
  kod + grup içinde komşuyla sort_order takas eder ve grubu 10'luk adımlara yeniden numaralar.
- Zamanlayıcı görevi nexus-channel-inactive-alerts (varsayılan: 15 dakikada bir): yayındaki oteli olan ve
  en az bir channel_connections bağlantısı tanımlı ama hiçbiri aktif olmayan tedarikçilere panel bildirimi
  (dağıtım merkezine bağlantılı) ve admin_alert_email'e e-posta (tedarikçi, kanallar, son senkron, son
  hatalar) gönderir. Aynı tedarikçi için 24 saatte bir kez (notifications tipi channel_inactive_{id}).
- Tedarikçi ana sayfasında "iCal sağlık durumu" kartı: villa/yat ilanı başına aktif/pasif bağlantı sayısı,
  iCal blok sayısı, son senkron zamanı ve son hata; durum rozeti (Aktif / ⚠ Eski senkron / Pasif).
- iCal senkron aşamalı uyarı şiddeti: 7–30 gün → sarı ⚠ (skoru düşürmez), 30+ gün veya hiç senkron yok →
  kırmızı ✗ eksik kalem (çekirdek, skoru düşürür ve yayına engel olur). Tedarikçi ana sayfasındaki
  iCal sağlık kartı da aynı üç durumu gösterir.
- Zamanlayıcı görevi nexus-distribution-health-digest (varsayılan: pazartesi 08:00): iCal (villa/yat)
  ve kanal (otel) sağlığını tek "Dağıtım sağlığı" e-postasında birleştirir — 7+ gün eski senkron (sarı),
  30+ gün/hiç yok (kırmızı), pasif/eksik bağlantı durumlarını tek tabloda (Tip sütunuyla); sorun yoksa
  e-posta gitmez. Haftada bir kez (platform ayarı distribution_health_week = ISO yıl-hafta) idempotent.
  Eski nexus-ical-health-digest görevi bununla değiştirildi; gerçek zamanlı pasif uyarılar
  (nexus-ical-inactive-alerts / nexus-channel-inactive-alerts, 15 dk) ayrı çalışmaya devam eder.
- Tesisler kartındaki sarı uyarı rozetleri tıklanabilir: iCal uyarısına tıklayınca iCal takvimler sayfası açılır.
- iCal URL kopyalama satırlarında görünen etiket kısaltılır (api/ical?token=XXXX…xxxx); tam URL hover
  ipucunda, kopyalama verisi değişmez.
- Tesisler listesi kartında "iCal URL göster" açılır kutusu: aktif dışa aktarma URL'leri kısa etiketle
  listelenir, "Kopyala" butonu tam URL'i panoya yazar (kart başına bağımsız aç/kapa).
- Kanal webhook uç noktası (api/channel-webhook) + dağıtım merkezinde kopyalanabilir webhook URL (migration 044: channel_connections.access_token)
- Ozellik katalogu islemleri (ekle/sil/aktiflestir/tasi) admin_audit_logs'a kaydedilir; silme kaydinda etkilenen ilan sayisi + listesi bulunur
- Katalog & siniflandirma yonetimi tek sayfada: otel tipleri/yildizlar/temalar (hotel_taxonomies) + villa/yat + otel olanak/aktivite/etkinlik kataloglari (ozellik-listeleri); eski otel-siniflandirma sayfasi yonlendirir, ceviriler ayri sayfada kalir
- Kanal baglantisi error durumunda dagitim merkezinde kirmizi rozet + kirmizi kart + son hata metni gorunur
- Zamanlayici gorevi nexus-channel-sync-job-alerts (varsayilan: 15 dakikada bir): channel_sync_jobs tablosunda son 24 saatte failed olan veya 30 dakikadan uzun running takili kalan isleri tarar, tedarikciye panel bildirimi (dagitim merkezine baglantili) ve admin_alert_email'e e-posta gonderir. Ayni tedarikci icin 24 saatte bir (notifications tipi channel_job_failed_{id}).
- Tedarikci ana sayfasinda "Dagitim saglik durumu" karti: kanal baglantilari basina durum rozeti (Aktif / Eski senkron 7-30 gun sari, 30+ gun kirmizi / Hata kirmizi / Taslak-Pasif), son senkron, basarisiz is sayisi ve son hata (iCal karti ile ayni desen)
- Tedarikci ana sayfasi "AKTIF ODA TIPI" stati artik sabit 2 yerine tedarikcinin aktif room_types sayisini gosterir
- iCal saglik karti basina ozet satiri: toplam ilan, toplam aktif/pasif baglanti ve sorunlu ilan sayisi (pasif/eski senkron/30+ gun)
- nexus-ical-inactive-alerts artik iki dal calistirir: (1) pasif iCal baglantisi (ical_inactive_{id}), (2) aktif baglanti var ama son senkron 30+ gun eski veya hic yok (ical_stale_{id}) — 30 gun esigi hazirlik kontrolundeki kirmizi kalemle ayni; 7 gunluk sari durum bildirim uretmez
- alert-ical-inactive ciktisi ilan bazli kademe ve senkron yasini gosterir: her ilan icin [durum] satiri (guncul / sari 7-30 gun / kirmizi pasif veya 30+ gun + yas), sonunda tarama ozeti (guncul/sari/kirmizi sayilari)
- Hazirlik skorundaki iCal kaleminde "hic senkron yok" ayri mesajla gosterilir: "N baglanti · hic ice aktarma yapilmadi — 'Simdi ice aktar' ile ilk senkronu baslatin" (30+ gun eski senkron ile karistirilmaz)
- Dagitim sagligi ozeti e-postasina PDF eki: TCPDF kuruluysa sorun tablosunun yazdirilabilir hali (dagitim-sagligi-YYYY-MM-DD.pdf) ek olarak gonderilir; TCPDF yoksa HTML govde ile devam eder
- admin/dagitim-sagligi sayfasi: iCal + kanal sorun tablosu canli goruntulenir, PDF (TCPDF) ve CSV olarak indirilebilir; haftalik e-posta ile ayni veri
- Dagitim sagligi ozetine konum bazli kirilim: sorunlu iCal ilanlari sehir/limana gore gruplanir (yatlar icin home_port, degilse city); e-posta govdesinde ve PDF ekinde "Konum bazli kirilim" bolumu
- Tesisler kartinda "Tum kalemler" genisletilebilir bolum: her hazirlik kalemi ikon + etiket + detay metni + eksikse "Doldur ->" / sariysa "Incele ->" baglantisiyla listelenir
- Sari iCal rozetine senkron yasi eklendi: "son senkron N gun once" (listing_readiness age_days alani)
- Tesisler kartindaki kirmizi eksik kalemler tiklanabilir: rooms/media/description/location/pool/liman/murettebat -> ilan duzenleyici, rates/inventory -> fiyat-kontenjan, ical -> iCal takvimler, rules -> satis-kurallari
- ical-takvimler sayfasindaki disa aktarma URL alani artik kisa etiket (api/ical?token=XXXX...xxxx) + Kopyala butonuyla gosterilir; tam URL hover ve panoda
- otel-detay sayfasinda "Entegrasyon" paneli: tedarikcinin kanal webhook adresleri kisa etiket + Kopyala ile gosterilir (dagitim merkezindeki desen)
- Token gizleme modu ortadan: kisa etiketler artik son 4 karakter yerine token'in ortasindaki 4 karakteri gosterir (api/ical?token=XXXXXXXXXX...XXXX...)
- Kontrol merkezinde "iCal URL kutusu yalnizca yayindaki ilanlarda gosterilsin" ayari (ical_url_published_only): villa-detay paneli, tesisler karti ve ical-takvimler sayfasinda taslak ilanlarda URL kutulari gizlenir
- Tesisler kartindaki iCal URL kutusuna "Disar aktarma kanallarina ekle — adim adim" yardim bolumu: Airbnb/Vrbo/Booking.com takvim iceri aktarma adreslerine yapistirma talimatlari (acilir/kapanir)
- Kanal webhook iki asamali senkronizasyon: api/channel-webhook yuku kuyruga alir (migration 045: channel_room_mappings + source/external_ref kolonlari), cron/process-channel-webhooks.php 1 dakikada bir isler; scope=rates/availability -> inventory_calendar fiyat/kontenjan, restrictions -> stop_sale/min/max kalis, reservations -> sold artirimi. Sonuc channel_sync_logs'a yazilir, baglanti last_sync_at tazelenir. Zamanlayici: nexus-channel-webhook-process.
- Özellik silme geri alınabilir (migration 046): silinen özellik soft-delete ile çöp kutusuna
  taşınır, ilan/bölüm anlık görüntüsü feature_delete_backups'a yazılır; "Geri yükle" ile özellik
  ve kaldırıldığı ilanlar (service_pricing değeri dahil) tek tıkla geri eklenir. feature.restore
  denetim kaydına yazılır. verify-platform artık 45 tablo sayar.
- Özellik kataloğunda toplu işlem: kartlarda çoklu seçim (tümünü seç dahil) + yapışkan
  toplu çubuk — tek seferde pasifleştirme veya silme (silme önce etki özeti ister).
  Her özellik tekil akıştan geçer (geri alınabilir); feature.bulk_delete /
  feature.bulk_deactivate özet denetim kaydı + per-özellik kayıtları yazılır.
- Tesisler kartindaki genisletilebilir "Tum kalemler" listesinde Doldur/Incele
  baglantilari artik tur bazli hedeflere gider (fiyat-kontenjan, satis-kurallari,
  ical-takvimler, ilan duzenleyici) — uyari/eksik listeleriyle ayni harita.
- Eksik kalem baglantilari artik dogrudan ilgili bolume kaydirir: gor seller -> #sec-05,
  oda/birim -> #sec-04, aciklama -> #sec-02, konum/havuz/liman/murettebat -> #sec-01
  (tesisler karti + otel-detay/villa-detay hazirlik panelleri; sayfa acilirken ayni
  offset ile yumusak kaydirma).
- Dagitim merkezine oda eslestirme arayuzu (bölüm 3): kanal + urun secilir, her
  NEXUS oda/birim tipi icin dis oda kodu tek formda girilir, tek tikla kaydedilir;
  bos satir eslesmeyi kaldirir. channel_room_mappings (migration 045) artik
  DB'den degil panelden yonetilir; webhook bildirimleri dogru oda tipine yazilir.
