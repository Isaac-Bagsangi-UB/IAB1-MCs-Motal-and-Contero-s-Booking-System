<?php
// modules/auth/reset.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

$token = $_GET['token'] ?? '';
$db = getDB();
$stmt = $db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
$stmt->execute([$token]);
$reset = $stmt->fetch();
$errors = [];
$done = false;

if (!$reset) {
    $errors[] = 'This reset link is invalid or expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $pass = $_POST['password'] ?? '';
    $conf = $_POST['confirm']  ?? '';
    if (strlen($pass) < 8 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[^A-Za-z0-9]/', $pass)) {
        $errors[] = 'Password does not meet requirements.';
    } elseif ($pass !== $conf) {
        $errors[] = 'Passwords do not match.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE users SET password = ? WHERE email = ?")->execute([$hash, $reset['email']]);
        $db->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Reset Password | MCTBS</title>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo"><span style="font-size:40px">🏠</span><h2>Reset Password</h2></div>
    <div class="auth-body">
      <?php if ($done): ?>
        <div class="alert alert-success">Your password has been reset successfully!</div>
        <div class="text-center mt-2"><a href="<?= base_url('modules/auth/login.php') ?>" class="btn btn-primary">Sign In</a></div>
      <?php elseif (!$reset): ?>
        <div class="alert alert-danger"><?= sanitize($errors[0]) ?></div>
      <?php else: ?>
        <?php if ($errors): ?><div class="alert alert-danger"><?= sanitize($errors[0]) ?></div><?php endif; ?>
        <form method="POST">
          <div class="form-group">
            <label class="required">New Password</label>
            <input type="password" name="password" id="password" required>
            <div class="password-rule">
              <span id="rule-len">8+ chars</span>
              <span id="rule-upper">Uppercase</span>
              <span id="rule-lower">Lowercase</span>
              <span id="rule-num">Number</span>
              <span id="rule-spec">Special</span>
            </div>
          </div>
          <div class="form-group">
            <label class="required">Confirm Password</label>
            <input type="password" name="confirm" required>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
