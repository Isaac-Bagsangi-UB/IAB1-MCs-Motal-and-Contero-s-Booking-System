<?php
// modules/auth/verify.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

$token = $_GET['token'] ?? '';
$db = getDB();
$msg = '';
$type = 'danger';

if ($token) {
    $stmt = $db->prepare("SELECT * FROM email_verifications WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        $db->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")->execute([$row['user_id']]);
        $db->prepare("DELETE FROM email_verifications WHERE id = ?")->execute([$row['id']]);
        $msg = 'Your email has been verified! You can now log in.';
        $type = 'success';
    } else {
        $msg = 'This verification link is invalid or has expired.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Verify Email | MCTBS</title>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo"><span style="font-size:40px">🏠</span><h2>Email Verification</h2></div>
    <div class="auth-body">
      <div class="alert alert-<?= $type ?>"><?= sanitize($msg) ?></div>
      <div class="text-center mt-2">
        <a href="<?= base_url('modules/auth/login.php') ?>" class="btn btn-primary">Go to Login</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
