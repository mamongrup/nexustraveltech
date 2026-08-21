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

### 8.9 Sunucu kodu güncellemiyor (eski hash) — kapsamlı sorun giderme

**Belirti:** `git fetch` çalışıyor ama `git log --oneline -1` eski bir commit gösteriyor; yeni özellikler sunucuda görünmüyor.

---

#### Hızlı teşhis (30 saniye)

```bash
cd /var/www/vhosts/nexustraveltech
echo "=== 1) Remote URL ===" && git remote -v
echo "=== 2) GitHub son ref ===" && git ls-remote origin main
echo "=== 3) Local HEAD ===" && git log --oneline -1
echo "=== 4) Shallow? ===" && git rev-parse --is-shallow-repository
echo "=== 5) Proxy? ===" && git config --global --get http.proxy || echo "(yok)"
echo "=== 6) Disk? ===" && df -h . | tail -1
echo "=== 7) Lock? ===" && ls -la .git/refs/heads/main.lock 2>/dev/null || echo "(kilit yok)"
echo "=== 8) Fetch refspec ===" && git config --get remote.origin.fetch
```

---

#### 10 olası kök neden ve çözümleri

**① Yanlış remote URL**

```bash
git remote -v
# Beklenen: https://github.com/mamongrup/nexustraveltech.git
# Yanlışsa:
git remote set-url origin https://github.com/mamongrup/nexustraveltech.git
```

**② Branch adı tutarsız (main vs master vs başka)**

```bash
git branch -r
# Remote'ta "origin/master" var ama siz "origin/main" arıyorsanız fetch sessizce başarısız olur
git fetch origin master:main  # veya remote adını düzeltin
```

**③ Shallow clone (eksik tarihçe)**

```bash
git rev-parse --is-shallow-repository  # "true" dönerse
git fetch --unshallow origin main      # tam tarihçeyi çek
```

**④ Proxy / SSL engeli**

```bash
# Proxy var mı?
git config --global --get http.proxy
env | grep -i proxy

# SSL hatası?
curl -sI https://github.com 2>&1 | head -5

# Çözüm:
git config --global --unset http.proxy                    # proxy'yi kaldır
git -c http.sslVerify=false fetch origin main --force     # geçici SSL bypass
```

**⑤ SSH key hatası (private repo)**

```bash
ssh -T git@github.com                    # yetki testi
# "Permission denied" → SSH key eklenmemiş veya süresi dolmuş
ssh-add -l                               # key listesi
ssh-add ~/.ssh/id_rsa                    # key ekle
# veya HTTPS'ye geç:
git remote set-url origin https://github.com/mamongrup/nexustraveltech.git
```

**⑥ GitHub token süresi dolmuş (HTTPS + token auth)**

```bash
git config --get credential.helper
git config --get remote.origin.url       # https://TOKEN@github.com/... ise
# Yeni token oluştur → credential-helper ile güncelle veya URL'yi yeniden ayarla
git remote set-url origin https://ghp_YENİ_TOKEN@github.com/mamongrup/nexustraveltech.git
```

**⑦ Plesk kendi deploy mekanizmasını kullanıyor**

```bash
# Plesk, deploy ederken kendi klonunu/cartını kullanabilir
# Plesk → Domains → nexustraveltech → Git → "Use remote repository" seçin
# veya Plesk deploy cache'ini temizleyin:
git fetch origin main --force
git reset --hard origin/main
```

**⑧ .git.lock dosyası kalmış**

```bash
ls -la .git/refs/heads/main.lock 2>/dev/null
# Varsa sil:
rm -f .git/refs/heads/main.lock
```

**⑨ Disk dolu**

```bash
df -h . | tail -1
# %100 doluysa:
du -sh .git/          # repo boyutu
git gc --aggressive   # sıkıştırma
```

**⑩ Remote'da commit henüz push edilmemiş (yanlış repo)**

```bash
git ls-remote origin main          # GitHub'daki son SHA
git log --oneline -1               # Local SHA
# Farklıysa → localdeki commit henüz push edilmemiş
git push origin main               # push et
```

---

#### Teşhis akış diyagramı

```
git fetch sessizce çalışıyor ama HEAD eski mi?
  │
  ├─ git ls-remote origin main → GitHub SHA
  │   └─ SHA farklı mı? → ⑩ push edilmemiş
  │
  ├─ git log --oneline -1 → Local SHA
  │   └─ Aynı SHA mı? → fetch başarısız (②③④⑤⑥⑦⑧⑨)
  │
  ├─ git rev-parse --is-shallow-repository → true?
  │   └─ ③ shallow clone → --unshallow
  │
  ├─ git config --get http.proxy → var mı?
  │   └─ ④ proxy → unset
  │
  ├─ git remote -v → doğru URL?
  │   └─ ① yanlış → set-url
  │
  ├─ .git/refs/heads/main.lock var mı?
  │   └─ ⑧ kilit → sil
  │
  └─ df -h → disk dolu mu?
      └─ ⑨ → gc --aggressive veya temizle
```

---

#### Tek komutluk onarım (sunucuda)

```bash
cd /var/www/vhosts/nexustraveltech

# 1) Teşhis
echo "Remote: $(git remote get-url origin)"
echo "GitHub: $(git ls-remote origin main 2>/dev/null | head -1)"
echo "Local:  $(git log --oneline -1)"
echo "Shallow: $(git rev-parse --is-shallow-repository)"

# 2) Düzeltme
git remote set-url origin https://github.com/mamongrup/nexustraveltech.git
git fetch origin main --force --prune
git reset --hard origin/main
git log --oneline -1
```

---

#### Kalıcı koruma (server-update.sh içinde)

```bash
git fetch origin main --prune
REMOTE_SHA=$(git rev-parse origin/main 2>/dev/null | cut -c1-7)
LOCAL_SHA=$(git rev-parse HEAD | cut -c1-7)
if [ "$REMOTE_SHA" != "$LOCAL_SHA" ]; then
  echo "✗ Fetch başarısız: remote=$REMOTE_SHA local=$LOCAL_SHA"
  echo "  → §8.9 adımlarını çalıştırın"
  exit 1
fi
```


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

## 11) Kanal bağlantısı kurulumu (ilk kez)

Dağıtım merkezinde ilk kanal bağlantısı olmadığında tedarikçi panelinde "$ kanal yok" uyarısı görünür. Aşağıdaki adımlar ilk kanal bağlantısını + oda/plan eşleştirmesini kurar.

### 11.1 Kanal bağlantısı ekleme

```bash
# 1) Mevcut kanal bağlantılarını listele
sudo -u postgres psql -d nexus_traveltech -c "
SELECT id, channel_name, status, supplier_id, property_id
FROM channel_connections ORDER BY id;"

# 2) Yeni kanal bağlantısı oluştur (admin panelinden)
#    Admin → Tedarikçiler → [tedarikçi adı] → Dağıtım merkezi → Yeni kanal bağlantısı
#    veya API ile:
curl -X POST 'https://nexustraveltech.com/admin/api/channel-connection-create.php' \
  -d 'supplier_id=1&property_id=2&channel_name=Booking.com&status=active'

# 3) Bağlantı token'ını al
sudo -u postgres psql -d nexus_traveltech -c "
SELECT id, channel_name, webhook_token, status
FROM channel_connections WHERE property_id=2 ORDER BY id DESC LIMIT 1;"
```

### 11.2 Oda eşleştirmesi (manuel)

```bash
# 1) Oda tipi ve dış kod eşleştirmesi
#    Dağıtım merkezi → Bölüm 3 → Oda eşleştirmesi
#    veya doğrudan SQL:
sudo -u postgres psql -d nexus_traveltech -c "
INSERT INTO channel_room_mappings
  (channel_connection_id, property_id, room_type_id, external_room_id, status)
VALUES (1, 2, 5, 'DELUXE-SEA', 'confirmed')
ON CONFLICT DO NOTHING
RETURNING id, external_room_id, status;"

# 2) Fiyat planı eşleştirmesi (yeni)
sudo -u postgres psql -d nexus_traveltech -c "
INSERT INTO channel_rate_plan_mappings
  (channel_connection_id, property_id, rate_plan_id, external_plan_id, status)
VALUES (1, 2, 8, 'OTA-BB', 'confirmed')
ON CONFLICT DO NOTHING
RETURNING id, external_plan_id, status;"

# 3) Eşleştirme durumunu doğrula
sudo -u postgres psql -d nexus_traveltech -c "
SELECT m.id, m.external_room_id, m.status, r.name AS room_name,
       p.name AS rate_name, m.external_plan_id
FROM channel_room_mappings m
JOIN room_types r ON r.id = m.room_type_id
LEFT JOIN channel_rate_plan_mappings p ON p.channel_connection_id = m.channel_connection_id
  AND p.rate_plan_id = m.rate_plan_id
WHERE m.channel_connection_id = 1
ORDER BY m.id;"
```

### 11.3 Otomatik eşleştirme (öneri akışı)

```bash
# 1) Otomatik öneriyi etkinleştir (varsayılan: açık)
/opt/plesk/php/8.5/bin/php -r "
require 'config/platform_settings.php';
require 'config/database.php';
save_platform_setting('channel_webhook_auto_map', true);
echo 'OK';
"

# 2) Benzerlik eşiğini ayarla (varsayılan: 45)
#    Düşük eşik = daha fazla öneri, yüksek eşik = daha az ama daha güvenilir
/opt/plesk/php/8.5/bin/php -r "
require 'config/platform_settings.php';
require 'config/database.php';
save_platform_setting('channel_webhook_similarity_threshold', 60);
echo 'OK';
"

# 3) Test webhook gönder — eşleşmemiş kod ile öneri oluşmalı
CONNECTION_ID=1
PROPERTY_ID=2
curl -s -X POST "https://nexustraveltech.com/api/channel-webhook.php?connection_id=${CONNECTION_ID}&property_id=${PROPERTY_ID}" \
  -H 'Content-Type: application/json' \
  -d '{"action":"inventory_update","room_code":"YENI-ODA-TEST","plan_code":"BB","currency":"EUR","prices":[{"date":"'$(date -d '+1 day' +%Y-%m-%d 2>/dev/null || date -v+1d +%Y-%m-%d)'","price":100,"allotment":10}]}'

# 4) Öneriyi onayla
SUGGESTED_ID=$(sudo -u postgres psql -d nexus_traveltech -t -c "
SELECT id FROM channel_room_mappings WHERE status='suggested' ORDER BY id DESC LIMIT 1;" | tr -d '[:space:]')
if [ -n "$SUGGESTED_ID" ]; then
  sudo -u postgres psql -d nexus_traveltech -c "
  UPDATE channel_room_mappings
  SET status='confirmed',
      approved_by_type='admin',
      approved_by_name='manuel',
      approved_at=now()
  WHERE id=$SUGGESTED_ID
  RETURNING id, external_room_id, status;"
fi
```

### 11.4 Dosya yapısı özeti

| Dosya | Amaç |
|---|---|
| `api/channel-webhook.php` | Webhook yükü alma + uygulama |
| `cron/process-channel-webhooks.php` | Kuyruktaki yükleri işleme |
| `tedarikci/dagitim-merkezi.php` | Eşleştirme UI (bölm 1-4) |
| `config/health.php` | Yetim tarama + onarım mantığı |
| `channel_room_mappings` | Oda eşleştirme tablosu |
| `channel_rate_plan_mappings` | Fiyat planı eşleştirme tablosu |
| `channel_sync_logs` | Webhook iş logu |

---

## 12) Tek kopyala-yapıştır: güncelleme + migration + repair + webhook test

Tüm sunucu güncelleme akışını tek blokta çalıştırın. Her adımda çıkış kodunu kontrol eder; hata olursa durur ve raporlar.

```bash
#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# NEXUS — Tek kopyala-yapıştır tam kurulum + doğrulama
# Kullanım: Sunucu SSH → Paste → Enter
# ═══════════════════════════════════════════════════════════════════════════

set -euo pipefail
PHP="/opt/plesk/php/8.5/bin/php"
DB="nexus_traveltech"
cd /var/www/vhosts/nexustraveltech
FAIL=0

ok()   { echo "  ✓ $1"; }
fail() { echo "  ✗ $1 — DURduruldu"; FAIL=1; }
warn() { echo "  ⚠ $1"; }

# ── ADIM 1: Git fetch + reset ──
echo ""
echo "══════════════════════════════════════════════════════════════"
echo " 1/7  KOD GÜNCELLEME"
echo "══════════════════════════════════════════════════════════════"
git fetch origin main --prune
EXPECTED=$(curl -s "https://api.github.com/repos/$(git config --get remote.origin.url | sed 's#.*github.com[:/]##;s#\.git##')/commits?sha=main&per_page=1" 2>/dev/null | grep -oP '"sha":"[a-f0-9]+"' | head -1 | cut -d'"' -f4)
ACTUAL=$(git rev-parse origin/main)
if [ -n "$EXPECTED" ] && [ "$ACTUAL" != "$EXPECTED" ]; then
  fail "Fetch başarısız — beklenen: ${EXPECTED:0:7}, bulunan: ${ACTUAL:0:7}"
else
  ok "Remote main: ${ACTUAL:0:7}"
fi
[ $FAIL -eq 1 ] && { echo "══════════════════════════════════════════════════════════════"; exit 1; }

PREV=$(git log --oneline -1)
git reset --hard origin/main
POST=$(git log --oneline -1)
ok "$PREV → $POST"

# ── ADIM 2: Sahiplik devri ──
echo ""
echo "══════════════════════════════════════════════════════════════"
echo " 2/7  SAHİPLİK DEVRİ"
echo "══════════════════════════════════════════════════════════════"
APP_DB_USER=$(grep -oP "'db_user'\s*=>\s*'\K[^']+" config/secrets.php)
BAD_COUNT=$(sudo -u postgres psql -d $DB -t -c \
  "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tableowner='postgres';" \
  | tr -d '[:space:]')
if [ "$BAD_COUNT" = "0" ]; then
  ok "Tüm tablolar zaten app kullanıcısında ($APP_DB_USER)"
else
  warn "$BAD_COUNT tablo hâlâ postgres sahipli — devrediliyor..."
  sudo -u postgres psql -d $DB -c "
    DO \$\$ DECLARE r RECORD;
    BEGIN
      FOR r IN SELECT tablename FROM pg_tables WHERE schemaname='public' AND tableowner='postgres' LOOP
        EXECUTE format('ALTER TABLE public.%I OWNER TO $APP_DB_USER', r.tablename);
        RAISE NOTICE 'devredildi: %', r.tablename;
      END LOOP;
    END \$\$;"
  AFTER=$(sudo -u postgres psql -d $DB -t -c \
    "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tableowner='postgres';" \
    | tr -d '[:space:]')
  [ "$AFTER" = "0" ] && ok "Sahiplik devri tamam" || fail "$AFTER tablo hâlâ postgres sahipli"
fi
[ $FAIL -eq 1 ] && { echo "══════════════════════════════════════════════════════════════"; exit 1; }

# ── ADIM 3: Migration uygula ──
echo ""
echo "══════════════════════════════════════════════════════════════"
echo " 3/7  MIGRATION"
echo "══════════════════════════════════════════════════════════════"
bash scripts/apply-migrations-postgres.sh 2>&1 | tail -15
MIG_ERR=${PIPESTATUS[0]}
[ $MIG_ERR -eq 0 ] && ok "Migration tamam" || fail "Migration hatası (çıkış: $MIG_ERR)"

# ── ADIM 4: Health-check --repair --orphans ──
echo ""
echo "══════════════════════════════════════════════════════════════"
echo " 4/7  HEALTH-CHECK + REPAIR"
echo "══════════════════════════════════════════════════════════════"
$PHP scripts/health-check.php --repair --backup-schema --yes --orphans 2>&1 | tail -20
HC_ERR=${PIPESTATUS[0]}
[ $HC_ERR -eq 0 ] && ok "Health-check tamam" || warn "Health-check uyarıları var (çıkış: $HC_ERR)"

# ── ADIM 5: channel_room_mappings onarımı ──
echo ""
echo "══════════════════════════════════════════════════════════════"
echo " 5/7  EŞLEŞTİRME ONARIMI"
echo "══════════════════════════════════════════════════════════════"
$PHP -r "
\$pdo = new PDO('pgsql:host=localhost;dbname=$DB', 'postgres', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo 'channel_room_mappings: ';
\$n = (int)\$pdo->query('SELECT count(*) FROM channel_room_mappings')->fetchColumn();
echo \$n . ' satır, ';
\$cols = \$pdo->query(\"SELECT column_name FROM information_schema.columns WHERE table_name='channel_room_mappings' AND table_schema='public'\")->fetchAll(PDO::FETCH_COLUMN);
echo count(\$cols) . ' kolon';
\$expected = ['id','channel_connection_id','property_id','room_type_id','external_room_id','rate_plan_id','status','suggested_at','suggestion_count','suggestion_score','approved_by_type','approved_by_name','approved_by_user_id','approved_at'];
\$missing = array_diff(\$expected, \$cols);
if (\$missing) { echo ' — EKSİK: ' . implode(', ', \$missing); exit(1); }
echo ' ✓' . PHP_EOL;
echo 'channel_rate_plan_mappings: ';
\$n2 = (int)\$pdo->query('SELECT count(*) FROM channel_rate_plan_mappings')->fetchColumn();
echo \$n2 . ' satır, ';
\$cols2 = \$pdo->query(\"SELECT column_name FROM information_schema.columns WHERE table_name='channel_rate_plan_mappings' AND table_schema='public'\")->fetchAll(PDO::FETCH_COLUMN);
echo count(\$cols2) . ' kolon ✓' . PHP_EOL;
" 2>&1
ok "Eşleştirme tabloları doğrulandı"

# ── ADIM 6: Webhook uçtan uca test ──
echo ""
echo "══════════════════════════════════════════════════════════════"
echo " 6/7  WEBHOOK UÇTAN UCA TEST"
echo "══════════════════════════════════════════════════════════════"

CONN_ID=$(sudo -u postgres psql -d $DB -t -c \
  "SELECT id FROM channel_connections WHERE status='active' ORDER BY id LIMIT 1;" | tr -d '[:space:]')

if [ -z "$CONN_ID" ]; then
  warn "Aktif kanal bağlantısı yok — webhook testi atlandı"
  warn "Kanal bağlantısı kurmak için §11'e bakın"
else
  PROP_ID=$(sudo -u postgres psql -d $DB -t -c \
    "SELECT property_id FROM channel_connections WHERE id=$CONN_ID;" | tr -d '[:space:]')

  # 6a: Eşlenmemiş kod ile POST
  TEST_CODE="DEPLOY-$(date +%s)-$(openssl rand -hex 3)"
  TOMORROW=$(date -d "+1 day" +%Y-%m-%d 2>/dev/null || date -v+1d +%Y-%m-%d)
  echo "  POST → kod: $TEST_CODE"

  RESP=$(curl -s -w "\n%{http_code}" -X POST \
    "https://nexustraveltech.com/api/channel-webhook.php?connection_id=${CONN_ID}&property_id=${PROP_ID}" \
    -H 'Content-Type: application/json' \
    -d "{\"action\":\"inventory_update\",\"room_code\":\"${TEST_CODE}\",\"plan_code\":\"BB\",\"currency\":\"EUR\",\"prices\":[{\"date\":\"${TOMORROW}\",\"price\":99,\"allotment\":10,\"min_stay\":1,\"stop_sale\":false}]}")

  CODE=$(echo "$RESP" | tail -1)
  [ "$CODE" = "200" ] && ok "POST 200" || fail "POST $CODE"

  # 6b: İşleyiciyi çalıştır
  $PHP cron/process-channel-webhooks.php 2>&1 | tail -3
  ok "İşleyici çalıştı"

  # 6c: Öneri oluştu mu?
  SUGGESTED=$(sudo -u postgres psql -d $DB -t -c \
    "SELECT count(*) FROM channel_room_mappings WHERE status='suggested';" | tr -d '[:space:]')
  [ "$SUGGESTED" != "0" ] && ok "$SUGGESTED öneri bekliyor" || warn "Öneri oluşmadı (auto_map kapalı olabilir)"

  # 6d: sync_log son durum
  LAST=$(sudo -u postgres psql -d $DB -t -c \
    "SELECT status FROM channel_sync_logs ORDER BY id DESC LIMIT 1;" | tr -d '[:space:]')
  echo "  sync_log son durum: $LAST"
fi

# ── ADIM 7: auto-test ──
echo ""
echo "══════════════════════════════════════════════════════════════"
echo " 7/7  AUTO-TEST"
echo "══════════════════════════════════════════════════════════════"
$PHP scripts/auto-test.php --verbose 2>&1 | grep -E "✓|✗|PASS|FAIL|WARN|modül" | tail -10
AT_ERR=${PIPESTATUS[0]}
[ $AT_ERR -eq 0 ] && ok "auto-test tamam" || warn "auto-test uyarıları var"

# ── SONUÇ ──
echo ""
echo "══════════════════════════════════════════════════════════════"
if [ $FAIL -eq 0 ]; then
  echo " ✓ TÜM ADIMLAR BAŞARILI"
else
  echo " ✗ HATALAR VAR — yukarıdaki ✗ satırlarını kontrol edin"
fi
echo "══════════════════════════════════════════════════════════════"
exit $FAIL
```

### Hızlı referans

| Adım | Ne yapıyor | Başarısızsa |
|---|---|---|
| 1 | `git fetch + reset --hard` | §8.9 (eski hash) |
| 2 | Tablo sahipliğini app'e devret | §8.1 (must be owner) |
| 3 | `apply-migrations-postgres.sh` | §8.1 (migration hataları) |
| 4 | `health-check --repair --orphans` | §8.6 (yetim/silme) |
| 5 | Şema doğrulama (14+10 kolon) | §11 (eşleştirme kurulumu) |
| 6 | Webhook POST → öneri → fiyat | §11.3 (otomatik eşleştirme) |
| 7 | `auto-test.php --verbose` | §8.2-8.8 (ilgili bölüm) |
---

## 13) Webhook ilk kurulum (bağlantı boşsa)

Dağıtım merkezinde kanal bağlantısı yoksa bu adımları izleyin. Blok sonunda bağlantıyı, oda/plan eşleştirmelerini ve test webhook'unu tek seferde kurarsınız.

```bash
#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# NEXUS — Webhook ilk kurulum (bağlantı boşken)
# ═══════════════════════════════════════════════════════════════════════════

PHP="/opt/plesk/php/8.5/bin/php"
DB="nexus_traveltech"
cd /var/www/vhosts/nexustraveltech

# ── 0) Durum kontrolü ──
echo "═══ Bağlantı durumu ═══"
sudo -u postgres psql -d $DB -c "
SELECT count(*) AS toplam,
       count(*) FILTER (WHERE status='active') AS aktif
FROM channel_connections;"

COUNT=$(sudo -u postgres psql -d $DB -t -c \
  "SELECT count(*) FROM channel_connections;" | tr -d '[:space:]')

if [ "$COUNT" != "0" ]; then
  echo "✓ Zaten $COUNT bağlantı var:"
  sudo -u postgres psql -d $DB -c \
    "SELECT id, channel_name, status, property_id FROM channel_connections;"
  exit 0
fi

# ═══════════════════════════════════════════════════════════════════════════
# ADIM 1: Tedarikçi + ürün listesi
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "═══ ADIM 1: Tedarikçi + ürün ═══"

echo "=== Tedarikçiler ==="
sudo -u postgres psql -d $DB -c "
SELECT s.id, s.company_name,
       (SELECT count(*) FROM properties
        WHERE supplier_id=s.id AND status='active') AS urunler
FROM suppliers s WHERE s.status='active' ORDER BY s.id;"

echo "=== Ürünler ==="
sudo -u postgres psql -d $DB -c "
SELECT p.id, p.name, p.property_type,
       (SELECT count(*) FROM room_types
        WHERE property_id=p.id AND status='active') AS oda,
       (SELECT count(*) FROM rate_plans
        WHERE property_id=p.id AND status='active') AS plan
FROM properties p WHERE p.status='active' ORDER BY p.supplier_id, p.id;"

echo ""
read -p "  Supplier ID [varsayılan: ilk aktif]: " SUPPLIER_ID
read -p "  Property ID [varsayılan: ilk aktif]: " PROP_ID
read -p "  Kanal adı [varsayılan: Booking.com]: " CH_NAME

SUPPLIER_ID=${SUPPLIER_ID:-$(sudo -u postgres psql -d $DB -t -c \
  "SELECT id FROM suppliers WHERE status='active' ORDER BY id LIMIT 1;" \
  | tr -d '[:space:]')}
PROP_ID=${PROP_ID:-$(sudo -u postgres psql -d $DB -t -c \
  "SELECT id FROM properties WHERE status='active' ORDER BY id LIMIT 1;" \
  | tr -d '[:space:]')}
CH_NAME=${CH_NAME:-Booking.com}

echo "  → Supplier: $SUPPLIER_ID | Property: $PROP_ID | Kanal: $CH_NAME"

# ═══════════════════════════════════════════════════════════════════════════
# ADIM 2: Kanal bağlantısı oluştur
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "═══ ADIM 2: Kanal bağlantısı ═══"

TOKEN=$(openssl rand -hex 32)
CONN_ID=$(sudo -u postgres psql -d $DB -t -c "
INSERT INTO channel_connections
  (supplier_id, property_id, channel_name, webhook_token, status, created_at)
VALUES ($SUPPLIER_ID, $PROP_ID, '$CH_NAME', '$TOKEN', 'active', now())
RETURNING id;" | tr -d '[:space:]')

echo "  ✓ Bağlantı #$CONN_ID oluşturuldu"
echo "  Token: ${TOKEN:0:16}…"
echo "  Webhook URL:"
echo "  POST https://nexustraveltech.com/api/channel-webhook.php?connection_id=${CONN_ID}&property_id=${PROP_ID}"
echo "  ⚠ Bu URL'yi kanal paneline (Booking.com/Expedia/vb.) girin"

# ═══════════════════════════════════════════════════════════════════════════
# ADIM 3: Oda tipleri + fiyat planlarını listele
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "═══ ADIM 3: Oda + plan listesi ═══"

echo "=== Oda tipleri ==="
sudo -u postgres psql -d $DB -c "
SELECT id, name, status FROM room_types
WHERE property_id=$PROP_ID AND status='active' ORDER BY id;"

echo "=== Fiyat planları ==="
sudo -u postgres psql -d $DB -c "
SELECT id, name, currency, board_type FROM rate_plans
WHERE property_id=$PROP_ID AND status='active' ORDER BY id;"

# ═══════════════════════════════════════════════════════════════════════════
# ADIM 4: Oda eşleştirmesi
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "═══ ADIM 4: Oda eşleştirmesi ═══"
echo "Her oda tipi için kanal panelindeki dış kodu girin (boş = atla)"
echo ""

for RID in $(sudo -u postgres psql -d $DB -t -c \
  "SELECT id FROM room_types WHERE property_id=$PROP_ID AND status='active' ORDER BY id;" \
  | tr -d '[:space:]'); do
  RNAME=$(sudo -u postgres psql -d $DB -t -c \
    "SELECT name FROM room_types WHERE id=$RID;" | tr -d '[:space:]')
  read -p "  #$RID $RNAME → dış kod: " EXT
  if [ -n "$EXT" ]; then
    sudo -u postgres psql -d $DB -c "
    INSERT INTO channel_room_mappings
      (channel_connection_id, property_id, room_type_id,
       external_room_id, status, approved_by_type, approved_by_name, approved_at)
    VALUES ($CONN_ID, $PROP_ID, $RID, '$EXT', 'confirmed', 'admin', 'kurulum', now())
    ON CONFLICT DO NOTHING RETURNING id, external_room_id;"
    echo "    ✓ $RNAME → $EXT"
  else
    echo "    ⏭ atlandı"
  fi
done

# ═══════════════════════════════════════════════════════════════════════════
# ADIM 5: Fiyat planı eşleştirmesi
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "═══ ADIM 5: Fiyat planı eşleştirmesi ═══"
echo ""

for PID2 in $(sudo -u postgres psql -d $DB -t -c \
  "SELECT id FROM rate_plans WHERE property_id=$PROP_ID AND status='active' ORDER BY id;" \
  | tr -d '[:space:]'); do
  PNAME=$(sudo -u postgres psql -d $DB -t -c \
    "SELECT name FROM rate_plans WHERE id=$PID2;" | tr -d '[:space:]')
  read -p "  #$PID2 $PNAME → dış plan kodu (boş = atla): " EXTPLAN
  if [ -n "$EXTPLAN" ]; then
    sudo -u postgres psql -d $DB -c "
    INSERT INTO channel_rate_plan_mappings
      (channel_connection_id, property_id, rate_plan_id,
       external_plan_id, status, approved_by_type, approved_by_name, approved_at)
    VALUES ($CONN_ID, $PROP_ID, $PID2, '$EXTPLAN', 'confirmed', 'admin', 'kurulum', now())
    ON CONFLICT DO NOTHING RETURNING id, external_plan_id;"
    echo "    ✓ $PNAME → $EXTPLAN"
  else
    echo "    ⏭ atlandı"
  fi
done

# ═══════════════════════════════════════════════════════════════════════════
# ADIM 6: Doğrulama
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "═══ ADIM 6: Doğrulama ═══"

echo "=== Bağlantı ==="
sudo -u postgres psql -d $DB -c "
SELECT id, channel_name, status, left(webhook_token,16)||'…' AS token
FROM channel_connections WHERE id=$CONN_ID;"

echo "=== Oda eşleştirmeleri ==="
sudo -u postgres psql -d $DB -c "
SELECT m.id, r.name AS oda, m.external_room_id AS dis_kod, m.status
FROM channel_room_mappings m JOIN room_types r ON r.id=m.room_type_id
WHERE m.channel_connection_id=$CONN_ID ORDER BY m.id;"

echo "=== Fiyat planı eşleştirmeleri ==="
sudo -u postgres psql -d $DB -c "
SELECT m.id, rp.name AS plan, m.external_plan_id AS dis_kod, m.status
FROM channel_rate_plan_mappings m JOIN rate_plans rp ON rp.id=m.rate_plan_id
WHERE m.channel_connection_id=$CONN_ID ORDER BY m.id;"

# ═══════════════════════════════════════════════════════════════════════════
# ADIM 7: Test webhook
# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "═══ ADIM 7: Test webhook ═══"

TEST_CODE=$(sudo -u postgres psql -d $DB -t -c "
SELECT external_room_id FROM channel_room_mappings
WHERE channel_connection_id=$CONN_ID AND status='confirmed'
ORDER BY id LIMIT 1;" | tr -d '[:space:]')

TOMORROW=$(date -d "+1 day" +%Y-%m-%d 2>/dev/null || date -v+1d +%Y-%m-%d)

if [ -n "$TEST_CODE" ]; then
  echo "  Eşleşmiş kod: $TEST_CODE"
  RESP=$(curl -s -w "\n%{http_code}" -X POST \
    "https://nexustraveltech.com/api/channel-webhook.php?connection_id=${CONN_ID}&property_id=${PROP_ID}" \
    -H 'Content-Type: application/json' \
    -d "{\"action\":\"inventory_update\",\"room_code\":\"${TEST_CODE}\",\"plan_code\":\"BB\",\"currency\":\"EUR\",\"prices\":[{\"date\":\"${TOMORROW}\",\"price\":150,\"allotment\":20,\"min_stay\":1,\"stop_sale\":false}]}")

  echo "  HTTP: $(echo "$RESP" | tail -1)"
  echo "  Yanıt: $(echo "$RESP" | head -n -1)"

  $PHP cron/process-channel-webhooks.php 2>&1 | tail -1

  echo ""
  echo "=== sync_log ==="
  sudo -u postgres psql -d $DB -c "
  SELECT id, status, room_code_sent, mapped_room_type_id, created_at
  FROM channel_sync_logs ORDER BY id DESC LIMIT 3;"

  echo "=== inventory_calendar ==="
  sudo -u postgres psql -d $DB -c "
  SELECT stay_date, base_price, allotment, sold
  FROM inventory_calendar ORDER BY stay_date DESC LIMIT 5;"
else
  echo "  ⚠ Eşleşmiş kod yok — test atlandı"
fi

# ═══════════════════════════════════════════════════════════════════════════
echo ""
echo "════════════════════════════════════════════════════════════════════════"
echo " ✓ KURULUM TAMAMLANDI"
echo "════════════════════════════════════════════════════════════════════════"
echo "  Bağlantı: #$CONN_ID ($CH_NAME)"
echo "  Ürün:     #$PROP_ID"
echo "  Webhook:  https://nexustraveltech.com/api/channel-webhook.php?connection_id=${CONN_ID}&property_id=${PROP_ID}"
echo "  Token:    ${TOKEN:0:16}…"
echo ""
echo "  Sonraki adımlar:"
echo "    1. Webhook URL'yi kanal paneline girin"
echo "    2. Otomatik eşleştirmeyi açın:"
echo "       Kontrol merkezi → channel_webhook_auto_map = true"
echo "    3. Dağıtım merkezini kontrol edin:"
echo "       tedarikci/dagitim-merkezi.php?product=${PROP_ID}"
echo "════════════════════════════════════════════════════════════════════════"
```

### Akış diyagramı

```
Bağlantı var mı?
  ├─ EVET → listele, dur
  └─ HAYIR ↓
1) Tedarikçi + ürün listesi (hangi ürüne hangi kanal?)
2) channel_connections INSERT (token + webhook URL üret)
3) room_types + rate_plans listesi (kullanıcıya göster)
4) Her oda tipi → dış kod gir → INSERT
5) Her fiyat planı → dış kod gir → INSERT
6) 3 tabloyu sorgula → doğrula
7) Test webhook gönder → sync_log + inventory_calendar kontrol
```

### Beklenen çıktı

```
═══ ADIM 1: Tedarikçi + ürün ═══
  → Supplier: 1 | Property: 2 | Kanal: Booking.com
═══ ADIM 2: Kanal bağlantısı ═══
  ✓ Bağlantı #5 oluşturuldu
  Webhook URL: POST https://…connection_id=5&property_id=2
═══ ADIM 4: Oda eşleştirmesi ═══
  #12 Deluxe Sea → DELUXE-SEA
  ✓ Deluxe Sea → DELUXE-SEA
═══ ADIM 6: Doğrulama ═══
  channel_room_mappings: 1 satır ✓
  channel_rate_plan_mappings: 1 satır ✓
═══ ADIM 7: Test webhook ═══
  HTTP: 200
  sync_log son durum: succeeded
════════════════════════════════════════════════════════════════════════
 ✓ KURULUM TAMAMLANDI
```

### İlişkili bölümler

| Konu | Bölüm |
|---|---|
| Kanal bağlantısı kurulumu detayı | §11 |
| Otomatik eşleştirme (öneri akışı) | §11.3 |
| Webhook uçtan uca test (bağlantı varsa) | §5 |
| Tek komut tam kurulum (deploy + repair + test) | §12 |
| Eşleşme sorunları | §8.6 |
| Dağıtım merkezi kullanım kılavuzu | §11.4 |
---

## 14) Webhook e2e test (komutlar + beklenen çıktılar)

İki bağımsız betik webhook uçtan uca akışını test eder. Her ikisi de `--e2e` bayrağıyla aynı anda hem onaylı eşleştirme hem öneri akışını çalıştırır.

### 14.1 auto-test.php --e2e

Tüm modülleri (DB, migration, zamanlayıcı, kanal-webhook, kur, ical, e-posta) + webhook e2e testini tek komutta çalıştırır.

```bash
# Tam test (tüm kapsamlar + öneri akışı)
/opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e --verbose

# Tek kapsam seçerek test
/opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e --scope rates
/opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e --scope availability
/opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e --scope restrictions

# JSON çıktısı (CI/otomasyon için)
/opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e --json

# Test satırlarını silme
/opt/plesk/php/8.5/bin/php scripts/auto-test.php --e2e --keep
```

**Beklenen çıktı (tam test):**

```
── E2E webhook testi (--e2e) ──
  ✓ aktif kanal: Booking.com (BK, id=1, token 64 hex)
  ✓ ürün eşleştirmesi: Otel Premier (hotel, id=2)
  ✓ aktif oda tipi: Deluxe Sea View
  ✓ aktif fiyat planı: Oda Kahvaltı · EUR
  ✓ kur kapsaması: EUR→TRY mevcut (kur 35.1234)
  ✓ POST rates (curl): kuyruğa alındı
  ✓ uygula rates: log #45 success · 1 gün · fx_audit:1 dönüşüm
  ✓ POST availability (curl): kuyruğa alındı
  ✓ uygula availability: log #46 success · 1 gün
  ✓ POST restrictions (curl): kuyruğa alındı
  ✓ uygula restrictions: log #47 success · 1 gün
  ✓ takvim yazımı: 123.45 EUR → 4335.73 TRY (beklenen 4335.73, kur 35.1234)
  ✓ temizlik: test satırları silindi

── E2E öneri akışı (--e2e) ──
  ✓ auto_map: açık
  ✓ POST (tanınmayan kod): kuyruğa alındı (kod E2E-SUG-A1B2C3D4)
  ✓ işleyici: log #48 success
  ✓ öneri oluştu: E2E-SUG-A1B2C3D4 → oda #12 (skor %72)
  ✓ tedarikçi bildirimi: 1 kayıt
  ✓ öneri onaylandı: E2E-SUG-A1B2C3D4 → confirmed
  ✓ takvim yazımı: 220.00 TRY
  ✓ fx_audit: 1 dönüşüm kaydı
  ✓ temizlik: test satırları silindi
```

**Beklenen çıktı (yalnızca rates):**

```
── E2E webhook testi (--e2e) ──
  ✓ aktif kanal: Booking.com (BK, id=1)
  ✓ POST rates (curl): kuyruğa alındı
  ✓ uygula rates: log #50 success · 1 gün · fx_audit:1 dönüşüm
  ✓ takvim yazımı: 123.45 EUR → 4335.73 TRY
  ✓ temizlik: test satırları silindi

── E2E öneri akışı (--e2e) ──
  ✓ auto_map: açık
  ✓ POST (tanınmayan kod): kuyruğa alındı
  ✓ işleyici: log #51 success
  ✓ öneri oluştu: E2E-SUG-... → oda #12 (skor %72)
  ✓ tedarikçi bildirimi: 1 kayıt
  ✓ öneri onaylandı: confirmed
  ✓ takvim yazımı: 220.00 TRY
  ✓ fx_audit: 1 dönüşüm kaydı
  ✓ temizlik: test satırları silindi
```

### 14.2 webhook-e2e-test.php

Yalnızca **öneri akışını** test eder (tanınmayan kod → suggestion → confirmed → fiyat yazma). Daha ayrıntılı çıktıyla tek başına çalıştırılabilir.

```bash
# Tam test
/opt/plesk/php/8.5/bin/php scripts/webhook-e2e-test.php

# HTTP olmadan (doğrudan kuyruğa ekle)
/opt/plesk/php/8.5/bin/php scripts/webhook-e2e-test.php --no-http

# Belirli bir plan kodu ile
/opt/plesk/php/8.5/bin/php scripts/webhook-e2e-test.php --plan-code OTA-BB

# Test satırlarını silme
/opt/plesk/php/8.5/bin/php scripts/webhook-e2e-test.php --keep
```

**Beklenen çıktı:**

```
=== 1) ÖN KOŞULLAR ===
  ✓ gerekli tablolar mevcut (10/10)
  ✓ channel_sync_logs.fx_audit mevcut (migration 048)
  ✓ channel_room_mappings.suggestion_score mevcut (migration 045+)
  ✓ channel_webhook_auto_map AÇIK (öneri akışı etkin)
  ✓ aktif kanal: Booking.com (BK, id=1, token 64 hex)
  ✓ ürün eşleştirmesi: Otel Premier (hotel, id=2)
  ✓ aktif oda tipi: Deluxe Sea View
  ✓ aktif fiyat planı: Oda Kahvaltı · EUR
  ✓ kur kapsaması: EUR→TRY mevcut (kur 35.1234)

=== 2) YÜK GÖNDER — tanınmayan kod ile rates ===
  ✓ kuyruğa eklendi (kod E2E-A1B2C3D4)

=== 3) ÖNERİ OLUŞUMU — tanınmayan kod ===
  ✓ işleyici: log #52 success
  ✓ öneri oluştu: E2E-A1B2C3D4 → oda tipi #12 (suggestion_count=1, skor %72)
  ✓ tedarikçi bildirimi oluştu (channel_mapping_suggestion, 1 kayıt)

=== 4) ONAY + YAZIM — suggested → confirmed → takvim ===
  ✓ öneri onaylandı (oda tipi #12 · plan #8 — dağıtım merkezi bölüm 3teki onayın aynısı)
  ✓ takvim yazımı: 210.00 EUR → 7377.91 TRY (beklenen 7377.91, kur 35.1234)

=== 5) RAPOR + TEMİZLİK ===
  ✓ temizlik: test satırları silindi
```

### 14.3 Hangisini kullanmalı?

| Durum | Önerilen betik | Neden |
|---|---|---|
| Günlük CI/otomasyon | `auto-test.php --e2e --json` | Tüm modüller + JSON çıktısı |
| Hızlı doğrulama | `auto-test.php --e2e --scope rates` | Tek kapsam, hızlı |
| Derin öneri testi | `webhook-e2e-test.php` | Ayrıntılı 5 adımlık çıktı |
| CI pipeline | `auto-test.php --e2e --json` | Makinece okunabilir, exit code 0/1 |
| Manuel kontrol | `webhook-e2e-test.php --no-http` | HTTP gerektirmez,_sunucuda doğrudan çalışır |

### 14.4 Hata durumunda § referansları

| Hata | Referans |
|---|---|
| `aktif kanal bağlantısı yok` | §11 (kurulum) veya §13 (ilk kurulum) |
| `ürün eşleştirmesi yok` | §11.2 (oda eşleştirmesi) |
| `auto_map KAPALI` | §10 (kontrol merkezi → channel_webhook_auto_map) |
| `POST başarısız` | §8.9 (sunucu kodu güncellemiyor) veya §8.3 (kuyruk) |
| `suggested öneri bulunamadı` | §8.6 (benzerlik eşiği) |
| `kur eksik` | §8.4 (kur çifti eksik) |
| `takvim yazımı başarısız` | §8.3 (rate_plan_id eşleşmemiş) |
---

*Güncellenme: bu dosya, kullanım kılavuzunun kalıcı kopyasıdır; komutlardaki değişiklikler
kod güncellemeleriyle birlikte buraya da işlenmelidir.*
