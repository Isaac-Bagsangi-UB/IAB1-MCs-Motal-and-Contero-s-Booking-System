<?php
// index.php — Main entry point / router
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

if (isLoggedIn()) {
    $role = currentUser()['role'];
    switch ($role) {
        case 'sysadmin': redirect('modules/sysadmin/dashboard.php'); break;
        case 'owner':    redirect('modules/owner/dashboard.php');    break;
        case 'admin':    redirect('modules/admin/dashboard.php');    break;
        case 'guest':    redirect('modules/public/home.php');        break;
        default:         redirect('modules/auth/login.php');
    }
} else {
    redirect('modules/public/home.php');
}
