<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/audit.php';
admin_session();
audit_log('admin.logout');
$_SESSION = [];
session_destroy();

header('Location: /nexustraveltech/admin/login');
exit;
