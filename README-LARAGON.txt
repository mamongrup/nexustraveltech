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
5. Plesk Scheduled Tasks'a aşağıdaki cron'ları ekleyin:
   */15 * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/sync-ical-calendars.php >/dev/null 2>&1
   15 2 * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/generate-revenue-recommendations.php >/dev/null 2>&1
   * * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/process-netgsm-sms.php >/dev/null 2>&1
   */5 * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/process-emails.php >/dev/null 2>&1
   */1 * * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/process-webhooks.php >/dev/null 2>&1
   0 8 * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/send-welcome-emails.php >/dev/null 2>&1
   15 9 * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/send-notification-digest.php >/dev/null 2>&1
   30 3 * * * /opt/plesk/php/8.5/bin/php /var/www/vhosts/nexustraveltech.com/httpdocs/cron/expire-group-options.php >/dev/null 2>&1

   Tek komutla (root): bash scripts/install-crons.sh — tüm görevleri idempotent kurar.

6. Doğrulama ve test:
   /opt/plesk/php/8.5/bin/php scripts/verify-platform.php
   /opt/plesk/php/8.5/bin/php scripts/test-booking-flow.php   (10 bölüm, tek transaction, veri bırakmaz)
   /opt/plesk/php/8.5/bin/php scripts/audit-performance.php  (eksik indeks raporu)

7. Deploy sonrası aktifleştirme kontrol listesi:
   - Admin → İki adımlı doğrulama: QR ile 2FA etkinleştir (tedarikçi/acente menülerinde de var).
   - Fiyat & kontenjan → İptal politikası: her fiyat planına ücretsiz iptal günü + ücret % girin (boşsa iade "tanımsız").
   - Rezervasyonlar: depozito tanımla → "Alındı olarak işaretle" (folyoya işlenir, acenteye bildirim).
   - Otel ön büro (günlük): check-in → çıkış yapacaklar → Check-out (folyo bakiyesi sıfırlanınca, sadakat puanı otomatik).

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
