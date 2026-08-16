<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/../config/database.php';

// Honeypot: gerçek kullanıcıların görmediği gizli alan doldurulduysa botu sessizce reddet.
if (trim((string) ($_POST['company_website'] ?? '')) !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$email = trim((string) ($_POST['email'] ?? ''));
$role = mb_substr(trim((string) ($_POST['role'] ?? '')), 0, 40);
$language = strtolower(mb_substr(trim((string) ($_POST['language'] ?? 'tr')), 0, 2));
$currency = strtoupper(mb_substr(trim((string) ($_POST['currency'] ?? 'TRY')), 0, 3));
$consent = (string) ($_POST['consent'] ?? '');

if ($consent !== '1') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Kişisel verilerinizin işlenmesi için onay gereklidir.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Gecerli bir e-posta girin.']);
    exit;
}

if (!preg_match('/^[a-z]{2}$/', $language)) $language = 'tr';
if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'TRY';

try {
    $stmt = db()->prepare(
        'INSERT INTO early_access_leads (email, role, language, currency, ip_address, user_agent, consent_at)
         VALUES (:email, :role, :language, :currency, :ip_address, :user_agent, now())'
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
