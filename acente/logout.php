<?php require_once __DIR__.'/../config/agency_auth.php';agency_session();$_SESSION=[];session_destroy();header('Location: /nexustraveltech/acente/login');exit;
