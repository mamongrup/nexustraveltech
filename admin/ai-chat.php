<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/ai_assistant.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');
try {
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : [];
    $csrf = (string) ($in['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['admin_csrf'], $csrf)) {
        http_response_code(403);
        echo json_encode(['error' => 'Güvenlik doğrulaması geçersiz. Sayfayı yenileyip tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];
    $lang = trim((string) ($in['lang'] ?? ''));
    if ($lang === '' && !empty($_SESSION['tooltip_language'])) $lang = $_SESSION['tooltip_language'];
    $reply = ai_assistant_chat('admin', $messages, ['admin_id' => (int) ($_SESSION['admin_id'] ?? 0), 'lang' => $lang ?: 'tr']);
    echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
