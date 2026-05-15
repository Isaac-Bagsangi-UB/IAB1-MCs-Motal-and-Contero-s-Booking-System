<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/notifications.php';

$token = $_GET['token'] ?? '';
if (isLoggedIn() && !$token) redirect('index.php');

$db     = getDB();
$errors = [];
$invite = null;
$preEmail = '';
$expiredInvite = null;
$msg = '';
$type = 'danger';

if ($token) {
    $stmt = $db->prepare("SELECT * FROM invitations WHERE token = ? AND used_at IS NULL");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();
    if ($invite) {
        if (strtotime($invite['expires_at']) > time()) {
            $preEmail = $invite['email'];
        } else {
            $expiredInvite = $invite;
        }
    } else {
        $errors[] = 'This invitation link is invalid.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_invitation']) && $expiredInvite) {
    // Notify the inviter
    $inviter = $db->prepare("SELECT * FROM users WHERE id = ?");
    $inviter->execute([$expiredInvite['invited_by']]);
    $inviter = $inviter->fetch();
    if ($inviter) {
        createNotification($inviter['id'], 'resend_request', 'Resend Invitation Request', "The invitation to {$expiredInvite['email']} has expired and they have requested a resend.", "modules/{$inviter['role']}/dashboard.php");
        // Send email to inviter
        $body = "Hello,<br><br>The invitation link sent to <strong>{$expiredInvite['email']}</strong> has expired, and the recipient has requested a resend.<br><br>Please log in to your dashboard to send a new invitation.<br><br>Best regards,<br>MCTBS System";
        sendMail($inviter['email'], 'Resend Invitation Request', $body);
    }
    $msg = 'Your request has been sent. The administrator will resend the invitation shortly.';
    $type = 'info';
}

function validatePassword($pass) {
    return strlen($pass) >= 8
        && preg_match('/[A-Z]/', $pass)
        && preg_match('/[a-z]/', $pass)
        && preg_match('/[0-9]/', $pass)
        && preg_match('/[^A-Za-z0-9]/', $pass);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name']  ?? '');
    $email     = trim($_POST['email']      ?? '');
    $phone     = trim($_POST['phone']      ?? '');
    $password  = $_POST['password']        ?? '';
    $confirm   = $_POST['confirm_password']?? '';

    if (!$firstName || !$lastName || !$email || !$password) {
        $errors[] = 'All required fields must be filled.';
    } elseif (!preg_match('/^[a-zA-Z\s\-\.]+$/', $firstName)) {
        $errors[] = 'First name must contain letters only.';
    } elseif (!preg_match('/^[a-zA-Z\s\-\.]+$/', $lastName)) {
        $errors[] = 'Last name must contain letters only.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } elseif (!validatePassword($password)) {
        $errors[] = 'Password does not meet requirements.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $errors[] = 'This email is already registered.';
        } else {
            $role = $invite ? $invite['role'] : 'guest';
            if (!in_array($role, ['owner', 'admin', 'guest', 'sysadmin'])) $role = 'guest';

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("INSERT INTO users (email, password, role, first_name, last_name, phone) VALUES (?,?,?,?,?,?)")
               ->execute([$email, $hash, $role, $firstName, $lastName, $phone]);
            $userId = $db->lastInsertId();

            if ($invite && $invite['role'] === 'admin' && $invite['owner_id']) {
                $db->prepare("INSERT INTO owner_admins (owner_id, admin_id, house_id) VALUES (?, ?, ?)")
                   ->execute([$invite['owner_id'], $userId, $invite['house_id'] ?: null]);
            }

            if (!$invite) {
                $vToken = generateToken();
                $expiry = date('Y-m-d H:i:s', strtotime('+' . VERIFY_EXPIRY_HOURS . ' hours'));
                $db->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?,?,?)")
                   ->execute([$userId, $vToken, $expiry]);
                sendVerificationEmail($email, $vToken);
            } else {
                $db->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")->execute([$userId]);
            }

            if ($invite) {
                $db->prepare("UPDATE invitations SET used_at = NOW() WHERE id = ?")->execute([$invite['id']]);
            }

            $newUser = $db->prepare("SELECT * FROM users WHERE id = ?");
            $newUser->execute([$userId]);
            $newUser = $newUser->fetch();
            $_SESSION['user_id'] = $userId;
            $_SESSION['user']    = $newUser;
            flash('success', 'Welcome to MCTBS, ' . $firstName . '! Your account has been created.');
            $dashRole = $role === 'sysadmin' ? 'sysadmin' : $role;
            redirect("modules/{$dashRole}/dashboard.php");
        }
    }
}

$inviteLabel = '';
if ($invite) {
    if ($invite['role'] === 'admin')        $inviteLabel = 'Staff';
    elseif ($invite['role'] === 'sysadmin') $inviteLabel = 'System Admin';
    else $inviteLabel = ucfirst($invite['role']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | MCTBS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --amber-50:  #fffbeb;
      --amber-100: #fef3c7;
      --amber-300: #fcd34d;
      --amber-400: #fbbf24;
      --amber-500: #f59e0b;
      --amber-600: #d97706;
      --amber-700: #b45309;
      --amber-800: #92400e;
      --white:     #ffffff;
      --gray-50:   #f9fafb;
      --gray-100:  #f3f4f6;
      --gray-200:  #e5e7eb;
      --gray-300:  #d1d5db;
      --gray-400:  #9ca3af;
      --gray-500:  #6b7280;
      --gray-700:  #374151;
      --gray-900:  #111827;
      --green-500: #22c55e;
      --red-400:   #f87171;
    }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--amber-50);
      overflow: hidden;
    }

    .auth-wrapper {
      display: grid;
      grid-template-columns: 1fr 1fr;
      height: 100vh;
      overflow: hidden;
    }

    /* ══ LEFT PANEL ══ */
    .auth-left {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 48px 52px;
      overflow: hidden;
      background: linear-gradient(145deg, #b45309 0%, #d97706 35%, #f59e0b 65%, #fbbf24 100%);
    }
    .auth-left::before, .auth-left::after {
      content: ''; position: absolute; border-radius: 50%; pointer-events: none;
    }
    .auth-left::before { width: 520px; height: 520px; top: -160px; right: -160px; background: rgba(255,255,255,.07); }
    .auth-left::after  { width: 340px; height: 340px; bottom: -120px; left: -100px; background: rgba(255,255,255,.06); }

    .deco-ring { position: absolute; border-radius: 50%; border: 1.5px solid rgba(255,255,255,.12); pointer-events: none; }
    .deco-ring-1 { width: 700px; height: 700px; top: -220px; right: -280px; }
    .deco-ring-2 { width: 420px; height: 420px; bottom: -160px; left: -140px; }
    .deco-ring-3 { width: 200px; height: 200px; bottom: 80px; right: 40px; }

    .auth-left-noise {
      position: absolute; inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
      opacity: .5; pointer-events: none;
    }

    .left-content { position: relative; z-index: 1; text-align: center; }

    .brand-logo {
      width: 180px;
      height: 180px;
      margin: 0 auto 24px;
      background: rgba(255,255,255,.15);
      border-radius: 24px;
      backdrop-filter: blur(6px);
      border: 1.5px solid rgba(255,255,255,.25);
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 8px 32px rgba(0,0,0,.15);
      animation: logoFloat 6s ease-in-out infinite;
    }
    .brand-logo img { width: 220px; filter: brightness(0) invert(1); }
    @keyframes logoFloat {
      0%,100% { transform: translateY(0); }
      50%      { transform: translateY(-8px); }
    }

    .left-heading {
      font-family: arial, sans-serif;
      font-size: clamp(22px, 2.4vw, 32px);
      font-weight: 800; color: var(--white);
      line-height: 1.2; letter-spacing: -0.5px;
      margin-bottom: 16px;
      text-shadow: 0 2px 16px rgba(0,0,0,.2);
      opacity: 0; animation: fadeUp .8s ease forwards .3s;
    }

    .heading-accent {
      display: block; width: 60px; height: 3px;
      background: rgba(255,255,255,.55);
      margin: 14px auto 18px; border-radius: 99px;
      transform: scaleX(0); transform-origin: center;
      animation: accentGrow 1s ease forwards .9s;
    }
    @keyframes accentGrow { to { transform: scaleX(1); } }

    .left-subheader {
      font-size: clamp(13px, 1.1vw, 15px);
      color: rgba(255,255,255,.82); line-height: 1.7;
      max-width: 380px; margin: 0 auto;
      white-space: normal; width: 100%;
      opacity: 0; animation: fadeUp .8s ease forwards 1.2s;
    }


    /* Left step tracker */
    .left-steps {
      margin-top: 40px;
      display: flex; flex-direction: column;
      opacity: 0; animation: fadeUp .5s ease forwards 2s;
    }
    .left-step-item {
      display: flex; align-items: flex-start; gap: 14px;
      position: relative;
    }
    .left-step-item:not(:last-child)::after {
      content: ''; position: absolute;
      left: 16px; top: 36px; width: 2px; height: 30px;
      background: rgba(255,255,255,.2); border-radius: 1px;
      transition: background .5s;
    }
    .left-step-item.done:not(:last-child)::after { background: rgba(255,255,255,.55); }

    .left-step-circle {
      width: 34px; height: 34px; border-radius: 50%;
      border: 2px solid rgba(255,255,255,.35);
      background: rgba(255,255,255,.1);
      display: flex; align-items: center; justify-content: center;
      color: rgba(255,255,255,.7); font-size: 12px; font-weight: 700;
      flex-shrink: 0; transition: all .4s ease; backdrop-filter: blur(4px);
    }
    .left-step-item.active .left-step-circle {
      background: var(--white); border-color: var(--white);
      color: var(--amber-600); box-shadow: 0 0 0 5px rgba(255,255,255,.2);
    }
    .left-step-item.done .left-step-circle {
      background: rgba(255,255,255,.3); border-color: rgba(255,255,255,.6); color: var(--white);
    }

    .left-step-text { padding-top: 6px; padding-bottom: 28px; }
    .left-step-label {
      font-size: 13.5px; font-weight: 600;
      color: rgba(255,255,255,.45); transition: color .4s; line-height: 1;
    }
    .left-step-item.active .left-step-label { color: var(--white); }
    .left-step-item.done   .left-step-label { color: rgba(255,255,255,.75); }
    .left-step-desc { font-size: 12px; color: rgba(255,255,255,.35); margin-top: 3px; transition: color .4s; }
    .left-step-item.active .left-step-desc { color: rgba(255,255,255,.6); }
    .left-step-item.done   .left-step-desc { color: rgba(255,255,255,.45); }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(18px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ══ RIGHT PANEL ══ */
    .auth-right {
      display: flex; align-items: center; justify-content: center;
      padding: 32px 40px;
      background: var(--white);
      position: relative; overflow: hidden;
    }
    .auth-right::before {
      content: ''; position: absolute;
      top: 0; left: 0; right: 0; height: 5px;
    }

    .register-card {
      width: 100%; max-width: 420px;
      opacity: 0; animation: fadeUp .7s ease forwards .5s;
    }

    /* Step pill */
    .step-pill {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--amber-50);
      border: 1px solid #fde68a;
      border-radius: 99px; padding: 5px 14px 5px 5px;
      margin-bottom: 14px;
    }
    .step-pill-dot {
      width: 24px; height: 24px; border-radius: 50%;
      background: linear-gradient(135deg, var(--amber-500), var(--amber-600));
      color: var(--white); font-size: 11px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .step-pill-text { font-size: 12px; font-weight: 600; color: var(--amber-700); letter-spacing: .3px; }

    /* Progress bar */
    .progress-bar-wrap {
      width: 100%; height: 4px; background: var(--gray-100);
      border-radius: 99px; margin-bottom: 24px; overflow: hidden;
    }
    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--amber-500), var(--amber-300));
      border-radius: 99px;
      transition: width .5s cubic-bezier(.4,0,.2,1);
    }

    .card-title {
      font-family: arial, sans-serif;
      font-size: 25px;
      font-weight: 700; color: var(--gray-900);
      margin-bottom: 3px; line-height: 1.2;
    }
    .card-subtitle { font-size: 13px; color: var(--gray-500); margin-bottom: 20px; }

    /* Invite badge */
    .invite-badge {
      display: flex; align-items: center; gap: 8px;
      background: var(--amber-50); border: 1px solid var(--amber-300);
      border-radius: 10px; padding: 9px 14px;
      font-size: 13px; color: var(--amber-800); font-weight: 500; margin-bottom: 16px;
    }
    .invite-badge i { color: var(--amber-500); }

    /* Alert */
    .alert {
      display: flex; align-items: flex-start; gap: 10px;
      background: #fef3c7; border: 1px solid #fcd34d;
      border-left: 3px solid var(--amber-500); color: var(--amber-800);
      border-radius: 10px; padding: 11px 14px;
      font-size: 13px; margin-bottom: 18px;
    }

    /* Step panels */
    .step-panel { display: none; }
    .step-panel.active { display: block; animation: stepIn .32s ease; }
    @keyframes stepIn {
      from { opacity: 0; transform: translateX(20px); }
      to   { opacity: 1; transform: translateX(0); }
    }
    .step-panel.slide-back { animation: stepBack .32s ease; }
    @keyframes stepBack {
      from { opacity: 0; transform: translateX(-20px); }
      to   { opacity: 1; transform: translateX(0); }
    }

    /* Form */
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-field { margin-bottom: 14px; }
    .form-field label {
      display: block; font-size: 12.5px; font-weight: 600;
      color: var(--gray-700); margin-bottom: 6px;
    }
    .optional-tag { font-weight: 400; color: var(--gray-400); font-size: 11px; margin-left: 3px; }

    .input-wrap { position: relative; }
    .input-icon {
      position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
      color: var(--gray-300); font-size: 13px;
      pointer-events: none; transition: color .2s;
    }
    .form-field input {
      width: 100%; padding: 11px 14px 11px 38px;
      font-family: 'DM Sans', sans-serif; font-size: 14px;
      color: var(--gray-900); background: var(--gray-50);
      border: 1.5px solid var(--border); border-radius: 10px; outline: none;
      transition: border-color .2s, background .2s, box-shadow .2s;
    }
    .form-field input::placeholder { color: var(--gray-300); }
    .form-field input:focus {
      background: var(--white); border-color: var(--amber-400);
      box-shadow: 0 0 0 4px rgba(245,158,11,.1);
    }
    .input-wrap:focus-within .input-icon { color: var(--amber-500); }
    .form-field input[readonly] {
      background: var(--amber-50); border-color: var(--border);
      color: var(--amber-800); cursor: not-allowed;
    }

    .field-error { font-size: 11.5px; color: var(--red-400); margin-top: 5px; display: none; }
    .field-error.show { display: block; }

    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; color: var(--gray-300);
      cursor: pointer; font-size: 13px; padding: 4px; transition: color .2s;
    }
    .pw-toggle:hover { color: var(--amber-500); }

    /* Password rules */
    .pw-rules { display: grid; grid-template-columns: 1fr 1fr; gap: 5px 10px; margin-top: 9px; }
    .pw-rule {
      display: flex; align-items: center; gap: 5px;
      font-size: 11.5px; color: var(--gray-400); transition: color .2s;
    }
    .pw-rule i {
      font-size: 9px; width: 15px; height: 15px; border-radius: 50%;
      background: var(--gray-100); display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; transition: background .2s, color .2s;
    }
    .pw-rule.valid   { color: var(--green-500); }
    .pw-rule.valid i { background: #dcfce7; color: var(--green-500); }
    .pw-rule.invalid { color: var(--red-400); }
    .pw-rule.invalid i { background: #fee2e2; color: var(--red-400); }

    .confirm-indicator {
      display: flex; align-items: center; gap: 5px;
      font-size: 11.5px; color: var(--gray-400);
      margin-top: 6px; min-height: 16px;
    }
    .confirm-indicator.match   { color: var(--green-500); }
    .confirm-indicator.nomatch { color: var(--red-400); }

    /* Terms summary */
    .terms-summary {
      background: var(--gray-50); border: 1px solid var(--gray-100);
      border-radius: 12px; padding: 14px; margin-bottom: 16px;
    }
    .terms-item {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 8px 0; border-bottom: 1px solid var(--gray-100);
    }
    .terms-item:last-child { border-bottom: none; padding-bottom: 0; }
    .terms-item i { color: var(--amber-500); font-size: 12px; margin-top: 2px; flex-shrink: 0; }
    .terms-item-text strong { display: block; font-size: 12px; color: var(--gray-700); }
    .terms-item-text span   { font-size: 11.5px; color: var(--gray-400); line-height: 1.5; }

    .terms-check-row {
      display: flex; align-items: flex-start; gap: 10px; margin-bottom: 18px;
    }
    .terms-check-row input[type="checkbox"] {
      appearance: none; -webkit-appearance: none;
      width: 18px; height: 18px; border: 1.5px solid var(--gray-300);
      border-radius: 5px; background: var(--gray-50); cursor: pointer;
      flex-shrink: 0; margin-top: 1px; position: relative;
      transition: border-color .2s, background .2s;
    }
    .terms-check-row input[type="checkbox"]:checked { background: var(--amber-500); border-color: var(--amber-500); }
    .terms-check-row input[type="checkbox"]:checked::after {
      content: ''; position: absolute; left: 4px; top: 1px;
      width: 6px; height: 10px;
      border: 2px solid var(--white); border-top: none; border-left: none;
      transform: rotate(45deg);
    }
    .terms-label { font-size: 13px; color: var(--gray-500); line-height: 1.6; }
    .terms-label a { color: var(--amber-600); font-weight: 600; text-decoration: none; }
    .terms-label a:hover { color: var(--amber-700); }

    /* Buttons */
    .btn-row { display: grid; gap: 10px; }
    .btn-row.has-back { grid-template-columns: auto 1fr; }

    .btn-next, .btn-submit {
      width: 100%; padding: 12px;
      background: linear-gradient(135deg, var(--amber-500) 0%, var(--amber-600) 100%);
      color: var(--white); font-family: 'DM Sans', sans-serif;
      font-size: 14.5px; font-weight: 600; border: none; border-radius: 11px;
      cursor: pointer; letter-spacing: .3px;
      box-shadow: 0 4px 16px rgba(245,158,11,.35);
      transition: transform .18s, box-shadow .18s, filter .18s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-next:hover, .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 24px rgba(245,158,11,.45);
      filter: brightness(1.05);
    }
    .btn-next:active, .btn-submit:active { transform: translateY(0); }

    .btn-back {
      padding: 12px 18px;
      background: var(--gray-100); color: var(--gray-500);
      font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 600;
      border: none; border-radius: 11px; cursor: pointer;
      display: flex; align-items: center; gap: 6px; white-space: nowrap;
      transition: background .2s, color .2s;
    }
    .btn-back:hover { background: var(--gray-200); color: var(--gray-700); }

    .divider {
      display: flex; align-items: center; gap: 12px;
      margin: 18px 0; color: var(--gray-300); font-size: 12px;
    }
    .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--gray-100); }

    .signin-link { text-align: center; font-size: 13px; color: var(--gray-500); }
    .signin-link a { color: var(--amber-600); font-weight: 600; text-decoration: none; }
    .signin-link a:hover { color: var(--amber-700); }

    /* ── Responsive ── */
    @media (max-width: 900px) {
      html, body { overflow: auto; }
      .auth-wrapper { grid-template-columns: 1fr; height: auto; }
      .auth-left { padding: 40px 28px; min-height: 240px; }
      .left-subheader {
        white-space: normal; width: 100%; border-right: none;
        opacity: 0; animation: fadeUp .8s ease forwards 1.2s;
      }
      .left-steps { flex-direction: row; justify-content: center; gap: 4px; margin-top: 24px; }
      .left-step-item { flex-direction: column; align-items: center; }
      .left-step-item:not(:last-child)::after { display: none; }
      .left-step-text { padding: 5px 0 0; text-align: center; }
      .left-step-desc { display: none; }
      .left-step-label { font-size: 11px; }
      .left-step-circle { width: 28px; height: 28px; font-size: 11px; }
      .auth-right { padding: 28px 20px 48px; }
    }
    @media (max-width: 480px) {
      .form-row { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<div class="auth-wrapper">

  <!-- ══ LEFT PANEL ══ -->
  <div class="auth-left">
    <div class="auth-left-noise"></div>
    <div class="deco-ring deco-ring-1"></div>
    <div class="deco-ring deco-ring-2"></div>
    <div class="deco-ring deco-ring-3"></div>

    <div class="left-content">
      <div class="brand-logo">
        <img src="<?= base_url('assets/img/mctbs.png') ?>" alt="MCTBS Logo">
      </div>

      <h1 class="left-heading">
        Motal and Conteros<br>Transient Booking System
      </h1>
      <span class="heading-accent"></span>
      <p class="left-subheader" id="subheader">
        A Modern Booking System Designed for Baguio Transient House Services
      </p>

      <!-- Dynamic left step tracker -->
      <div class="left-steps" id="leftSteps">
        <div class="left-step-item active" data-step="1">
          <div class="left-step-circle" id="lsc-1">1</div>
          <div class="left-step-text">
            <div class="left-step-label">Your Details</div>
            <div class="left-step-desc">Name, email &amp; phone</div>
          </div>
        </div>
        <div class="left-step-item" data-step="2">
          <div class="left-step-circle" id="lsc-2">2</div>
          <div class="left-step-text">
            <div class="left-step-label">Security</div>
            <div class="left-step-desc">Create your password</div>
          </div>
        </div>
        <div class="left-step-item" data-step="3">
          <div class="left-step-circle" id="lsc-3">3</div>
          <div class="left-step-text">
            <div class="left-step-label">Confirm</div>
            <div class="left-step-desc">Review &amp; agree</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ RIGHT PANEL ══ -->
  <div class="auth-right">
    <div class="register-card">

      <?php if (!$expiredInvite): ?>
      <!-- Step pill + progress bar -->
      <div class="step-pill">
        <div class="step-pill-dot" id="pillNum">1</div>
        <span class="step-pill-text" id="pillText">Step 1 of 3 — Your Details</span>
      </div>
      <div class="progress-bar-wrap">
        <div class="progress-bar-fill" id="progressFill" style="width:33.33%"></div>
      </div>
      <?php endif; ?>

      <?php if ($msg): ?>
        <div class="alert alert-<?= $type ?>">
          <i class="fa fa-info-circle"></i>
          <?= sanitize($msg) ?>
        </div>
      <?php endif; ?>

      <?php if ($expiredInvite): ?>
        <h2 class="card-title">Invitation Expired</h2>
        <p class="card-subtitle">The invitation link you clicked has expired.</p>
        <div class="alert">
          <i class="fa fa-clock"></i>
          This invitation link is now expired. Please coordinate with the system administrator to resend the invitation.
        </div>
        <form method="POST">
          <input type="hidden" name="resend_invitation" value="1">
          <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;">
            <i class="fa fa-envelope"></i> Request Resend Invitation
          </button>
        </form>
        <div class="text-center mt-3">
          <a href="<?= base_url('modules/auth/login.php') ?>" class="btn btn-outline">Back to Login</a>
        </div>
      <?php else: ?>

      <form method="POST" id="registerForm" autocomplete="on">
        <?php if ($token): ?>
          <input type="hidden" name="token" value="<?= sanitize($token) ?>">
        <?php endif; ?>

        <!-- ────── STEP 1: Details ────── -->
        <div class="step-panel active" id="step-1">
          <?php if ($invite): ?>
            <div class="invite-badge">
              <i class="fa fa-envelope-open-text"></i>
              Invited as <strong>&nbsp;<?= htmlspecialchars($inviteLabel) ?></strong>
            </div>
          <?php endif; ?>

          <h2 class="card-title">Your Details</h2>
          <p class="card-subtitle">Tell us a bit about yourself to get started.</p>

          <div class="form-row">
            <div class="form-field">
              <label>First Name</label>
              <div class="input-wrap">
                <i class="fa fa-user input-icon"></i>
                <input type="text" id="first_name" name="first_name"
                       value="<?= sanitize($_POST['first_name'] ?? '') ?>"
                       placeholder="Juan" autofocus>
              </div>
              <div class="field-error" id="err-first_name">Enter your first name.</div>
            </div>
            <div class="form-field">
              <label>Last Name</label>
              <div class="input-wrap">
                <i class="fa fa-user input-icon"></i>
                <input type="text" id="last_name" name="last_name"
                       value="<?= sanitize($_POST['last_name'] ?? '') ?>"
                       placeholder="Dela Cruz">
              </div>
              <div class="field-error" id="err-last_name">Enter your last name.</div>
            </div>
          </div>

          <div class="form-field">
            <label>Email Address</label>
            <div class="input-wrap">
              <i class="fa fa-envelope input-icon"></i>
              <input type="email" id="email" name="email"
                     value="<?= sanitize($preEmail ?: ($_POST['email'] ?? '')) ?>"
                     placeholder="you@email.com"
                     <?= $invite ? 'readonly' : '' ?>>
            </div>
            <div class="field-error" id="err-email">Enter a valid email address.</div>
          </div>

          <div class="form-field">
            <label>Phone Number <span class="optional-tag">(optional)</span></label>
            <div class="input-wrap">
              <i class="fa fa-phone input-icon"></i>
              <input type="tel" id="phone" name="phone"
                     value="<?= sanitize($_POST['phone'] ?? '') ?>"
                     placeholder="+63 912 345 6789">
            </div>
          </div>

          <div class="btn-row">
            <button type="button" class="btn-next" id="nextTo2">
              Continue <i class="fa fa-arrow-right"></i>
            </button>
          </div>

          <?php if (!$invite): ?>
            <div class="divider">or</div>
            <p class="signin-link">
              Already have an account?
              <a href="<?= base_url('modules/auth/login.php') ?>">Sign In</a>
            </p>
          <?php endif; ?>
        </div>

        <!-- ────── STEP 2: Password ────── -->
        <div class="step-panel" id="step-2">
          <h2 class="card-title">Set Your Password</h2>
          <p class="card-subtitle">Create a strong password to protect your account.</p>

          <div class="form-field">
            <label>Password</label>
            <div class="input-wrap">
              <i class="fa fa-lock input-icon"></i>
              <input type="password" id="password" name="password"
                     placeholder="Create a strong password">
              <button type="button" class="pw-toggle" id="pwToggle1">
                <i class="fa fa-eye" id="pwIcon1"></i>
              </button>
            </div>
            <div class="field-error" id="err-password">Password does not meet all requirements.</div>
            <div class="pw-rules">
              <div class="pw-rule" id="rule-len">  <i class="fa fa-check"></i>8+ characters</div>
              <div class="pw-rule" id="rule-upper"><i class="fa fa-check"></i>Uppercase letter</div>
              <div class="pw-rule" id="rule-lower"><i class="fa fa-check"></i>Lowercase letter</div>
              <div class="pw-rule" id="rule-num">  <i class="fa fa-check"></i>Number</div>
              <div class="pw-rule" id="rule-spec"> <i class="fa fa-check"></i>Special character</div>
            </div>
          </div>

          <div class="form-field">
            <label>Confirm Password</label>
            <div class="input-wrap">
              <i class="fa fa-lock input-icon"></i>
              <input type="password" id="confirm_password" name="confirm_password"
                     placeholder="Re-enter your password">
              <button type="button" class="pw-toggle" id="pwToggle2">
                <i class="fa fa-eye" id="pwIcon2"></i>
              </button>
            </div>
            <div class="field-error" id="err-confirm">Passwords do not match.</div>
            <div class="confirm-indicator" id="confirmMsg"></div>
          </div>

          <div class="btn-row has-back">
            <button type="button" class="btn-back" id="backTo1">
              <i class="fa fa-arrow-left"></i> Back
            </button>
            <button type="button" class="btn-next" id="nextTo3">
              Continue <i class="fa fa-arrow-right"></i>
            </button>
          </div>
        </div>

        <!-- ────── STEP 3: Terms ────── -->
        <div class="step-panel" id="step-3">
          <h2 class="card-title">Almost There!</h2>
          <p class="card-subtitle">Review our terms before creating your account.</p>

          <div class="terms-summary">
            <div class="terms-item">
              <i class="fa fa-handshake"></i>
              <div class="terms-item-text">
                <strong>Acceptance of Use</strong>
                <span>Use MCTBS responsibly and provide honest information at all times.</span>
              </div>
            </div>
            <div class="terms-item">
              <i class="fa fa-calendar-check"></i>
              <div class="terms-item-text">
                <strong>Booking Policy</strong>
                <span>Downpayments are non-refundable. Full payment required within 24 hours.</span>
              </div>
            </div>
            <div class="terms-item">
              <i class="fa fa-shield-halved"></i>
              <div class="terms-item-text">
                <strong>Account Security</strong>
                <span>You are responsible for all activity under your account.</span>
              </div>
            </div>
            <div class="terms-item">
              <i class="fa fa-ban"></i>
              <div class="terms-item-text">
                <strong>Cancellation</strong>
                <span>Cancellations after acceptance forfeit the downpayment.</span>
              </div>
            </div>
          </div>

          <div class="terms-check-row">
            <input type="checkbox" id="agreeTerms" name="agree_terms" required>
            <label class="terms-label" for="agreeTerms">
              I have read and agree to the
              <a href="#" id="openTerms">Terms and Conditions</a> of MCTBS.
            </label>
          </div>
          <div class="field-error" id="err-terms" style="margin-top:-10px;margin-bottom:14px;">
            You must agree to the terms to continue.
          </div>

          <div class="btn-row has-back">
            <button type="button" class="btn-back" id="backTo2">
              <i class="fa fa-arrow-left"></i> Back
            </button>
            <button type="submit" class="btn-submit">
              <i class="fa fa-user-plus"></i> Create Account
            </button>
          </div>
        </div>

      </form>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══ Terms Modal ══ -->
<div id="termsModal" style="
  display:none; position:fixed; inset:0;
  background:rgba(0,0,0,.5); z-index:2000;
  align-items:center; justify-content:center;
  padding:20px; backdrop-filter:blur(3px);">
  <div style="
    background:var(--white); border-radius:18px;
    width:100%; max-width:480px; max-height:80vh;
    display:flex; flex-direction:column;
    box-shadow:0 24px 60px rgba(0,0,0,.2);
    animation:stepIn .25s ease;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:20px 22px 16px;border-bottom:1px solid var(--gray-100);">
      <span style="font-family:'Playfair Display',serif;font-size:19px;font-weight:700;color:var(--gray-900)">Terms &amp; Conditions</span>
      <button id="closeTerms" style="width:30px;height:30px;border-radius:8px;border:none;background:var(--gray-100);color:var(--gray-500);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;">
        <i class="fa fa-xmark"></i>
      </button>
    </div>
    <div style="padding:18px 22px;overflow-y:auto;flex:1;font-size:13px;color:var(--gray-500);line-height:1.75;">
      <p style="margin-bottom:12px"><strong style="color:var(--gray-700)">1. Acceptance</strong><br>By creating an account, you agree to use MCTBS responsibly and honestly.</p>
      <p style="margin-bottom:12px"><strong style="color:var(--gray-700)">2. Booking Policy</strong><br>Downpayments are non-refundable upon cancellation. Payment must be completed within 24 hours of booking acceptance.</p>
      <p style="margin-bottom:12px"><strong style="color:var(--gray-700)">3. Account Responsibility</strong><br>You are responsible for keeping your account credentials secure.</p>
      <p style="margin-bottom:12px"><strong style="color:var(--gray-700)">4. Accurate Information</strong><br>You agree to provide accurate and truthful information during registration and booking.</p>
      <p><strong style="color:var(--gray-700)">5. Cancellation</strong><br>Cancellations after acceptance will forfeit the downpayment. The system reserves the right to cancel unpaid bookings after the deadline.</p>
    </div>
    <div style="padding:16px 22px;border-top:1px solid var(--gray-100);">
      <button id="acceptTerms" class="btn-submit">
        <i class="fa fa-check"></i> I Understand &amp; Accept
      </button>
    </div>
  </div>
</div>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script>
// ── Step state
let currentStep = 1;
const TOTAL = 3;
const stepLabels = ['Your Details', 'Security', 'Confirm'];
const pillNum    = document.getElementById('pillNum');
const pillText   = document.getElementById('pillText');
const progress   = document.getElementById('progressFill');
const leftItems  = document.querySelectorAll('#leftSteps .left-step-item');

function goToStep(n, dir = 'forward') {
  document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active','slide-back'));
  const target = document.getElementById('step-' + n);
  target.classList.add('active');
  if (dir === 'back') target.classList.add('slide-back');

  pillNum.textContent  = n;
  pillText.textContent = `Step ${n} of ${TOTAL} — ${stepLabels[n-1]}`;
  progress.style.width = (n / TOTAL * 100) + '%';

  leftItems.forEach((item, i) => {
    item.classList.remove('active','done');
    const circle = item.querySelector('.left-step-circle');
    if (i + 1 === n)     { item.classList.add('active'); circle.textContent = i + 1; }
    else if (i + 1 < n) { item.classList.add('done');   circle.innerHTML = '<i class="fa fa-check" style="font-size:10px"></i>'; }
    else                  circle.textContent = i + 1;
  });

  currentStep = n;
}

// ── Field error helpers
function showErr(id, show) {
  const el = document.getElementById('err-' + id);
  if (el) el.classList.toggle('show', show);
}

// ── Password visibility toggles
function makePwToggle(btnId, iconId, inputId) {
  document.getElementById(btnId).addEventListener('click', () => {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    ico.className = isText ? 'fa fa-eye' : 'fa fa-eye-slash';
  });
}
makePwToggle('pwToggle1','pwIcon1','password');
makePwToggle('pwToggle2','pwIcon2','confirm_password');

// ── Password rules
const pwInput = document.getElementById('password');
const ruleEls = {
  len:   document.getElementById('rule-len'),
  upper: document.getElementById('rule-upper'),
  lower: document.getElementById('rule-lower'),
  num:   document.getElementById('rule-num'),
  spec:  document.getElementById('rule-spec'),
};
function setRule(el, valid) {
  el.classList.toggle('valid',   valid);
  el.classList.toggle('invalid', !valid && pwInput.value.length > 0);
}
function checkPw() {
  const v = pwInput.value;
  setRule(ruleEls.len,   v.length >= 8);
  setRule(ruleEls.upper, /[A-Z]/.test(v));
  setRule(ruleEls.lower, /[a-z]/.test(v));
  setRule(ruleEls.num,   /[0-9]/.test(v));
  setRule(ruleEls.spec,  /[^A-Za-z0-9]/.test(v));
}
function isPwValid() {
  const v = pwInput.value;
  return v.length >= 8 && /[A-Z]/.test(v) && /[a-z]/.test(v) && /[0-9]/.test(v) && /[^A-Za-z0-9]/.test(v);
}
pwInput.addEventListener('input', () => { checkPw(); checkConfirm(); });

// ── Confirm match
const confirmInput = document.getElementById('confirm_password');
const confirmMsg   = document.getElementById('confirmMsg');
function checkConfirm() {
  const val = confirmInput.value;
  if (!val) { confirmMsg.innerHTML = ''; confirmMsg.className = 'confirm-indicator'; return; }
  const match = val === pwInput.value;
  confirmMsg.className = 'confirm-indicator ' + (match ? 'match' : 'nomatch');
  confirmMsg.innerHTML = match
    ? '<i class="fa fa-check-circle"></i> Passwords match'
    : '<i class="fa fa-times-circle"></i> Passwords do not match';
}
confirmInput.addEventListener('input', checkConfirm);

// ── Navigation: Step 1 → 2
document.getElementById('nextTo2').addEventListener('click', () => {
  const fn = document.getElementById('first_name').value.trim();
  const ln = document.getElementById('last_name').value.trim();
  const em = document.getElementById('email').value.trim();
  const emailOk = em && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em);
  let ok = true;

  showErr('first_name', !fn);  if (!fn) ok = false;
  showErr('last_name',  !ln);  if (!ln) ok = false;
  showErr('email', !emailOk);  if (!emailOk) ok = false;

  if (ok) goToStep(2);
});

// ── Navigation: Step 2 → 3
document.getElementById('nextTo3').addEventListener('click', () => {
  const cf = document.getElementById('confirm_password').value;
  let ok = true;
  if (!isPwValid())          { showErr('password', true);  ok = false; } else showErr('password', false);
  if (!cf || cf !== pwInput.value) { showErr('confirm', true); ok = false; } else showErr('confirm', false);
  if (ok) goToStep(3);
});

// ── Back buttons
document.getElementById('backTo1').addEventListener('click', () => goToStep(1, 'back'));
document.getElementById('backTo2').addEventListener('click', () => goToStep(2, 'back'));

// ── Submit guard (step 3 terms)
document.getElementById('registerForm').addEventListener('submit', e => {
  const chk = document.getElementById('agreeTerms');
  if (!chk.checked) { e.preventDefault(); showErr('terms', true); }
});
document.getElementById('agreeTerms').addEventListener('change', () => showErr('terms', false));

// ── Terms modal
const modal    = document.getElementById('termsModal');
const openBtn  = document.getElementById('openTerms');
const closeBtn = document.getElementById('closeTerms');
const acceptBtn= document.getElementById('acceptTerms');
const termsChk = document.getElementById('agreeTerms');

openBtn.addEventListener('click',   e => { e.preventDefault(); modal.style.display = 'flex'; });
closeBtn.addEventListener('click',  () => modal.style.display = 'none');
modal.addEventListener('click', e  => { if (e.target === modal) modal.style.display = 'none'; });
acceptBtn.addEventListener('click', () => { termsChk.checked = true; modal.style.display = 'none'; });

// ── If PHP returned errors, jump to the right step
<?php if ($errors): ?>
const errMsg = <?= json_encode($errors[0]) ?>;
if (errMsg.includes('Password') || errMsg.includes('match')) goToStep(2);
else if (errMsg.includes('already registered') || errMsg.includes('email') || errMsg.includes('name')) goToStep(1);
<?php endif; ?>
</script>
</body>
</html>