<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/payments.php';

/**
 * NEXUS AI asistan motoru — DeepSeek tool-calling ile.
 *
 * Her rol (admin/supplier/agency) için yalnızca güvenli araçlar tanımlıdır:
 * okuma sorguları + küçük, geri alınabilir eylemler. Silme/askıya alma gibi
 * yıkıcı işlemler araçlarda YOKTUR; asistan bunları ilgili sayfaya yönlendirir.
 */

/** Rol başına sistem yönlendirmesi. */
function ai_assistant_system_prompt(string $role, array $ctx): string
{
    $base = "Sen NEXUS TravelTech platformunun yapay zeka asistanısın. Kullanıcının sorularını kısa ve net Türkçe yanıtla. "
        . "Veri gerektiren sorularda önce uygun aracı kullan; asla uydurma rakam verme. "
        . "Yıkıcı işlemler (silme, iptal, askıya alma, kalıcı değişiklik) yapma; kullanıcıyı ilgili panel sayfasına yönlendir ve ne yapacağını açıkla. "
        . "Müsaitlik, teklif, bildirim gibi konularda sayfa bağlantıları öner.";
    if ($role === 'admin') {
        return $base . ' Sen platform yöneticisin; genel istatistikler, acenteler, hatalar ve zamanlayıcı görevleri hakkında bilgi verirsin.';
    }
    if ($role === 'supplier') {
        return $base . ' Sen bir tedarikçinin (otel operatörü) yardımcısısın; günlük ön büro, rezervasyonlar, gelir ve ödeme linkleri konularında yardımcı olursun.';
    }
    return $base . ' Sen bir seyahat acentesinin yardımcısısın; canlı müsaitlik, teklifler, rezervasyon talepleri ve webhooklar konusunda yardımcı olursun.';
}

/**
 * Rol için araç tanımlarını ve işleyicileri döndürür.
 * @return array{defs: array, handlers: array<string, callable>}
 */
function ai_assistant_tools(string $role, array $ctx): array
{
    $defs = [];
    $handlers = [];

    $tool = function (string $name, string $description, array $properties, array $required, callable $handler) use (&$defs, &$handlers): void {
        $defs[] = ['type' => 'function', 'function' => [
            'name' => $name,
            'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
        ]];
        $handlers[$name] = $handler;
    };

    if ($role === 'admin') {
        $tool('platform_summary', 'Platform genel özeti: tedarikçi, acente, ürün, bekleyen onay, hata ve zamanlayıcı sayaçları.', [], [], function (): string {
            $pdo = db();
            $c = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
            $errors = $pdo->query("SELECT COUNT(*) FROM error_logs WHERE status='new'")->fetchColumn();
            return json_encode([
                'tedarikci' => $c('SELECT COUNT(*) FROM suppliers'),
                'acente' => $c('SELECT COUNT(*) FROM agencies'),
                'urun' => $c('SELECT COUNT(*) FROM properties'),
                'bekleyen_tedarikci_onayi' => $c("SELECT COUNT(*) FROM supplier_verifications WHERE review_status='pending'"),
                'bekleyen_acente_onayi' => $c("SELECT COUNT(*) FROM agencies WHERE status='pending'"),
                'acik_hata' => (int) $errors,
                'aktif_zamanlayici' => $c('SELECT COUNT(*) FROM scheduled_jobs WHERE enabled=true'),
            ], JSON_UNESCAPED_UNICODE);
        });
        $tool('agency_list', 'Acente listesi: ünvan, durum, ödeme skoru, kredi limiti.', ['limit' => ['type' => 'integer', 'description' => 'Kaç acente gösterilsin (varsayılan 10)']], [], function (array $a): string {
            $limit = max(1, min(50, (int) ($a['limit'] ?? 10)));
            $q = db()->prepare('SELECT company_name,status,payment_score,credit_limit FROM agencies ORDER BY id DESC LIMIT ?');
            $q->bindValue(1, $limit, PDO::PARAM_INT);
            $q->execute();
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
        $tool('error_log', 'Son hata kayıtları.', ['limit' => ['type' => 'integer', 'description' => 'Kaç kayıt gösterilsin (varsayılan 8)']], [], function (array $a): string {
            $limit = max(1, min(30, (int) ($a['limit'] ?? 8)));
            $q = db()->prepare('SELECT created_at,level,message,status FROM error_logs ORDER BY id DESC LIMIT ?');
            $q->bindValue(1, $limit, PDO::PARAM_INT);
            $q->execute();
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
        $tool('scheduler_jobs', 'Zamanlayıcı görevleri: kod, zamanlama, durum, son çalışma.', [], [], function (): string {
            $out = [];
            foreach (scheduler_jobs() as $j) {
                $out[] = ['code' => $j['code'], 'schedule' => $j['schedule'], 'enabled' => (bool) $j['enabled'], 'last_status' => $j['last_status'], 'last_run_at' => $j['last_run_at'], 'next_run' => scheduler_next_run((string) $j['schedule'])];
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE);
        });
        $tool('scheduler_run', 'Bir zamanlayıcı görevini şimdi çalıştırır.', ['code' => ['type' => 'string', 'description' => 'Görev kodu, örn. nexus-process-emails']], ['code'], function (array $a): string {
            $code = trim((string) ($a['code'] ?? ''));
            $q = db()->prepare('SELECT * FROM scheduled_jobs WHERE code=?');
            $q->execute([$code]);
            $job = $q->fetch();
            if (!$job) return 'Görev bulunamadı. scheduler_jobs aracıyla kodu kontrol edin.';
            $res = scheduler_run_job($job);
            db()->prepare('UPDATE scheduled_jobs SET last_run_at=now(),last_status=?,last_output=?,run_count=run_count+1 WHERE id=?')
                ->execute([$res['status'], mb_substr((string) $res['output'], 0, 2000), $job['id']]);
            return json_encode(['code' => $code, 'status' => $res['status'], 'output' => mb_substr((string) $res['output'], 0, 500)], JSON_UNESCAPED_UNICODE);
        });
    }

    if ($role === 'supplier') {
        $sid = (int) ($ctx['supplier_id'] ?? 0);
        $tool('today_summary', 'Tedarikçinin bugünkü ön büro özeti: her otel için gelen, çıkan, konaklayan, doluluk ve açık görev.', [], [], function () use ($sid): string {
            $pdo = db();
            $out = [];
            $q = $pdo->prepare("SELECT p.id,p.name,COUNT(DISTINCT b.id) FILTER (WHERE b.check_in=CURRENT_DATE AND b.status NOT IN ('cancelled','rejected')) arrivals,COUNT(DISTINCT b.id) FILTER (WHERE b.check_out=CURRENT_DATE AND b.status NOT IN ('cancelled','rejected')) departures,COUNT(DISTINCT b.id) FILTER (WHERE b.booking_status='checked_in') in_house FROM properties p LEFT JOIN supplier_bookings b ON b.property_id=p.id WHERE p.supplier_id=? AND p.property_type='hotel' GROUP BY p.id,p.name ORDER BY p.name");
            $q->execute([$sid]);
            foreach ($q->fetchAll() as $r) {
                $tq = $pdo->prepare('SELECT COALESCE((SELECT COUNT(*) FROM housekeeping_tasks WHERE property_id=? AND status IN (\'open\',\'assigned\',\'in_progress\')),0)+(SELECT COUNT(*) FROM maintenance_tickets WHERE property_id=? AND status IN (\'open\',\'assigned\',\'in_progress\'))');
                $tq->execute([$r['id'], $r['id']]);
                $r['acik_gorev'] = (int) $tq->fetchColumn();
                $out[] = $r;
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE);
        });
        $tool('booking_lookup', 'Rezervasyon detayı: referans veya numara ile.', ['reference' => ['type' => 'string', 'description' => 'Rezervasyon referansı (kısmi de olabilir)']], ['reference'], function (array $a) use ($sid): string {
            $ref = trim((string) ($a['reference'] ?? ''));
            if ($ref === '') return 'Referans belirtin.';
            $q = db()->prepare('SELECT b.booking_reference,b.status,b.booking_status,b.check_in,b.check_out,b.total_amount,b.currency,b.source_code,b.deposit_amount,b.deposit_status,b.cancellation_reason,p.name property_name FROM supplier_bookings b LEFT JOIN properties p ON p.id=b.property_id WHERE b.supplier_id=? AND b.booking_reference ILIKE ? ORDER BY b.id DESC LIMIT 5');
            $q->execute([$sid, '%' . $ref . '%']);
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
        $tool('revenue_week', 'Önümüzdeki 7 günün beklenen geliri (tesis bazında).', [], [], function () use ($sid): string {
            $q = db()->prepare("SELECT p.name,COALESCE(SUM(b.total_amount),0) beklenen FROM properties p LEFT JOIN supplier_bookings b ON b.property_id=p.id AND b.status NOT IN ('cancelled','rejected') AND b.check_in<=CURRENT_DATE+6 AND b.check_out>CURRENT_DATE WHERE p.supplier_id=? GROUP BY p.name ORDER BY p.name");
            $q->execute([$sid]);
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
        $tool('open_requests', 'Bekleyen acente rezervasyon talepleri.', [], [], function () use ($sid): string {
            $q = db()->prepare("SELECT r.id,r.check_in,r.check_out,r.nights,r.total_amount,r.currency,r.guest_first_name,r.guest_last_name,a.company_name FROM agency_booking_requests r LEFT JOIN agencies a ON a.id=r.agency_id WHERE r.supplier_id=? AND r.status='pending' ORDER BY r.id DESC LIMIT 10");
            $q->execute([$sid]);
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
        $tool('create_payment_link', 'Bir rezervasyon için test modunda ödeme linki oluşturur ve URL döndürür.', ['reference' => ['type' => 'string', 'description' => 'Rezervasyon referansı'], 'amount' => ['type' => 'number', 'description' => 'Tutar (EUR)']], ['reference', 'amount'], function (array $a) use ($sid): string {
            $ref = trim((string) ($a['reference'] ?? ''));
            $amount = max(0.01, (float) ($a['amount'] ?? 0));
            $q = db()->prepare('SELECT id FROM supplier_bookings WHERE supplier_id=? AND booking_reference=? AND status NOT IN (\'cancelled\',\'rejected\') LIMIT 1');
            $q->execute([$sid, $ref]);
            $bid = (int) $q->fetchColumn();
            if (!$bid) return 'Rezervasyon bulunamadı.';
            $created = create_payment_link($sid, $bid, $amount, 'EUR', true, 30);
            return json_encode(['url' => $created['url'], 'test_modu' => true], JSON_UNESCAPED_UNICODE);
        });
    }

    if ($role === 'agency') {
        $aid = (int) ($ctx['agency_id'] ?? 0);
        $tool('check_availability', 'Canlı müsaitlik sorgusu: tarih aralığı, yetişkin, isteğe bağlı şehir/bütçe.', [
            'check_in' => ['type' => 'string', 'description' => 'Giriş tarihi YYYY-MM-DD'],
            'check_out' => ['type' => 'string', 'description' => 'Çıkış tarihi YYYY-MM-DD'],
            'adults' => ['type' => 'integer', 'description' => 'Yetişkin sayısı (varsayılan 2)'],
            'city' => ['type' => 'string', 'description' => 'Şehir (opsiyonel)'],
            'max_price' => ['type' => 'number', 'description' => 'Gece başına maksimum fiyat EUR (opsiyonel)'],
        ], ['check_in', 'check_out'], function (array $a) use ($aid): string {
            $ci = (string) ($a['check_in'] ?? '');
            $co = (string) ($a['check_out'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ci) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $co) || $ci >= $co) return 'Geçerli bir tarih aralığı girin.';
            $nights = (int) ((strtotime($co) - strtotime($ci)) / 86400);
            $adults = max(1, min(20, (int) ($a['adults'] ?? 2)));
            $city = trim((string) ($a['city'] ?? ''));
            $maxPrice = max(0, (float) ($a['max_price'] ?? 0));
            $where = "p.status='active' AND s.status IN ('active','pilot') AND i.stay_date>=? AND i.stay_date<? AND i.stop_sale=false AND i.allotment>i.sold AND r.capacity_adults>=?";
            $params = [$ci, $co, $adults];
            if ($city !== '') { $where .= ' AND lower(p.city)=lower(?)'; $params[] = $city; }
            $sql = "SELECT p.id property_id,p.name property_name,p.city,r.name room_name,MIN(i.base_price) price FROM room_types r JOIN properties p ON p.id=r.property_id JOIN suppliers s ON s.id=p.supplier_id JOIN inventory_calendar i ON i.room_type_id=r.id WHERE $where GROUP BY p.id,p.name,p.city,r.name HAVING COUNT(*)=?";
            $params[] = $nights;
            if ($maxPrice > 0) { $sql .= ' AND MIN(i.base_price)<=?'; $params[] = $maxPrice; }
            $sql .= ' ORDER BY price LIMIT 10';
            $q = db()->prepare($sql);
            $q->execute($params);
            $rows = [];
            foreach ($q->fetchAll() as $r) {
                $rows[] = ['tesis' => $r['property_name'], 'sehir' => $r['city'], 'oda' => $r['room_name'], 'gecelik' => (float) $r['price'], 'toplam' => round((float) $r['price'] * $nights, 2)];
            }
            return json_encode($rows, JSON_UNESCAPED_UNICODE);
        });
        $tool('my_bookings', 'Acentenin son rezervasyon talepleri ve durumları.', ['limit' => ['type' => 'integer', 'description' => 'Kaç kayıt (varsayılan 8)']], [], function (array $a) use ($aid): string {
            $limit = max(1, min(30, (int) ($a['limit'] ?? 8)));
            $q = db()->prepare('SELECT r.check_in,r.check_out,r.nights,r.total_amount,r.currency,r.status,b.booking_reference FROM agency_booking_requests r LEFT JOIN supplier_bookings b ON b.id=r.booking_id WHERE r.agency_id=? ORDER BY r.id DESC LIMIT ?');
            $q->bindValue(2, $limit, PDO::PARAM_INT);
            $q->execute([$aid]);
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
        $tool('my_quotes', 'Acentenin son teklifleri.', ['limit' => ['type' => 'integer', 'description' => 'Kaç kayıt (varsayılan 8)']], [], function (array $a) use ($aid): string {
            $limit = max(1, min(30, (int) ($a['limit'] ?? 8)));
            $q = db()->prepare('SELECT quote_number,total_amount,currency,status,valid_until FROM agency_quotes WHERE agency_id=? ORDER BY id DESC LIMIT ?');
            $q->bindValue(2, $limit, PDO::PARAM_INT);
            $q->execute([$aid]);
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
        $tool('my_webhooks', 'Webhook abonelikleri ve teslimat durumları.', [], [], function () use ($aid): string {
            $q = db()->prepare("SELECT s.url,s.status,s.events,COUNT(d.id) teslimat,COUNT(d.id) FILTER (WHERE d.status='failed') hatali FROM webhook_subscriptions s LEFT JOIN webhook_deliveries d ON d.subscription_id=s.id WHERE s.agency_id=? GROUP BY s.id,s.url,s.status,s.events ORDER BY s.id DESC");
            $q->execute([$aid]);
            return json_encode($q->fetchAll(), JSON_UNESCAPED_UNICODE);
        });
    }

    return ['defs' => $defs, 'handlers' => $handlers];
}

/**
 * Panel sohbet kaydı (best-effort) — panel bazlı aylık raporlar ve yönetim görünürlüğü için.
 */
function ai_assistant_log(string $role, array $ctx, string $userMessage, string $reply): void
{
    try {
        if ($role === 'supplier') {
            db()->prepare('INSERT INTO panel_chat_messages(role,supplier_id,user_message,ai_reply) VALUES(?,?,?,?)')
                ->execute([$role, (int) ($ctx['supplier_id'] ?? 0), mb_substr($userMessage, 0, 1000), mb_substr($reply, 0, 3000)]);
        } elseif ($role === 'agency') {
            db()->prepare('INSERT INTO panel_chat_messages(role,agency_id,user_message,ai_reply) VALUES(?,?,?,?)')
                ->execute([$role, (int) ($ctx['agency_id'] ?? 0), mb_substr($userMessage, 0, 1000), mb_substr($reply, 0, 3000)]);
        } else {
            db()->prepare('INSERT INTO panel_chat_messages(role,user_message,ai_reply) VALUES(?,?,?)')
                ->execute([$role, mb_substr($userMessage, 0, 1000), mb_substr($reply, 0, 3000)]);
        }
    } catch (Throwable $e) {
        // Kayıt başarısızlığı sohbeti engellemesin.
    }
}

/**
 * Sohbeti yürütür: DeepSeek + araç çağrısı döngüsü (en fazla 3 tur).
 */
function ai_assistant_chat(string $role, array $messages, array $ctx = []): string
{
    $settings = deepseek_settings();
    if ($settings['api_key'] === '') {
        throw new RuntimeException('AI asistanı için yönetici panelinden DeepSeek API anahtarı ekleyin.');
    }

    // Son kullanıcı mesajını yakala (kayıt için).
    $lastUser = '';
    foreach ($messages as $m) {
        if ((string) ($m['role'] ?? '') === 'user' && trim((string) ($m['content'] ?? '')) !== '') {
            $lastUser = (string) $m['content'];
        }
    }

    $tools = ai_assistant_tools($role, $ctx);
    $history = [['role' => 'system', 'content' => ai_assistant_system_prompt($role, $ctx)]];
    foreach (array_slice($messages, -20) as $m) {
        $r = (string) ($m['role'] ?? '');
        $c = (string) ($m['content'] ?? '');
        if (in_array($r, ['user', 'assistant'], true) && $c !== '') {
            $history[] = ['role' => $r, 'content' => mb_substr($c, 0, 2000)];
        }
    }

    for ($round = 0; $round < 3; $round++) {
        $body = [
            'model' => $settings['model'],
            'messages' => $history,
            'temperature' => 0.3,
            'stream' => false,
        ];
        if ($tools['defs']) $body['tools'] = $tools['defs'];

        $ch = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $settings['api_key']],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('AI asistanı yanıt veremedi (HTTP ' . $status . '). Lütfen kısa süre sonra tekrar deneyin.');
        }
        $data = json_decode((string) $raw, true);
        $message = $data['choices'][0]['message'] ?? [];

        if (!empty($message['tool_calls'])) {
            $history[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $message['tool_calls']];
            foreach ($message['tool_calls'] as $tc) {
                $name = (string) ($tc['function']['name'] ?? '');
                $args = json_decode((string) ($tc['function']['arguments'] ?? '{}'), true) ?: [];
                $args = is_array($args) ? $args : [];
                try {
                    $result = isset($tools['handlers'][$name]) ? $tools['handlers'][$name]($args) : 'Bilinmeyen araç: ' . $name;
                    if (!is_string($result)) $result = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                } catch (Throwable $e) {
                    $result = 'HATA: ' . $e->getMessage();
                }
                $history[] = ['role' => 'tool', 'tool_call_id' => (string) ($tc['id'] ?? ''), 'content' => mb_substr($result, 0, 3000)];
            }
            continue;
        }

        $reply = trim((string) ($message['content'] ?? ''));
        if ($reply !== '') {
            ai_assistant_log($role, $ctx, $lastUser, $reply);
            return $reply;
        }
    }

    $fallback = 'Asistan yanıt üretemedi. Lütfen sorunuzu biraz daha açık yazın.';
    ai_assistant_log($role, $ctx, $lastUser, $fallback);
    return $fallback;
}
