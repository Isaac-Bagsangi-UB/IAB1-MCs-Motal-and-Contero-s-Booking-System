<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/notifications.php';

if (!isLoggedIn()) { header('Location: ../modules/auth/login.php'); exit; }

$user = currentUser();
markAllRead($user['id']);

$ref = $_SERVER['HTTP_REFERER'] ?? base_url('index.php');
header('Location: ' . $ref);
exit;
