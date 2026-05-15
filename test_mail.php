<?php
// test_mail.php
// DELETE THIS FILE after confirming email works.
// Access: http://localhost/mctbs/test_mail.php

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/mailer.php';

$result  = null;
$error   = null;
$sent    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testTo = trim($_POST['test_email'] ?? '');
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $body = <<<HTML
<p>Hello,</p>
<p>This is a <strong>test email</strong> from your MCTBS installation.</p>
<p>If you received this, your SMTP configuration is working correctly! ✅</p>
<p>You can now delete the <code>test_mail.php</code> file from your server.</p>
HTML;
        $ok = sendMail($testTo, 'MCTBS — SMTP Test Email ✅', $body, 'Test Recipient');
        if ($ok) {
            $sent = true;
        } else {
            // Pull error from PHPMailer
            require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
            $error = 'Mail failed. Check your config/mail.php settings and PHP error log (xampp/php/logs/php_error_log).';
        }
    }
}

$cfg = require __DIR__ . '/config/mail.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>MCTBS Mail Test</title>
  <link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<div style="max-width:600px;margin:48px auto;padding:0 20px">
  <div class="card">
    <div class="card-header" style="background:var(--primary);color:#fff;font-size:18px">
      🏠 MCTBS — Mail Configuration Test
    </div>
    <div class="card-body">

      <!-- Current Config Summary -->
      <div style="background:var(--bg);border-radius:8px;padding:16px;margin-bottom:20px;font-size:13px">
        <strong>Current SMTP Settings (config/mail.php):</strong>
        <table style="margin-top:10px;width:100%">
          <tr><td style="padding:4px 0;color:var(--text-muted);width:120px">Host</td><td><strong><?= sanitize($cfg['host']) ?></strong></td></tr>
          <tr><td style="padding:4px 0;color:var(--text-muted)">Port</td><td><strong><?= $cfg['port'] ?></strong></td></tr>
          <tr><td style="padding:4px 0;color:var(--text-muted)">Encryption</td><td><strong><?= sanitize($cfg['encryption']) ?></strong></td></tr>
          <tr><td style="padding:4px 0;color:var(--text-muted)">Username</td><td><strong><?= sanitize($cfg['username']) ?></strong></td></tr>
          <tr><td style="padding:4px 0;color:var(--text-muted)">From Email</td><td><strong><?= sanitize($cfg['from_email']) ?></strong></td></tr>
          <tr><td style="padding:4px 0;color:var(--text-muted)">From Name</td><td><strong><?= sanitize($cfg['from_name']) ?></strong></td></tr>
        </table>
      </div>

      <?php if ($sent): ?>
        <div class="alert alert-success">
          <i class="fa fa-check-circle"></i>
          <strong>Email sent successfully!</strong> Check <strong><?= sanitize($_POST['test_email']) ?></strong> inbox (and spam folder).
        </div>
        <div class="alert alert-info" style="margin-top:10px;font-size:13px">
          <i class="fa fa-info-circle"></i>
          Everything is working. You can now <strong>delete test_mail.php</strong> from your project.
        </div>
      <?php elseif ($error): ?>
        <div class="alert alert-danger">
          <i class="fa fa-exclamation-circle"></i> <?= sanitize($error) ?>
        </div>
        <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:14px;font-size:13px;margin-bottom:16px">
          <strong>Troubleshooting tips:</strong>
          <ul style="margin:8px 0 0 16px;line-height:2">
            <li>For <strong>Gmail</strong>: Make sure you're using an <em>App Password</em>, not your regular Gmail password.<br>
                Create one at: <a href="https://myaccount.google.com/apppasswords" target="_blank">myaccount.google.com/apppasswords</a></li>
            <li>For <strong>Gmail</strong>: 2-Step Verification must be enabled on your account first.</li>
            <li>For <strong>Mailtrap</strong>: Double-check username/password from your Mailtrap inbox SMTP settings.</li>
            <li>Check <code>xampp/php/logs/php_error_log</code> for the exact SMTP error.</li>
            <li>Make sure port <strong><?= $cfg['port'] ?></strong> is not blocked by your firewall or ISP.</li>
          </ul>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="required">Send test email to:</label>
          <input type="email" name="test_email" value="<?= sanitize($_POST['test_email'] ?? '') ?>"
                 placeholder="your@email.com" required autofocus>
          <p class="form-hint">Use a real inbox you can check — Gmail, Mailtrap, Outlook, etc.</p>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">
          <i class="fa fa-paper-plane"></i> Send Test Email
        </button>
      </form>
    </div>
    <div class="card-footer fs-sm text-muted">
      ⚠️ Delete <code>test_mail.php</code> from your server after testing.
    </div>
  </div>
</div>
</body>
</html>
