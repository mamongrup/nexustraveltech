<?php
declare(strict_types=1);require_once __DIR__.'/database.php';
function agency_session():void{if(session_status()!==PHP_SESSION_ACTIVE){session_name('nexus_agency');session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax']);}}
function agency_user():?array{agency_session();return $_SESSION['agency_user']??null;}
function require_agency():array{$u=agency_user();if(!$u){header('Location: /nexustraveltech/acente/login');exit;}return $u;}
