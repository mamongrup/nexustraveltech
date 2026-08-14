<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/verification_documents.php';

require_admin();

$id = filter_input(INPUT_GET, 'document', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('Belge bulunamadı.'); }
$query = db()->prepare('SELECT * FROM supplier_verification_documents WHERE id=?');
$query->execute([$id]);
$document = $query->fetch();
if (!$document) { http_response_code(404); exit('Belge bulunamadı.'); }

$path = verification_document_directory((int) $document['supplier_id']) . '/' . basename((string) $document['stored_name']);
if (!is_file($path)) { http_response_code(404); exit('Belge dosyası bulunamadı.'); }

header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string) $document['file_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
