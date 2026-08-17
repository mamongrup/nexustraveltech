# NEXUS TravelTech — Kullanım ve Operasyon Kılavuzu

Bu kılavuz; sunucu güncelleme, veritabanı migration/sahiplik, sağlık kontrolü,
webhook uçtan uca test, e-posta uyarı testleri ve sık karşılaşılan hataların
çözümünü tek kalıcı dokümanda toplar. Tüm komutlar **root** olarak sunucuda
çalıştırılır (Plesk SSH Terminal: `https://<sunucu-ip>/modules/ssh-terminal/`).

> PHP yolu sunucuda: `/opt/plesk/php/8.5/bin/php` · Veritabanı: `nexus_traveltech`
> Kod dizini: `/var/www/vhosts/nexustraveltech.com/httpdocs`

---

## 1) Sunucu kodu güncelleme (deploy)

```bash
cd /var/www/vhosts/nexustraveltech.com/httpdocs
git fetch origin --unshallow --prune 2>/dev/null; git fetch origin --prune --tags && git reset --hard origin/main && git log --oneline -1
```

- `git log --oneline -1` çıktısı, GitHub'daki `main` ile aynı olmalı.
- Farklıysa önce `git fetch` çalıştırın; `origin/main` referansı güncel olmayabilir.
- Kod güncellemesi sonrası **her zaman** adım 2 (migration/sahiplik) çalıştırılır.

---

## 2) Veritabanı: migration + sahiplik devri

```bash
cd /var/www/vhosts/nexustraveltech.com/httpdocs
bash scripts/apply-migrations-postgres.sh
```

Betik ne yapar:
1. `schema_migrations` tablosunu hazırlar (`commit_hash` kolonu dahil)
2. Bekleyen tüm `database/migrations/*.sql` dosyalarını **postgres kullanıcısıyla** uygular
   (böylece "must be owner of table" hataları oluşmaz) ve `schema_migrations`'a kaydeder
3. Tüm public şema (tablolar + sequence'lar) sahipliğini **app kullanıcısına** devreder
4. `health-check.php --dry-run` ile ön doğrulama yapar

Beklenen çıktı: `Uygulanan: N · Başarısız: 0` ve sahiplik satırında `nexus_app` sayısı en üstte.

---

## 3) Sağlık kontrolü

```bash
cd /var/www/vhosts/nexustraveltech.com/httpdocs

# Tam tarama (tablolar, migration durumu, tutarlılık, ortam)
/opt/plesk/php/8.5/bin/php scripts/health-check.php

# Ayrıntılı yetim eşleştirme listesi (ID · dış kod · durum · sorun türü)
/opt/plesk/php/8.5/bin/php scripts/health-check.php --orphans

# Onarım — önce kuru (hiçbir şey değişmez):
/opt/plesk/php/8.5/bin/php scripts/health-check.php --repair --dry-run
# Sonra onaylı:
/opt/plesk/php/8.5/bin/php scripts/health-check.php --repair --yes

# Platform doğrulama (tablo/kolon kontrolü)
/opt/plesk/php/8.5/bin/php scripts/verify-platform.php

# TEK KOMUTLA TÜM MODÜLLERİN OTOMATİK TESTİ (salt okunur)
/opt/plesk/php/8.5/bin/php scripts/auto-test.php
# Her kontrolü göster:  --verbose
# Gerçek webhook uçtan uca (cURL POST + uygulama + doğrulama):  --e2e
# E2E test satırlarını silme:  --e2e --keep
```

`auto-test.php` modülleri: veritabani (51 tablo + kritik kolonlar) · migration (bekleyen
dosyalar) · zamanlayici (görev kayıtları + advisory kilit) · kanal-webhook (bağlantı,
token biçimi, eşleştirmeler, yetim) · kur · ical · eposta (kuyruk + son test durumu) ·
e2e-webhook. Her kontrol OK/WARN/FAIL üretir; sonunda modül özeti ve çıkış kodu
(0 = temiz, 1 = hata var).

`--repair` neleri düzeltir:
- Yanlış/eski şemalı tabloları (ör. hibrit `channel_room_mappings`) migration zincirinden yeniden kurar
- Yetim eşleştirmeleri temizler (`channel_room_mappings` / `channel_rate_plan_mappings` / `channel_property_mappings`)
- Hedefi dolmuş `suggested` önerileri otomatik `confirmed` yapar
- Tüm onarımlar `admin_audit_logs`'a yazılır (`health.repair_*`)

---

## 4) Zamanlayıcı görevleri

Görevler `config/scheduler.php` içindeki `scheduler_seed_defaults()` ile kayıtlıdır;
Admin paneli → **Zamanlayıcılar** sayfasında görünür, "Şimdi çalıştır" ile manuel
tetiklenebilir. Kritik olanlar:

| Görev kodu | Ne yapar | Sıklık |
|---|---|---|
| `nexus-process-emails` | E-posta kuyruğu (tüm uyarılar buradan gider) | 5 dk |
| `nexus-channel-webhook-process` | Kanal webhook yüklerini uygular | 1 dk |
| `nexus-channel-webhook-retry` | Başarısız yükleri yeniden dener (maks 3) | 5 dk |
| `nexus-health-check` | Günlük sağlık kontrolü + admin'e özet e-postası | 06:45 |
| `nexus-room-mapping-audit` | Oda eşleştirme tutarlılık denetimi (kanal/ürün bazlı) | 05:30 |
| `nexus-distribution-health-digest` | Haftalık dağıtım sağlığı özeti (iCal + kanal + yetim trendi) | Pzt 08:00 |
| `nexus-admin-alert-test` | Uyarı e-postası hazırlık kontrolü (kuru) | Pzt 07:00 |
| `nexus-alert-test-delivery` | Test e-postası teslimat doğrulaması | 30 dk |
| `nexus-fx-missing-audit` | Eksik kur çifti denetimi | 06:15 |
| `nexus-suggestion-cleanup` | Süresi dolan eşleştirme önerilerini temizler | 05:00 |

Kilit sorunu: `cron/tick.php` çıktısı `{"locked":true,...}` ise advisory kilit
takılıdır (anahtar `424242`):

```bash
# Kilidi tutan bağlantıyı bul ve sonlandır
sudo -u postgres psql -d nexus_traveltech -c "SELECT pid, state, application_name FROM pg_stat_activity WHERE pid IN (SELECT pid FROM pg_locks WHERE locktype='advisory' AND objid=424242);"
sudo -u postgres psql -d nexus_traveltech -c "SELECT pg_terminate_backend(pid) FROM pg_locks WHERE locktype='advisory' AND objid=424242 AND pid <> pg_backend_pid();"
/opt/plesk/php/8.5/bin/php cron/tick.php   # → {"locked":false,...} beklenir
```

---

## 5) Webhook uçtan uca test

### 5.1 Ön koşullar
1. Kanal bağlantısı oluşturulmuş olmalı (Dağıtım merkezi → bölüm 1 — `access_token` otomatik üretilir, 64 hex)
2. Ürün eşleştirilmiş olmalı (bölüm 2 — `external_property_id` ↔ NEXUS ilanı)
3. Oda kodları eşleştirilmiş olmalı (bölüm 3 — tanınmayan kodlar **öneri** oluşturur, onay bekler)

### 5.2 Bağlantı sağlığı (GET)

```bash
curl -s "https://nexustraveltech.com/api/channel-webhook.php?token=GERCEK_TOKEN" | head
# → {"ok":true,"channel":"..."}
```

### 5.3 Fiyat yükü (rates) — EUR → TRY dönüşümü dahil

```bash
curl -s -X POST "https://nexustraveltech.com/api/channel-webhook.php?token=GERCEK_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "scope": "rates",
    "external_property_id": "OTA-HOTEL-1",
    "entries": [
      {
        "external_room_id": "OTA-STD",
        "date": "2026-09-01",
        "price": 120.00,
        "currency": "EUR",
        "rate_plan_code": "BAR"
      }
    ]
  }'
```

Beklenen yanıt: `{"ok":true,...}` — yük `channel_sync_logs`'a `queued` yazılır,
`nexus-channel-webhook-process` (1 dk) uygular.

### 5.4 Kapsamlar

| `scope` | entries alanları |
|---|---|
| `availability` | `allotment` (kontenjan), `sold` |
| `rates` | `price`, `currency`, `rate_plan_code` |
| `restrictions` | `stop_sale`, `min_stay`, `max_stay` |
| `reservations` | `qty` (rezervasyon miktarı) |

### 5.5 Otomatik smoke test

```bash
/opt/plesk/php/8.5/bin/php scripts/webhook-smoke-test.php
# Ön koşulları denetler, token'ı okur, test yükü gönderir, sonucu raporlar.
```

---

## 6) E-posta uyarı testi + teslimat doğrulaması

```bash
cd /var/www/vhosts/nexustraveltech.com/httpdocs

# Kuru çalışma (hiçbir şey göndermez) — kanallar hazır mı bakar
/opt/plesk/php/8.5/bin/php cron/test-admin-alerts.php

# Gerçek gönderim — TÜM kanallar TEK özet e-postada tablo olarak gider
/opt/plesk/php/8.5/bin/php cron/test-admin-alerts.php --send

# ~5 dk sonra teslimat durumunu kontrol et
/opt/plesk/php/8.5/bin/php cron/verify-alert-test-delivery.php

# Admin'e tablolu teslimat raporu gönder (--email)
/opt/plesk/php/8.5/bin/php cron/verify-alert-test-delivery.php --email
```

Durumlar: `delivered` (kod eşleşti) · `pending` (30 dk penceresi açık) ·
`missed` (30 dk geçti, kod eşleşmedi → kuyruk işleyicisini kontrol edin).
Tarihçe (son 20 koşu) `alert_test_history` ayarında tutulur.

---

## 7) Yaygın hatalar ve düzeltmeleri

| `error_message` / belirti | Anlam | Çözüm |
|---|---|---|
| `column m.channel_connection_id does not exist` | 045-052 migration'ları uygulanmamış, şema eski | Adım 1 (kod) + adım 2 (migration) + `--repair --yes` |
| `must be owner of table ...` | Tablo sahibi `postgres`, uygulama `nexus_app` ile koşuyor | `bash scripts/apply-migrations-postgres.sh` (adım 2) |
| `DROP TABLE ... syntax error at or near "\"` | Eski kod (kaçış hatası `b5c8ba8`'de düzeltildi) | Kodu güncelle (adım 1) |
| `{"locked":true,"ran":[]}` | Zamanlayıcı advisory kilidi takılı | Adım 4'teki kilit komutu |
| `property_not_mapped` | `external_property_id` eşleştirilmemiş | Dağıtım merkezi → bölüm 2 |
| `no_rooms` / `no_rate_plan` | İlanda aktif oda tipi / fiyat planı yok | İlan düzenleyiciden aktifleştir |
| `eşleşme yok` (tanınmayan kod) | **Beklenen** — öneri oluştu | Dağıtım merkezi → bölüm 3: öneriyi onayla |
| `blacklisted_room:CODE` | Kod karalistede (reddedilen öneri) | Bölüm 3 manuel formdan eşleştir |
| `fx_rate_missing:EUR->TRY:...` | Kur çifti `fx_rates`'te yok | Admin → Kur yönetimi: TCMB'den doldur veya manuel |
| `invalid_date` / `out_of_range` | Yük tarihi hatalı | Kanal yük formatını düzelt (Y-m-d) |

### 7.1 Tanılama: akış üç tabloda (webhook → uygulama → takvim)

```bash
sudo -u postgres psql -d nexus_traveltech -x <<'SQL'
-- Eşleştirmeler: tanınan (confirmed) / bekleyen (suggested)
SELECT id, external_room_id, room_type_id, rate_plan_id, status, suggestion_score, suggestion_count
FROM channel_room_mappings ORDER BY id;

-- Webhook işleri: son 8
SELECT id, channel_connection_id, scope, status, attempt_count, source, external_ref,
       COALESCE(error_message,'') AS err, created_at, completed_at
FROM channel_sync_logs ORDER BY id DESC LIMIT 8;

-- Takvim: uygulanan fiyatlar + plan para birimi
SELECT ic.room_type_id, ic.rate_plan_id, ic.stay_date, ic.base_price, ic.allotment, ic.sold,
       ic.stop_sale, rp.currency
FROM inventory_calendar ic LEFT JOIN rate_plans rp ON rp.id=ic.rate_plan_id
ORDER BY ic.id DESC LIMIT 8;
SQL
```

Sağlıklı akış kanıtı (üçü birden görülmeli):
1. `channel_room_mappings`: `OTA-STD · confirmed · rate_plan_id dolu · score 91`
2. `channel_sync_logs`: `rates · success · source=webhook · fx_audit:[EUR→TRY]`
3. `inventory_calendar`: aynı `room_type_id + rate_plan_id` için `base_price` plan para biriminde

### 7.2 Başarısız yükleri çek

```bash
sudo -u postgres psql -d nexus_traveltech -x -c "SELECT id, channel_connection_id, scope, status, attempt_count, error_message, created_at, completed_at, jsonb_pretty(fx_audit) AS fx FROM channel_sync_logs WHERE status='failed' ORDER BY id DESC LIMIT 15;"
```

- `attempt_count >= 3` → akıllı retry tükendi (kalıcı hata); kök nedeni düzeltin, yükler otomatik yeniden denenir.
- `status='queued'` çok eskiyse (15 dk+) → `nexus-channel-webhook-process` çalışmıyor; `cron/tick.php` çıktısını kontrol edin.

---

## 8) Sık kullanılan psql kontrolleri

```bash
# Tüm tablolar + sahip
sudo -u postgres psql -d nexus_traveltech -c "SELECT tableowner, count(*) FROM pg_tables WHERE schemaname='public' GROUP BY tableowner ORDER BY 2 DESC;"

# Uygulanan migration'lar
sudo -u postgres psql -d nexus_traveltech -c "SELECT file, applied_at, left(commit_hash,7) AS commit FROM schema_migrations ORDER BY id;"

# Denetim kayıtları (onarım / silme)
sudo -u postgres psql -d nexus_traveltech -c "SELECT action, details, created_at FROM admin_audit_logs WHERE action LIKE 'health.repair%' OR action LIKE 'feature.%' ORDER BY id DESC LIMIT 10;"
```

---

*Güncellenme: bu dosya, kullanım kılavuzunun kalıcı kopyasıdır; komutlardaki değişiklikler
kod güncellemeleriyle birlikte buraya da işlenmelidir.*
