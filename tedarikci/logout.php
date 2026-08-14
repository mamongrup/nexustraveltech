<?php require_once __DIR__ . '/../config/supplier_auth.php'; supplier_session(); $_SESSION = []; session_destroy(); header('Location: /nexustraveltech/tedarikci/login'); exit;
