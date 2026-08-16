<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/supplier_auth.php';
require_once __DIR__ . '/../config/ai_assistant.php';

$u = require_supplier();
header('Content-Type: application/json; charset=utf-8');
try {
    if (empty($_SESSION['supplier_csrf'])) {
        $_SESSION['supplier_csrf'] = bin2hex(random_bytes(32));
    }
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : [];
    $csrf = (string) ($in['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['supplier_csrf'], $csrf)) {
        http_response_code(403);
        echo json_encode(['error' => 'Güvenlik doğrulaması geçersiz. Sayfayı yenileyip tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $messages = is_array($in['messages'] ?? null) ? $in['messages'] : [];
    $reply = ai_assistant_chat('supplier', $messages, ['supplier_id' => (int) ($u['supplier_id'] ?? $u['id'] ?? 0)]);
    echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
