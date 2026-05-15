<?php
// config/app.php
define('APP_NAME', 'MCTBS');
define('APP_URL', 'http://localhost/mctbs');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');
define('PAYMENT_DEADLINE_HOURS', 24);
define('EXTRA_GUEST_RATE', 500);
define('QUEUE_EXPIRY_DAYS', 2);
define('INVITE_EXPIRY_HOURS', 24);
define('VERIFY_EXPIRY_HOURS', 24);

// Mail config (update with your SMTP if needed; uses PHP mail() by default)
define('MAIL_FROM', 'noreply@mctbs.com');
define('MAIL_FROM_NAME', 'MCTBS Booking System');

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function base_url($path = '') {
    return APP_URL . '/' . ltrim($path, '/');
}

function redirect($path) {
    header('Location: ' . base_url($path));
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

function requireRole(...$roles) {
    if (!isLoggedIn()) {
        redirect('modules/auth/login.php');
    }
    $user = currentUser();
    if (!in_array($user['role'], $roles)) {
        redirect('403.php');
    }
}

function flash($key, $msg = null) {
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
    } else {
        $val = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $val;
    }
}

function sanitize($val) {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function generateToken($length = 64) {
    return bin2hex(random_bytes($length));
}

function generateBookingCode() {
    return 'MCT-' . strtoupper(substr(uniqid(), -6)) . '-' . rand(100, 999);
}

function formatMoney($amount) {
    return '₱' . number_format($amount, 2);
}

function formatDate($date) {
    return date('F j, Y', strtotime($date));
}

function nightsBetween($checkin, $checkout) {
    $d1 = new DateTime($checkin);
    $d2 = new DateTime($checkout);
    return max(1, $d1->diff($d2)->days);
}
