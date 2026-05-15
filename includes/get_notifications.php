<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/notifications.php';

header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode([]); exit; }

$user = currentUser();
$notifs = getRecentNotifications($user['id'], 12);
echo json_encode($notifs);
