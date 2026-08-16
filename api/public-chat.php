<?php
declare(strict_types=1);

/**
 * Kamuya açık AI sohbet uç noktası (önyüz chatbox'ı için).
 *
 * Güvenlik: oturum yoktur; IP bazlı hız sınırı (5 dakikada 10 mesaj) +
 * mesaj uzunluk sınırı + yalnızca genel bilgi (iç veri erişimi yok).
 * Sorgular ve yanıtlar public_chat_messages tablosuna kaydedilir.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../config/platform_settings.php';

header('Content-Type: application/json; charset=utf-8');

function ai_public_reply_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : [];
    $message = trim(mb_substr((string) ($in['message'] ?? ''), 0, 1000));
    if ($message === '') {
        ai_public_reply_json(400, ['error' => 'Mesaj boş olamaz.']);
    }

    // Kalitesiz girdi filtresi (eşikler admin → Kontrol merkezi'nden düzenlenir):
    // minimum uzunluk + isteğe bağlı tek kelime engeli. Eşleşen girdiler AI'ya gitmez.
    $minLen = max(1, (int) platform_setting('chat_min_length', 5));
    $requireSpace = (bool) platform_setting('chat_require_space', true);
    if (mb_strlen($message) < $minLen || ($requireSpace && !str_contains($message, ' '))) {
        ai_public_reply_json(200, ['reply' => 'Sorunuzu biraz daha açık yazabilir misiniz? Örn. "Tedarikçi olarak nasıl katılırım?" veya "API entegrasyonu var mı?"']);
    }

    $history = is_array($in['history'] ?? null) ? array_slice($in['history'], -12) : [];

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '0.0.0.0';
    }

    $pdo = db();

    // Yasak kelime listesi: eşleşen girdiler AI'ya gitmez (admin → Kontrol merkezi'nde düzenlenir).
    $blocklist = platform_setting('chat_blocklist', []);
    if (is_array($blocklist) && $blocklist !== []) {
        $blocked = false;
        $lower = mb_strtolower($message);
        foreach ($blocklist as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') continue;
            if (strlen($entry) > 2 && str_starts_with($entry, '/') && str_ends_with($entry, '/')) {
                if (@preg_match('~' . substr($entry, 1, -1) . '~iu', $message) === 1) {
                    $blocked = true;
                    break;
                }
            } elseif (mb_strpos($lower, mb_strtolower($entry)) !== false) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            try {
                nexus_log_error('warning', 'AI sohbet engellenen kelime', ['ip' => $ip]);
            } catch (Throwable $e) {
                // Kayıt başarısızlığı yanıtı engellemesin.
            }
            ai_public_reply_json(200, ['reply' => 'Bu konuda size yardımcı olamıyorum. Başka bir sorunuz varsa memnuniyetle yanıtlarım.']);
        }
    }

    // IP engeli: engellenmiş IP'ler isteği hiç işlemez (bayraklı IP'ler kayıtla izlenir).
    $blk = $pdo->prepare('SELECT action FROM blocked_ips WHERE ip=?::inet LIMIT 1');
    $blk->execute([$ip]);
    $blkRow = $blk->fetch();
    if ($blkRow && (string) $blkRow['action'] === 'block') {
        ai_public_reply_json(403, ['error' => 'Erişiminiz kısıtlanmıştır.']);
    }

    // IP hız sınırı: 5 dakikada en fazla 10 soru.
    $cutoff = date('Y-m-d H:i:s', time() - 300);
    $q = $pdo->prepare('SELECT COUNT(*) FROM public_chat_messages WHERE ip=?::inet AND created_at>=?');
    $q->execute([$ip, $cutoff]);
    if ((int) $q->fetchColumn() >= 10) {
        // Suiistimal taraması için reddi kaydet (günlük görev bunları bayraklar).
        try {
            nexus_log_error('warning', 'AI sohbet hız sınırı aşıldı', ['ip' => $ip]);
        } catch (Throwable $e) {
            // Kayıt başarısızlığı yanıtı engellemesin.
        }
        ai_public_reply_json(429, ['error' => 'Çok fazla soru gönderdiniz. Lütfen birkaç dakika sonra tekrar deneyin.']);
    }

    $settings = deepseek_settings();
    if ($settings['api_key'] === '') {
        ai_public_reply_json(200, ['reply' => 'Asistan şu an yapılandırma bekliyor. Bu arada İletişim sayfasındaki formdan bize ulaşabilirsiniz: /nexustraveltech/iletisim']);
    }

    $system = "Sen NEXUS TravelTech'in kamuya açık web asistanısın. Ziyaretçiyi kısa, samimi ve net Türkçe karşıla; sorularını yanıtla ve doğru sayfaya yönlendir. "
        . "Platform hakkında bilgiler: "
        . "NEXUS TravelTech; turizm tedarikçilerinin (otel, villa, yat, araç kiralama, restoran, tur) ürün, fiyat ve müsaitliğini canlı paylaştığı, seyahat acentelerinin tek ağdan eriştiği ve gezginlerin rezervasyon yaptığı bir B2B turizm platformudur. "
        . "Tedarikçi yazılımı (NEXUS Supply) → /nexustraveltech/tedarikciler. "
        . "Acente yazılımı (NEXUS Agency) → /nexustraveltech/acenteler. "
        . "API/XML entegrasyonu (NEXUS Connect) → /nexustraveltech/api-xml. "
        . "Gezginler için bilgi → /nexustraveltech/gezginler. "
        . "Çözümler → /nexustraveltech/cozumler, platform → /nexustraveltech/platform, hakkımızda → /nexustraveltech/sirket. "
        . "İletişim ve partnerlik formu → /nexustraveltech/iletisim; pilot erişim başvurusu ana sayfadaki 'Pilot erişim iste' formundan yapılır. Gizlilik → /nexustraveltech/gizlilik, çerezler → /nexustraveltech/cerezler. "
        . "Kurallar: fiyat, müsaitlik, rezervasyon durumu, kullanıcı hesapları gibi canlı/kişisel veri hakkında ASLA bilgi uydurma; bu konularda kullanıcıyı doğru sayfaya yönlendir (tedarikçi/acente girişi için ilgili panel login sayfaları, destek için iletişim formu). "
        . "İç veya gizli bilgi sorulursa nazikçe 'bu bilgiyi paylaşamıyorum' de ve iletişim sayfasına yönlendir. "
        . "Yanıtları 3-4 cümleyle sınırla; madde listesini yalnızca gerçekten faydalıysa kullan; sayfa önerilerinde tam bağlantı ver.";

    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $m) {
        $r = (string) ($m['role'] ?? '');
        $c = trim((string) ($m['content'] ?? ''));
        if (in_array($r, ['user', 'assistant'], true) && $c !== '') {
            $messages[] = ['role' => $r, 'content' => mb_substr($c, 0, 1000)];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $body = [
        'model' => $settings['model'],
        'messages' => $messages,
        'temperature' => 0.4,
        'stream' => false,
    ];

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $settings['api_key']],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('AI asistanı yanıt veremedi (HTTP ' . $status . '). Lütfen kısa süre sonra tekrar deneyin.');
    }
    $data = json_decode((string) $raw, true);
    $reply = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($reply === '') {
        throw new RuntimeException('AI asistanı yanıt üretemedi. Lütfen sorunuzu biraz daha açık yazın.');
    }

    // Sorgu + yanıtı kaydet (yönetim görünürlüğü + hız sınırlama verisi).
    try {
        $pdo->prepare('INSERT INTO public_chat_messages(ip,user_message,ai_reply) VALUES(?::inet,?,?)')
            ->execute([$ip, mb_substr($message, 0, 1000), mb_substr($reply, 0, 3000)]);
    } catch (Throwable $e) {
        // Kayıt başarısızlığı yanıtı engellemesin.
    }

    ai_public_reply_json(200, ['reply' => $reply]);
} catch (Throwable $e) {
    ai_public_reply_json(500, ['error' => $e->getMessage()]);
}
