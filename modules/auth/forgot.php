<?php
// modules/auth/forgot.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

$resetLink = null;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $errors[] = 'Please enter your email.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = generateToken();

            // Use MySQL NOW() + INTERVAL so expiry is always in DB timezone — no PHP/MySQL timezone mismatch
            $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
               ->execute([$email, $token]);

            $resetLink = base_url("modules/auth/reset.php?token={$token}");
        } else {
            $errors[] = 'No account found with that email address.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password | MCTBS</title>
  <link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
  <style>
    .reset-link-box {
      margin-top: 18px;
      background: #f0faf4;
      border: 1.5px solid #27ae60;
      border-radius: 8px;
      padding: 18px 20px;
    }
    .reset-link-box p {
      margin: 0 0 10px;
      font-size: 13px;
      color: #2d6a4f;
      font-weight: 600;
    }
    .reset-link-box .link-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .reset-link-box .link-text {
      flex: 1;
      font-size: 12px;
      color: #1a6b3a;
      word-break: break-all;
      background: #fff;
      border: 1px solid #b7e4c7;
      border-radius: 5px;
      padding: 7px 10px;
      font-family: monospace;
    }
    .reset-link-box .btn-copy {
      white-space: nowrap;
      font-size: 12px;
      padding: 7px 14px;
      border-radius: 5px;
      border: none;
      background: #27ae60;
      color: #fff;
      cursor: pointer;
      transition: background .2s;
    }
    .reset-link-box .btn-copy:hover { background: #219150; }
    .reset-link-box .btn-open {
      display: inline-block;
      margin-top: 12px;
      font-size: 13px;
      font-weight: 600;
      color: #fff;
      background: #2980b9;
      border-radius: 6px;
      padding: 9px 20px;
      text-decoration: none;
      transition: background .2s;
    }
    .reset-link-box .btn-open:hover { background: #1f6692; }
    .reset-link-box .expires-note {
      margin-top: 10px;
      font-size: 11px;
      color: #666;
    }
  </style>
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo">
      <span style="font-size:40px">🏠</span>
      <h2>Forgot Password</h2>
    </div>
    <div class="auth-body">

      <?php if ($resetLink): ?>
        <div class="alert alert-success" style="margin-bottom:4px">
          Account found! Use the link below to reset your password.
        </div>
        <div class="reset-link-box">
          <p>🔗 Password Reset Link</p>
          <div class="link-wrap">
            <span class="link-text" id="resetLinkText"><?= htmlspecialchars($resetLink) ?></span>
            <button class="btn-copy" onclick="copyLink()">Copy</button>
          </div>
          <a class="btn-open" href="<?= htmlspecialchars($resetLink) ?>">Open Reset Page →</a>
          <div class="expires-note">⏱ This link expires in <strong>1 hour</strong>. (Simulation mode — no email sent.)</div>
        </div>

      <?php else: ?>
        <?php if ($errors): ?>
          <div class="alert alert-danger"><?= sanitize($errors[0]) ?></div>
        <?php endif; ?>
        <form method="POST">
          <div class="form-group">
            <label class="required">Email Address</label>
            <input type="email" name="email" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </form>
      <?php endif; ?>

      <div class="text-center mt-2">
        <a href="<?= base_url('modules/auth/login.php') ?>">Back to Login</a>
      </div>
    </div>
  </div>
</div>

<script>
function copyLink() {
  const text = document.getElementById('resetLinkText').textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.querySelector('.btn-copy');
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 2000);
  });
}
</script>
</body>
</html>