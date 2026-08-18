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

## 8) Sorun giderme — derinlemesine

### 8.1 `must be owner of table ...` (migration sahiplik hatası)

**Belirti:** Migration veya `--repair` çalışırken `SQLSTATE[42501]: ERROR: must be owner of table <tablo>`; health-check çıktısında `Migration başarısız: 0xx-...` satırları.

**Neden:** Tablolar `postgres` (superuser) sahibinde; migration'lar ve sağlık onarımları `nexus_app` ile koşuyor. Sahibi olmayan kullanıcı `ALTER`/`DROP`/`CREATE` yapamaz.

**Tanı:**
```bash
sudo -u postgres psql -d nexus_traveltech -c "SELECT tableowner, count(*) FROM pg_tables WHERE schemaname='public' GROUP BY tableowner ORDER BY 2 DESC;"
```
`postgres` satırı varsa sorun doğrulanır.

**Çözüm (sıralı):**
```bash
cd /var/www/vhosts/nexustraveltech.com/httpdocs
bash scripts/apply-migrations-postgres.sh
```
Betik migration'ları postgres olarak uygular, tüm public şema sahipliğini `nexus_app`'e devreder ve `schema_migrations`'a kaydeder.

**Yalnızca sahiplik devri (tek seferlik, migration'sız alternatif):** betik yerine sadece sahipliği devretmek isterseniz — app kullanıcısını `config/secrets.php`'den okur, tüm tablo + sequence + şema sahipliğini ona geçirir:

```bash
cd /var/www/vhosts/nexustraveltech.com/httpdocs
APP_USER=$(grep -oP "'db_user'\s*=>\s*'\K[^']+" config/secrets.php)
echo "Hedef sahip: $APP_USER"
sudo -u postgres psql -d nexus_traveltech -v app_user="$APP_USER" <<'SQL'
SELECT format('ALTER TABLE public.%I OWNER TO %I', tablename, :'app_user')
  FROM pg_tables WHERE schemaname='public' \gexec
SELECT format('ALTER SEQUENCE public.%I OWNER TO %I', sequence_name, :'app_user')
  FROM information_schema.sequences WHERE sequence_schema='public' \gexec
ALTER SCHEMA public OWNER TO :"app_user";
SQL
```

**Doğrulama:** Betik sonunda sahiplik satırında `nexus_app` en üstte; `verify-platform.php` temiz. Kalan `✗` varsa tam hata ile tekrar deneyin.

---

### 8.2 `{"locked":true,"ran":[]}` (zamanlayıcı kilidi takılı)

**Belirti:** `cron/tick.php` çıktısı `{"locked":true,"ran":[]}`; hiçbir görev çalışmıyor.

**Neden:** Zamanlayıcı, PostgreSQL advisory kilidi (anahtar `424242`) ile eşzamanlı tick'leri seriler. Kilit tutan bağlantı canlı ama asılı kaldıysa (uzun süren iş, kopan oturum) sonraki tick'ler reddedilir.

**Tanı (kilidi tutan bağlantı):**
```bash
sudo -u postgres psql -d nexus_traveltech -c "SELECT pid, state, application_name, left(query,80) AS q FROM pg_stat_activity WHERE pid IN (SELECT pid FROM pg_locks WHERE locktype='advisory' AND objid=424242);"
```

**Çözüm (sıralı):**
```bash
# 1) Kilidi tutan bağlantıyı sonlandır
sudo -u postgres psql -d nexus_traveltech -c "SELECT pg_terminate_backend(pid) FROM pg_locks WHERE locktype='advisory' AND objid=424242 AND pid <> pg_backend_pid();"

# 2) Kilit bırakıldı mı kontrol et
/opt/plesk/php/8.5/bin/php cron/tick.php    # → {"locked":false,"ran":[...]} beklenir

# 3) Sonlandırma işe yaramazsa (kilit sistem tarafında):
#    sudo systemctl restart postgresql
```

**Önleme:** Tick'i iki yere aynı anda koymayın (cron + web tetik). Uzun süren bir görev kilidi uzun tutuyorsa o görevi inceleyin; kilit oturum ölünce otomatik bırakılır.

---

### 8.3 Kuyruk birikmesi (webhook `queued` yığılması)

**Belirti:** `channel_sync_logs` içinde saatlerdir `queued` kalan satırlar; dağıtım merkezi işlem günlüğünde işlenmemiş yükler; e-postalar gitmiyor.

**Tanı:**
```bash
sudo -u postgres psql -d nexus_traveltech -c "SELECT status, count(*) FROM channel_sync_logs WHERE created_at > now() - interval '24 hours' GROUP BY status;"
sudo -u postgres psql -d nexus_traveltech -c "SELECT count(*) AS eski_queued FROM channel_sync_logs WHERE status='queued' AND created_at < now() - interval '15 minutes';"
sudo -u postgres psql -d nexus_traveltech -c "SELECT status, count(*) FROM email_outbox WHERE created_at > now() - interval '24 hours' GROUP BY status;"
```

**Neden zinciri (en sık → seyrek):**
1. `nexus-channel-webhook-process` (1 dk) hiç çalışmıyor — cron/tick kapalı, PHP yolu yanlış veya kilit takılı (bkz. 8.2)
2. `nexus-channel-webhook-retry` tükenmiş — `attempt_count >= 3` satırlar kalıcı `failed`
3. Payload kalıcı hatalı (eşleşme yok, kur eksik, tarih bozuk) — her denemede aynı hatayla döner
4. `cron/process-emails.php` (5 dk) çalışmıyor → `email_outbox` birikir

**Çözüm (sıralı):**
```bash
# 1) Zamanlayıcı sağlığı: kilit + son çalıştırma
/opt/plesk/php/8.5/bin/php cron/tick.php
sudo -u postgres psql -d nexus_traveltech -c "SELECT code, last_run_at, last_status FROM scheduled_jobs WHERE code IN ('nexus-channel-webhook-process','nexus-channel-webhook-retry','nexus-process-emails') ORDER BY code;"

# 2) Kilit takılıysa bırak (bkz. 8.2); yoksa işleyiciyi elle koşup hatayı gör
/opt/plesk/php/8.5/bin/php cron/process-channel-webhooks.php 2>&1 | tail -20

# 3) Hata kalıcıysa (ör. eşleşme/kur/tarih) kök nedeni düzelt — tabloya bak:
sudo -u postgres psql -d nexus_traveltech -x -c "SELECT id, scope, attempt_count, error_message FROM channel_sync_logs WHERE status='failed' ORDER BY id DESC LIMIT 5;"
#    · 'eşleşme yok' → bölüm 3'ten öneriyi onayla
#    · 'fx_rate_missing' → Kur yönetiminden TCMB çek
#    · 'invalid_date' → kanalın tarih formatı

# 4) Retry tükenen (attempt_count>=3) yükler kök neden düzeldikten sonra otomatik yeniden denenir.
#    Bekleyen yığını manuel temizlemek gerekirse (kök neden çözülmüşse):
#    UPDATE channel_sync_logs SET attempt_count=0, status='queued', error_message=NULL WHERE status='failed' AND attempt_count>=3 AND created_at > now() - interval '7 days';
```

**Otomatik onarım (health-check --repair):** `--repair` modu 4. adımdaki elle sıfırlamayı otomatik yapar — deneme sayısı tükenmiş (attempt_count >= max_retries) ve hata kalıcı olmayan (transient/expected) yükleri kuyruğa geri alır. Yalnızca `failure_category` kolonu varsa transient/expected filtresi uygulanır; yoksa tüm tükenmiş yükler sıfırlanır. Sonuç denetim kaydına (`health.repair_retry_reset`) yazılır:
```bash
# Dry-run: kaç yük sıfırlanacağını gör
/opt/plesk/php/8.5/bin/php scripts/health-check.php --repair --dry-run

# Gerçek onarım
/opt/plesk/php/8.5/bin/php scripts/health-check.php --repair --yes
```

**Doğrulama:** 15 dakika sonra aynı tanı sorgusu — `queued`/`failed` sayısı düşmeli, `success` artmalı; işlem günlüğünde yeni satırlar `success` olmalı.

---

### 8.4 Kur çifti eksik veya bayat

**Belirti:** Auto-test'te `kur` modülünde FAIL/WARN; webhook yükü EUR/USD ile geldiğinde `fx_rate_missing` hatası; fiyat takvimine yazım yapılamıyor.

**Tanı:**
```bash
sudo -u postgres psql -d nexus_traveltech -c "SELECT base_currency, quote_currency, rate_date, rate FROM fx_rates ORDER BY rate_date DESC LIMIT 10;"
sudo -u postgres psql -d nexus_traveltech -c "SELECT audit_date, missing_count, stale_count FROM fx_audit_daily ORDER BY audit_date DESC LIMIT 3;"
```

**Çözüm:**
1. Admin → Kur yönetimi → "Eksik çiftleri TCMB'den doldur" butonuna tıklayın
2. Tek çift için: `Admin → Kur yönetimi` sayfasından elle girin
3. Bayat kur (>7 gün): Aynı sayfadan yenileyin; otomatik denetim (`nexus-fx-missing-audit`) günde 2 kez tarar

**Kök neden:** Kur XML'inde çift henüz yayınlanmamış olabilir (hafta sonları / tatiller). Kalıcı çözüm: `fx_rates` tablosuna en azından EUR→TRY ve USD→TRY çiftlerini girin.

---

### 8.5 iCal senkron hataları

**Belirti:** Auto-test'te `ical` modülünde FAIL/WARN; iCal bağlantıları pasif; 24 saat içinde başarısız senkron.

**Tanı:**
```bash
/opt/plesk/php/8.5/bin/php scripts/verify-platform.php | grep -i ical
```

**Çözüm (sıralı):**
1. iCal connection URL'sini doğrulayın (tarayıcıda açın, `.ics` yüklenmeli)
2. URL değiştiyse: Tedarikçi → iCal takvimleri → ilgili bağlantıyı düzenleyin
3. Otomatik duraklatılmışsa (`auto_pause`): Kontrol merkezinden eşiği artırın veya bağlantıyı elle etkinleştirin
4. Tekrar deneme: Tedarikçi panelinden "Senkronize et" butonuna tıklayın

---

### 8.6 Eşleşme sorunları (property_not_mapped, no_rooms, yetim)

**Belirti:** Auto-test'te `kanal-webhook` modülünde property/oda eşleştirmesi hataları; dağıtım merkezinde "onay bekleyen" öneriler; yetim eşleştirmeler.

**Tanı:**
```bash
/opt/plesk/php/8.5/bin/php scripts/verify-platform.php | grep -i "eşleş"
/opt/plesk/php/8.5/bin/php scripts/health-check.php --orphans
```

**Çözüm:**
1. **Eşleşme yok (öneri bekliyor):** Dağıtım merkezi Bölüm 3'ten önerileri onaylayın veya elle eşleştirin
2. **property_not_mapped:** Dağııtmda Bölüm 2'den ürün eşleştirmesi yapın
3. **no_rooms:** Oda tipi/fiyat planı oluşturun (Tedarikçi → ilan detay → oda tipi ekle)
4. **Yetim eşleştirme:** `health-check --repair --yes` ile otomatik temizleyin

---

### 8.7 E-posta kuyruk sorunları

**Belirti:** Auto-test'te `eposta` modülünde WARN/FAIL; e-postalar gitmiyor; `email_outbox` tablosundaqueued/failedsatırları.

**Tanı:**
```bash
sudo -u postgres psql -d nexus_traveltech -c "SELECT status, count(*) FROM email_outbox GROUP BY status;"
sudo -u postgres psql -d nexus_traveltech -c "SELECT subject, status, error, created_at FROM email_outbox WHERE status='failed' ORDER BY id DESC LIMIT 5;"
```

**Çözüm:**
1. `admin_alert_email` tanımsızsa: Kontrol merkezinden ayarlayın
2. Kuyruk birikiyorsa: `nexus-process-emails` görevinin çalıştığını kontrol edin (Zamanlayıcılar sayfası)
3. SMTP hatası: Gönderim ayarlarını kontrol edin (`config/mailer.php`)
4. Başarısız e-postaları yeniden dene: `UPDATE email_outbox SET status='queued' WHERE status='failed';`

---

### 8.8 Webhook otomatik eşleşme ve döngü uyarıları

**Belirti:** Tanınmayan oda kodu geldiğinde öneri oluşmuyor; aynı yük tekrar tekrar başarısız oluyor (döngü uyarısı).

**Tanı:**
```bash
/opt/plesk/php/8.5/bin/php scripts/verify-platform.php | grep -i "hata sınıflandırması"
sudo -u postgres psql -d nexus_traveltech -c "SELECT failure_category, count(*) FROM channel_sync_logs WHERE created_at > now() - interval '24 hours' GROUP BY failure_category;"
```

**Çözüm:**
1. **Öneri oluşmuyor:** Kontrol merkezinden `channel_webhook_auto_map` → `true`
2. **Benzerlik eşiği düşük:** `channel_webhook_similarity_threshold` değerini artırın (varsayılan 45, 60-75 önerilir)
3. **Döngü uyarısı:** `channel_webhook_loop_threshold` değerini artırın veya karalisteye alın (`channel_mapping_blacklist`)
4. **Kalıcı hatalar:** `permanent` kategorisindeki yükler yapısal — eşleştirmeyi düzeltin, retry işe yaramaz

---

## 9) Sık kullanılan psql kontrolleri

```bash
# Tüm tablolar + sahip
sudo -u postgres psql -d nexus_traveltech -c "SELECT tableowner, count(*) FROM pg_tables WHERE schemaname='public' GROUP BY tableowner ORDER BY 2 DESC;"

# Uygulanan migration'lar
sudo -u postgres psql -d nexus_traveltech -c "SELECT file, applied_at, left(commit_hash,7) AS commit FROM schema_migrations ORDER BY id;"

# Denetim kayıtları (onarım / silme)
sudo -u postgres psql -d nexus_traveltech -c "SELECT action, details, created_at FROM admin_audit_logs WHERE action LIKE 'health.repair%' OR action LIKE 'feature.%' ORDER BY id DESC LIMIT 10;"
```

---

## 10) Platform ayarları referansı (Kontrol merkezi)

Admin → **Kontrol merkezi** sayfasından yönetilen tüm ayarlar. Her ayar `platform_settings` tablosunda saklanır; alteration `audit_saved_setting()` ile denetim kaydına yazılır.

### Genel

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `admin_alert_email` | `''` | Uyarı/sağlık/test e-postalarının gönderileceği adres |
| `supplier_notify_email` | `false` | Tedarikçi kullanıcılarına e-posta bildirimi gönderilsin mi |
| `tooltip_language` | `tr` | Arayüz ipuçları dili (tr/en/de/ru/ar/fr) |

### Webhook & Kanal

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `channel_webhook_auto_map` | `true` | Tanınmayan oda kodu gelince otomatik öneri oluştur (kapalıysa ilk aktif oda tipine yazar) |
| `channel_webhook_default_currency` | `EUR` | Kanal birim göndermezse kullanılacak varsayılan para birimi |
| `channel_webhook_loop_threshold` | `3` | Aynı yük bu kadar kez başarısız olunca döngü uyarısı |
| `channel_webhook_max_retries` | `3` | Başarısız webhook yükü için maks yeniden deneme sayısı |
| `channel_webhook_similarity_threshold` | `45` | Otomatik öneri için isim benzerlik eşiği (%) — altında önerilen kod ilk aktif oda tipine yazılır |
| `channel_suggestion_ttl_days` | `30` | Onay bekleyen öneri süresi (gün) — süresi dolanlar temizlenir |

### iCal

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `ical_url_published_only` | `false` | iCal URL'sini yalnızca yayındaki ilanlarda göster |
| `ical_repeat_threshold` | `3` | Aynı hata bu kadar kez tekrarlanınca iCal tekrar uyarısı |
| `ical_auto_pause_repeat` | `false` | iCal tekrar hatası eşiği aşılınca bağlantıyı otomatik duraklat |

### Kur (FX)

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `fx_tcmb_last_ok` | `null` | Son başarılı TCMB kur çekme tarihi (otomatik) |
| `fx_tcmb_last_fail` | `null` | Son başarısız kur çekme tarihi (otomatik) |
| `fx_tcmb_last_error` | `''` | Son kur çekme hata mesajı (otomatik) |

### Çöp kutusu & Özellikler

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `feature_trash_ttl_days` | `30` | Özellik çöp kutusunda kalma süresi (7-365 gün) |
| `trash_upcoming_warning_days` | `3` | Kalıcı silmeden önce uyarı penceresi (gün) |
| `orphan_cleanup_require_password` | `false` | Yetim temizleme onayında admin parolası istensin mi |
| `orphan_cleanup_approve` | `null` | Tek tıkla toplu temizleme onay tokenı |
| `trash_bulk_approve` | `null` | Toplu çöp kutusu işlem onay tokenı |
| `trash_token_attempts` | `[]` | Kaba kuvvet koruması: hata sayacı (JSON) |

### Sağlık uyarıları (eşikler)

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `health_warn_error_logs` | `20` | error_logs tablosunda bu kadar satır olunca uyarı |
| `health_warn_email_queue` | `50` | email_outbox kuyruğunda bu kadar bekleyen olunca uyarı |
| `health_warn_webhook_fail` | `10` | Son 24 saatte bu kadar başarısız webhook olunca uyarı |
| `health_warn_ical_fail` | `3` | Son 24 saatte bu kadar başarısız iCal senkron olunca uyarı |

### Hazırlık (readiness)

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `readiness_all_auto_open` | `false` | Hazırlık skoru eşik altındaysa tüm bölümleri otomatik aç |
| `readiness_all_auto_open_threshold` | `70` | Otomatik açma eşiği (skor bu değerin altındaysa) |

### Görsel & AI

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `gemini_visual_similarity_threshold` | `90` | Görsel benzerlik eşiği (%) — bu aşımda Gemini uyarı üretir |
| `gemini_auto_pause_duplicate` | `false` | Benzer görsel tespit edilince ilanı otomatik duraklat |

### KPS kimlik doğrulama

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `kps_identity_verification_enabled` | `false` | KPS kimlik doğrulama aktif mi |

### Ziyaretçi sohbeti

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `chat_min_length` | `5` | Minimum mesaj uzunluğu (karakter) |
| `chat_require_space` | `true` | Mesajda boşluk olmalı mı (bot koruması) |
| `chat_blocklist` | `[]` | Yasaklı kelimeler listesi (JSON) |
| `chat_topic_instant` | `true` | Konu bazlı anlık yanıtlar aktif mi |
| `chat_topic_responses` | `{}` | Konu bazlı özel yanıtlar (JSON: `{topic: {text, link}}`) |

### SMS (Netgsm)

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `netgsm_sms_enabled` | `false` | SMS gönderimi aktif mi |

### Dağıtım sağlığı (otomatik, haftalık)

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `distribution_health_week` | `''` | Son haftalık özet haftası (otomatik, ör. `2026-W33`) |
| `distribution_health_orphan_history` | `{}` | Haftalık yetim sayısı tarihçesi (JSON) |
| `distribution_health_pending_history` | `{}` | Haftalık onay bekleyen öneri tarihçesi (JSON) |
| `distribution_health_plan_missing_history` | `{}` | Haftalık planı eksik eşleştirme tarihçesi (JSON) |
| `orphan_daily_channel_history` | `{}` | Günlük kanal bazında yetim tarihçesi (JSON) |

### Otomatik sistem (otomatik, ReadOnly)

| Anahtar | Varsayılan | Açıklama |
|---|---|---|
| `tick_token` | `''` | tick.php URL erişim belirteci (otomatik üretilir) |
| `scheduler_last_tick_at` | `''` | Son başarılı tick zamanı (otomatik) |
| `last_alert_test_at` | `''` | Son test e-postası çalışma zamanı |
| `last_alert_test_channels` | `0` | Son testte doğrulanan kanal sayısı |
| `last_alert_test_code` | `''` | Son test doğrulama kodu |
| `last_alert_test_mode` | `''` | Son test modu (`send`/`dry`) |
| `last_alert_test_status` | `''` | Son test durumu (`delivered`/`pending`/`missed`) |
| `last_alert_test_reason` | `''` | Son test durum nedeni |
| `last_alert_test_delivered_code` | `''` | Teslim edilen kod (doğrulama) |
| `last_alert_test_delivered_at` | `''` | Teslim tarihi |
| `last_alert_test_retry_at` | `''` | Son otomatik yeniden deneme zamanı |
| `last_alert_test_retry_count` | `0` | Toplam yeniden deneme sayısı |
| `last_webhook_smoke_test` | `null` | Son webhook smoke test sonucu (JSON) |
| `alert_test_history` | `[]` | Test koşu tarihçesi (son 20, JSON) |
| `panel_weekly_digest` | `{}` | Haftalık panel özeti katılımcıları (JSON) |

### Komutla yönetim

```bash
# Tek ayarı oku
/opt/plesk/php/8.5/bin/php -r "require 'config/platform_settings.php'; echo var_export(platform_setting('admin_alert_email',''), true);"

# Tek ayarı değiştir
/opt/plesk/php/8.5/bin/php -r "require 'config/platform_settings.php'; require 'config/database.php'; save_platform_setting('channel_webhook_auto_map', false); echo 'OK';"

# Tüm ayarları listele
sudo -u postgres psql -d nexus_traveltech -c "SELECT setting_key, LEFT(setting_value::text, 80) AS value FROM platform_settings ORDER BY setting_key;"

# Denetim kayıtlarını göster
sudo -u postgres psql -d nexus_traveltech -c "SELECT action, details->>'key' AS ayar, details->>'old' AS eski, details->>'new' AS yeni, created_at FROM admin_audit_logs WHERE action='platform.setting_change' ORDER BY id DESC LIMIT 20;"
```

---

*Güncellenme: bu dosya, kullanım kılavuzunun kalıcı kopyasıdır; komutlardaki değişiklikler
kod güncellemeleriyle birlikte buraya da işlenmelidir.*
