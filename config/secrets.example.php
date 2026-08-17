<?php
// Copy this file to secrets.php on each environment. Do not commit secrets.php.
return [
  'db_host' => '127.0.0.1',
  'db_port' => '5432',
  'db_name' => 'nexus_traveltech',
  'db_user' => 'postgres',
  'db_pass' => 'CHANGE_ME',
  // Opsiyonel — sahiplik devri için süper kullanıcı bilgileri. Tanımlıysa
  // health-check --repair, app kullanıcısı tablo sahibi değilse önce sahipliği
  // bu hesapla devreder, sonra migration'ları uygular. Boş bırakılırsa komut
  // sahipliği elle devretmeniz için gerekli tek satırı çıktıda gösterir.
  'db_admin_user' => '',
  'db_admin_pass' => '',
  'admin_username' => 'admin',
  'admin_password' => 'CHANGE_ME',
  // Do not store API keys in this file. Generate a unique 32+ character value.
  'app_encryption_key' => 'GENERATE_A_RANDOM_32_PLUS_CHARACTER_SECRET',
];
