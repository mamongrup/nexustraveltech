<?php

declare(strict_types=1);

require __DIR__ . '/../config/auth.php';
admin_session();
session_destroy();

header('Location: /nexustraveltech/admin/login');
exit;
