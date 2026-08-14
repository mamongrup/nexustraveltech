<?php
// Copy this file to secrets.php on each environment. Do not commit secrets.php.
return [
  'db_host' => '127.0.0.1',
  'db_port' => '5432',
  'db_name' => 'nexus_traveltech',
  'db_user' => 'postgres',
  'db_pass' => 'CHANGE_ME',
  'admin_username' => 'admin',
  'admin_password' => 'CHANGE_ME',
  // Do not store API keys in this file. Generate a unique 32+ character value.
  'app_encryption_key' => 'GENERATE_A_RANDOM_32_PLUS_CHARACTER_SECRET',
];
