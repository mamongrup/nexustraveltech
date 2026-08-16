<?php

declare(strict_types=1);

// Test ortamında global hata/exception yakalayıcıyı devre dışı bırak:
// PHPUnit kendi yakalayıcılarını kullanmalıdır.
define('NEXUS_NO_ERROR_HANDLER', true);

date_default_timezone_set('Europe/Istanbul');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/pricing.php';
require_once __DIR__ . '/../config/settlements.php';
require_once __DIR__ . '/../config/webhooks.php';
require_once __DIR__ . '/../config/fx.php';
