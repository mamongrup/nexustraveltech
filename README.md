# NEXUS TravelTech

Otel, villa ve yat otelcilik yönetimi platformu — tedarikçilerin fiyat, kontenjan ve rezervasyon akışlarını tek panelden yönettiği, kanal entegrasyonlarıyla otomatik senkronize ettiği bir PMS/Channel Manager.

---

## Mimari

```
┌─────────────────────────────────────────────────────────────────┐
│                         NEXUS Platform                         │
├──────────┬──────────┬──────────┬──────────┬────────────────────┤
│ Tedarikçi│  Acente  │   Admin  │  Public  │   API Webhooks     │
│  Paneli  │  Paneli  │  Paneli  │  Site    │                    │
├──────────┴──────────┴──────────┴──────────┴────────────────────┤
│                      PHP 8.5 + PostgreSQL                      │
├────────────────────────────────────────────────────────────────┤
│  config/  │  tedarikci/  │  admin/  │  api/  │  cron/          │
│  database │  Properties  │  Settings│  REST  │  Scheduler      │
│  Auth     │  Room Types  │  Users   │  Webhooks│ Tick/Lock     │
│  Mailer   │  Rate Plans  │  Audit   │  iCal  │  Health Check   │
│  Scheduler│  Distribution│  Templates│ FX    │  Distribution   │
└────────────────────────────────────────────────────────────────┘
```

### Temel modüller

| Modül | Konum | Açıklama |
|---|---|---|
| **Ürün yönetimi** | `tedarikci/otel-detay.php`, `villa-detay.php` | Oda tipleri, fiyat planları, görseller, olanaklar |
| **Dağıtım merkezi** | `tedarikci/dagitim-merkezi.php` | Kanal bağlantıları, oda/plan eşleştirmesi, webhook testi |
| **iCal entegrasyonu** | `tedarikci/ical-takvimler.php` | Airbnb, Vrbo, Booking.com takvim senkronizasyonu |
| **Kanal webhook** | `api/channel-webhook.php` | OTA'dan gelen fiyat/kontenjan/kısıt/rezervasyon bildirimleri |
| **Kur yönetimi** | `admin/kur-yonetimi.php` | TCMB kurları, EUR/TRY/USD/GBP dönüşümleri, eksik kur denetimi |
| **Sağlık kontrolü** | `scripts/health-check.php` | Tablo/kolon/migration/kilit durumu, yetim temizliği |
| **Zamanlayıcı** | `config/scheduler.php` | 35+ otomatik görev, advisory kilitleme, tek nabız noktası |
| **E-posta altyapısı** | `config/mailer.php` | Kuyruk, şablonlar, test teslimat doğrulaması |
| **Çöp kutusu** | `admin/ozellik-listeleri.php` | Özellik silme/geri yükleme, TTL, toplu işlem |
| **Denetim** | `admin/denetim-kayitlari.php` | Tüm admin işlemleri için log, CSV dışa aktarma |

### Veritabanı (PostgreSQL)

51+ tablo, 63 migration. Ana tablolar:

```
properties ──┬── room_types ──┬── inventory_calendar
             │                └── rate_plans ── rate_rules
             ├── media
             └── property_features

channel_connections ──┬── channel_property_mappings
                     ├── channel_room_mappings
                     ├── channel_rate_plan_mappings
                     └── channel_sync_logs ── fx_audit (JSONB)

ical_connections ──┬── ical_events
                   └── ical_sync_logs

scheduled_jobs ── scheduled_job_runs
email_outbox
admin_audit_logs
fx_rates ── fx_audit_daily
feature_delete_backups ── pending_trash_purges
```

### Webhook akışı

```
OTA (Booking.com/Airbnb/Vrbo)
  │
  ▼ POST /api/channel-webhook?token=...
  │
  ├─ token doğrula (64 hex, channel_connections.access_token)
  ├─ channel_property_mappings → property_id çöz
  ├─ channel_room_mappings → room_type_id + rate_plan_id çöz
  │   └─ tanınmayan kod → suggested öneri oluştur (onay bekler)
  ├─ inventory_calendar UPSERT (fiyat/kontenjan/kısıt)
  ├─ fx_rates ile kur dönüşümü (farklı birimdeyse)
  ├─ channel_sync_logs'a yaz (status, fx_audit JSONB)
  └─ channel_webhook_apply() sonucu döndür
```

### Zamanlayıcı görevleri

`config/scheduler.php` — `scheduler_seed_defaults()` ile 35+ görev otomatik kaydedilir.
Tek nabız noktası (`cron/tick.php`) dakikada bir çalışır, advisory kilit ile çift çalışma engellenir.

| Görev | Sıklık | Açıklama |
|---|---|---|
| `nexus-channel-webhook-process` | 1 dk | Webhook yüklerini uygular |
| `nexus-process-emails` | 5 dk | E-posta kuyruğunu gönderir |
| `nexus-health-check` | 06:45 | Günlük sağlık kontrolü + admin e-postası |
| `nexus-distribution-health-digest` | Pzt 08:00 | Haftalık dağıtım sağlığı özeti |
| `nexus-alert-test-delivery` | 30 dk | Test e-postası teslimat doğrulaması |
| `nexus-room-mapping-audit` | 05:30 | Eşleştirme tutarlılık denetimi |
| `nexus-fx-missing-audit` | 06:15 | Eksik kur çifti denetimi |
| `nexus-revenue-rec` | 02:15 | Gelir önerisi üretimi |

Tam liste: `admin/timerlar.php` → Görevler tablosu.

---

## Hızlı başlangıç

### Ön koşullar

- PHP 8.5+ (PDO PostgreSQL, cURL, JSON)
- PostgreSQL 14+
- Composer yok (sıfır bağımlılık)

### Kurulum

```bash
git clone https://github.com/mamongrup/nexustraveltech.git
cd nexustraveltech

# Veritabanını oluştur
sudo -u postgres createdb nexus_traveltech

# Migration'ları uygula
sudo -u postgres psql -d nexus_traveltech -f database/migrations/001-initial-postgres.sql
bash scripts/apply-migrations-postgres.sh

# Sağlık kontrolü
/opt/plesk/php/8.5/bin/php scripts/health-check.php
```

### Yapılandırma

```bash
# config/secrets.php — veritabanı ve uygulama anahtarı
cp config/secrets.example.php config/secrets.php
#_EDIT_: db_user, db_pass, app_encryption_key

# Admin e-postası
# Admin → Kontrol merkezi → admin_alert_email
```

### Sunucuda test

```bash
# Tüm modülleri otomatik test et
/opt/plesk/php/8.5/bin/php scripts/auto-test.php

# Uçtan uca webhook testi
/opt/plesk/php/8.5/bin/php scripts/webhook-e2e-verify.php

#curl ile webhook testi
bash scripts/webhook-test-curl.sh
```

---

## Teknoloji yığını

| Katman | Teknoloji |
|---|---|
| **Backend** | PHP 8.5 (strict_types, nullable, match, enums, typed properties) |
| **Veritabanı** | PostgreSQL 14+ (JSONB, advisory locks, UPSERT) |
| **Frontend** | Saf HTML/CSS/JS (framework yok, minified PHP) |
| **Zamanlayıcı** | Kendi scheduler'ı (tek tick.php + advisory lock) |
| **E-posta** | Kuyruk tablosu (email_outbox) + PHP mail() / SMTP |
| **CI/CD** | Git push → sunucuda manuel `git reset --hard` |
| **Hosting** | Plesk (Linux) |

---

## Dizin yapısı

```
├── admin/                  # Yönetim paneli sayfaları
├── acente/                 # Acente paneli
├── api/                    # REST API uç noktaları
│   ├── channel-webhook.php # Kanal webhook alıcısı
│   ├── supplier-room-test.php  # Oda eşleştirme testi
│   └── ical.php            # iCal dışa aktarma
├── config/                 # Çekirdek yapılandırma
│   ├── database.php        # PDO bağlantısı
│   ├── health.php          # Sağlık kontrolü mantığı
│   ├── scheduler.php       # Görev zamanlayıcı
│   ├── channel_webhook.php # Webhook işleme
│   ├── mailer.php          # E-posta kuyruğu + şablonlar
│   └── tick_lock.php       # Advisory kilidi yönetimi
├── cron/                   # Zamanlayıcı görevleri (35+)
├── database/migrations/    # PostgreSQL migration'ları (63)
├── scripts/                # CLI araçları
│   ├── health-check.php    # Sağlık kontrolü
│   ├── verify-platform.php # Platform doğrulama
│   ├── auto-test.php       # Otomatik test
│   └── webhook-e2e-verify.php # E2E webhook testi
├── tedarikci/              # Tedarikçi paneli sayfaları
├── KULLANIM.md             # Operasyon kılavuzu
└── README.md               # Bu dosya
```

---

## Operasyon

Sunucu yönetimi, migration, webhook test, hata giderme ve sık karşılaşılan sorunlar için **[KULLANIM.md](KULLANIM.md)** dosyasına bakın.

---

## Lisans

Proprietary — [MAMON Grup](https://mamon.com.tr) mülkiyetinde.
