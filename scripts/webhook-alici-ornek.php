<?php
/**
 * NEXUS webhook alıcı örneği (standalone).
 *
 * NEXUS, acente webhook adreslerine aşağıdaki başlıklarla POST gönderir:
 *   Content-Type: application/json
 *   X-NEXUS-Event:      booking.created | booking.request.rejected | booking.cancelled
 *   X-NEXUS-Signature:  HMAC-SHA256(secret, raw_body) hex
 *   X-NEXUS-Event-Id:   teslimat kaydı id'si (idempotency için kullanılabilir)
 *
 * Kurulum:
 *   1. Bu dosyayı kendi sisteminize kopyalayın (ör. /webhook/nexus.php).
 *   2. Aşağıdaki WEBHOOK_SECRET sabitini, acente panelindeki webhook
 *      aboneliğinde girdiğiniz secret ile değiştirin (veya env'den okuyun).
 *   3. Acente paneli → Webhooks → adresinizi (https) ekleyin.
 *
 * Test (yerel):
 *   SECRET="ornek-secret" BODY='{"booking_reference":"NXR-TEST"}'
 *   SIGN=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
 *   curl -i -X POST http://localhost/webhook/nexus.php \
 *     -H "Content-Type: application/json" \
 *     -H "X-NEXUS-Event: booking.created" \
 *     -H "X-NEXUS-Signature: $SIGN" \
 *     --data "$BODY"
 */

declare(strict_types=1);

// Kendi secret'ınızla değiştirin veya ortam değişkeninden okuyun.
$webhookSecret = getenv('NEXUS_WEBHOOK_SECRET') ?: 'ornek-secret';

function respond(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $status < 400, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, 'Method not allowed');
}

$body = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_NEXUS_SIGNATURE'] ?? '');
$event = (string) ($_SERVER['HTTP_X_NEXUS_EVENT'] ?? '');
$eventId = (string) ($_SERVER['HTTP_X_NEXUS_EVENT_ID'] ?? '');

if ($event === '' || $eventId === '') {
    respond(400, 'Geçersiz webhook başlıkları.');
}

// İmza doğrulaması: HMAC-SHA256(secret, ham gövde)
$expected = hash_hmac('sha256', $body, $webhookSecret);
if (!hash_equals($expected, $signature)) {
    respond(401, 'İmza doğrulanamadı.');
}

// İşlem: idempotency için event_id'yi saklayın (ör. Redis/SQL unique key).
$payload = json_decode($body, true);
if (!is_array($payload)) {
    respond(400, 'Geçersiz JSON gövdesi.');
}

// Olay bazlı işleme
switch ($event) {
    case 'booking.created':
        // Rezervasyon onaylandı — kendi sisteminizde rezervasyonu kesinleştirin.
        error_log(sprintf('[NEXUS] booking.created %s (%s)', $payload['booking_reference'] ?? '', $payload['total_amount'] ?? 0));
        break;
    case 'booking.cancelled':
        error_log(sprintf('[NEXUS] booking.cancelled %s (%s)', $payload['booking_reference'] ?? '', $payload['reason'] ?? ''));
        break;
    case 'booking.request.rejected':
        error_log(sprintf('[NEXUS] booking.request.rejected %s', $payload['request_id'] ?? ''));
        break;
    default:
        respond(400, 'Bilinmeyen olay: ' . $event);
}

respond(200, 'OK');
