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
