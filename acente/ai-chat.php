<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/agency_auth.php';
require_once __DIR__ . '/../config/ai_assistant.php';

$u = require_agency();
header('Content-Type: application/json; charset=utf-8');
try {
    if (empty($_SESSION['agency_csrf'])) {
        $_SESSION['agency_csrf'] = bin2hex(random_bytes(32));
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : [];
    $csrf = (string) ($in['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['agency_csrf'], $csrf)) {
        http_response_code(403);
        echo json_encode(['error' => 'Güvenlik doğrulaması geçersiz. Sayfayı yenileyip tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];
    $reply = ai_assistant_chat('agency', $messages, ['agency_id' => (int) $u['agency_id']]);
    echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
