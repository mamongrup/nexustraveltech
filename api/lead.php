<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/../config/database.php';

$email = trim((string) ($_POST['email'] ?? ''));
$role = trim((string) ($_POST['role'] ?? ''));
$language = trim((string) ($_POST['language'] ?? ''));
$currency = trim((string) ($_POST['currency'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Gecerli bir e-posta girin.']);
    exit;
}

try {
    $stmt = db()->prepare(
        'INSERT INTO early_access_leads (email, role, language, currency, ip_address, user_agent)
         VALUES (:email, :role, :language, :currency, :ip_address, :user_agent)'
    );

    $stmt->execute([
        'email' => $email,
        'role' => $role,
        'language' => $language,
        'currency' => $currency,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Kayit sirasinda sorun olustu.']);
}
