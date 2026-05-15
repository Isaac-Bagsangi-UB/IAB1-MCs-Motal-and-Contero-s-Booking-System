<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

if (isLoggedIn()) {
    $role = currentUser()['role'];
    redirect("modules/$role/dashboard.php");
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $errors[] = 'Please fill in all fields.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid email or password.';
        } elseif (!$user['email_verified_at'] && $user['role'] !== 'guest') {
            $errors[] = 'Please verify your email before logging in.';
        } elseif ($user['is_deactivated']) {
            $errors[] = 'Your account has been deactivated. Contact support.';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user']    = $user;
            flash('success', 'Welcome back, ' . $user['first_name'] . '!');
            if ($user['role'] === 'sysadmin') {
                redirect('modules/sysadmin/dashboard.php');
            } else {
                redirect("modules/{$user['role']}/dashboard.php");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | MCTBS</title>
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
      --gray-300:  #d1d5db;
      --gray-500:  #6b7280;
      --gray-700:  #374151;
      --gray-900:  #111827;
    }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--amber-50);
    }

    /* ── Layout ── */
    .auth-wrapper {
      display: grid;
      grid-template-columns: 1fr 1fr;
      min-height: 100vh;
    }

    /* ── LEFT PANEL ── */
    .auth-left {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 56px;
      overflow: hidden;
      background: linear-gradient(145deg, #b45309 0%, #d97706 35%, #f59e0b 65%, #fbbf24 100%);
    }

    /* Decorative circles */
    .auth-left::before,
    .auth-left::after {
      content: '';
      position: absolute;
      border-radius: 50%;
      pointer-events: none;
    }
    .auth-left::before {
      width: 520px; height: 520px;
      top: -160px; right: -160px;
      background: rgba(255,255,255,.07);
    }
    .auth-left::after {
      width: 340px; height: 340px;
      bottom: -120px; left: -100px;
      background: rgba(255,255,255,.06);
    }

    .deco-ring {
      position: absolute;
      border-radius: 50%;
      border: 1.5px solid rgba(255,255,255,.12);
      pointer-events: none;
    }
    .deco-ring-1 { width: 700px; height: 700px; top: -220px; right: -280px; }
    .deco-ring-2 { width: 420px; height: 420px; bottom: -160px; left: -140px; }
    .deco-ring-3 { width: 200px; height: 200px; bottom: 120px; right: 40px; }

    /* Noise texture overlay */
    .auth-left-noise {
      position: absolute; inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
      opacity: .5;
      pointer-events: none;
    }

    .left-content {
      position: relative;
      z-index: 1;
      text-align: center;
    }

    /* Logo */
    .brand-logo {
      width: 180px;
      height: 180px;
      margin: 0 auto 32px;
      background: rgba(255,255,255,.15);
      border-radius: 28px;
      backdrop-filter: blur(6px);
      border: 1.5px solid rgba(255,255,255,.25);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 8px 32px rgba(0,0,0,.15);
    }
    .brand-logo img {
      width: 220px;
      filter: brightness(0) invert(1);
    }

    @keyframes logoFloat {
      0%,100% { transform: translateY(0); }
      50%      { transform: translateY(-8px); }
    }

    /* Heading */
    .left-heading {
      font-family: arial, sans-serif;
      font-size: clamp(26px, 2.8vw, 36px);
      font-weight: 800;
      color: var(--white);
      line-height: 1.2;
      letter-spacing: -0.5px;
      margin-bottom: 20px;
      text-shadow: 0 2px 16px rgba(0,0,0,.2);
      /* word-by-word reveal animation */
      opacity: 0;
      animation: fadeUp .8s ease forwards .3s;
    }

    /* Animated underline accent */
    .heading-accent {
      display: block;
      width: 60px; height: 3px;
      background: rgba(255,255,255,.55);
      margin: 18px auto 22px;
      border-radius: 99px;
      animation: accentGrow 1s ease forwards .9s;
      transform-origin: center;
      transform: scaleX(0);
    }

    @keyframes accentGrow {
      to { transform: scaleX(1); }
    }

    /* Subheader typewriter */
    .left-subheader {
      font-size: clamp(14px, 1.2vw, 16px);
      font-weight: 400;
      color: rgba(255,255,255,.82);
      line-height: 1.7;
      letter-spacing: .2px;
      max-width: 360px;
      margin: 0 auto;
      overflow: hidden;
      border-right: 2px solid rgba(255,255,255,.7);
      white-space: nowrap;
      width: 0;
      animation:
        typing 2.4s steps(56, end) forwards 1.2s,
        blink  .75s step-end 6;
    }

    @keyframes typing {
      to { width: 100%; }
    }
    @keyframes blink {
      50% { border-color: transparent; }
    }

    /* After typing finishes, hide cursor */
    .left-subheader.done {
      border-right-color: transparent;
    }

    /* Badges / trust indicators */
    .trust-badges {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 18px;
      margin-top: 44px;
      opacity: 0;
      animation: fadeUp .7s ease forwards 3.8s;
    }
    .badge {
      display: flex;
      align-items: center;
      gap: 7px;
      background: rgba(255,255,255,.14);
      border: 1px solid rgba(255,255,255,.22);
      border-radius: 99px;
      padding: 7px 16px;
      color: rgba(255,255,255,.9);
      font-size: 12.5px;
      font-weight: 500;
      backdrop-filter: blur(4px);
    }
    .badge i { font-size: 12px; }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(18px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ── RIGHT PANEL ── */
    .auth-right {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 48px 40px;
      background: var(--white);
      position: relative;
    }

    .login-card {
      width: 100%;
      max-width: 420px;
      opacity: 0;
      animation: fadeUp .7s ease forwards .5s;
    }

    /* Card header */
    .card-eyebrow {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: var(--amber-600);
      margin-bottom: 10px;
    }
    .card-eyebrow-dot {
      width: 6px; height: 6px;
      border-radius: 50%;
      background: var(--amber-500);
    }

    .card-title {
      font-family: arial, sans-serif;

      font-size: 30px;
      font-weight: 700;
      color: var(--gray-900);
      margin-bottom: 6px;
      line-height: 1.2;
    }

    .card-subtitle {
      font-size: 14px;
      color: var(--gray-500);
      margin-bottom: 36px;
    }

    /* Alert */
    .alert {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      background: #fef3c7;
      border: 1px solid #fcd34d;
      border-left: 3px solid var(--amber-500);
      color: #92400e;
      border-radius: 10px;
      padding: 13px 16px;
      font-size: 13.5px;
      margin-bottom: 22px;
      line-height: 1.5;
    }
    .alert i { margin-top: 1px; flex-shrink: 0; }

    /* Form */
    .form-field {
      margin-bottom: 20px;
    }

    .form-field label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: var(--gray-700);
      margin-bottom: 8px;
      letter-spacing: .1px;
    }

    .input-wrap {
      position: relative;
    }

    .input-icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--gray-300);
      font-size: 14px;
      pointer-events: none;
      transition: color .2s;
    }

    .form-field input {
      width: 100%;
      padding: 13px 16px 13px 42px;
      font-family: 'DM Sans', sans-serif;
      font-size: 14.5px;
      color: var(--gray-900);
      background: var(--gray-50);
      border: 1.5px solid var(--border);
      border-radius: 12px;
      outline: none;
      transition: border-color .22s, background .22s, box-shadow .22s;
    }

    .form-field input::placeholder { color: var(--gray-300); }

    .form-field input:focus {
      background: var(--white);
      border-color: var(--amber-400);
      box-shadow: 0 0 0 4px rgba(245,158,11,.1);
    }

    .form-field input:focus ~ .input-icon,
    .input-wrap:focus-within .input-icon {
      color: var(--amber-500);
    }

    /* Password toggle */
    .pw-toggle {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--gray-300);
      cursor: pointer;
      font-size: 14px;
      padding: 4px;
      transition: color .2s;
    }
    .pw-toggle:hover { color: var(--amber-500); }

    /* Forgot link row */
    .forgot-row {
      display: flex;
      justify-content: flex-end;
      margin: -8px 0 24px;
    }
    .forgot-row a {
      font-size: 12.5px;
      font-weight: 500;
      color: var(--amber-600);
      text-decoration: none;
      transition: color .2s;
    }
    .forgot-row a:hover { color: var(--amber-700); }

    /* Submit button */
    .btn-signin {
      width: 100%;
      padding: 14.5px;
      background: linear-gradient(135deg, var(--amber-500) 0%, var(--amber-600) 100%);
      color: var(--white);
      font-family: 'DM Sans', sans-serif;
      font-size: 15px;
      font-weight: 600;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      letter-spacing: .3px;
      box-shadow: 0 4px 16px rgba(245,158,11,.35);
      transition: transform .18s, box-shadow .18s, filter .18s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
    }

    .btn-signin:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 24px rgba(245,158,11,.45);
      filter: brightness(1.05);
    }

    .btn-signin:active {
      transform: translateY(0);
      box-shadow: 0 2px 10px rgba(245,158,11,.3);
    }

    /* Divider */
    .divider {
      display: flex;
      align-items: center;
      gap: 14px;
      margin: 28px 0;
      color: var(--gray-300);
      font-size: 12px;
    }
    .divider::before,
    .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--gray-100);
    }

    /* Register link */
    .register-link {
      text-align: center;
      font-size: 13.5px;
      color: var(--gray-500);
    }
    .register-link a {
      color: var(--amber-600);
      font-weight: 600;
      text-decoration: none;
      transition: color .2s;
    }
    .register-link a:hover { color: var(--amber-700); }

    /* ── Responsive ── */
    @media (max-width: 900px) {
      .auth-wrapper { grid-template-columns: 1fr; }
      .auth-left {
        padding: 50px 32px;
        min-height: 320px;
      }
      .left-subheader {
        white-space: normal;
        width: 100%;
        border-right: none;
        animation: fadeIn 1s ease forwards 1.2s;
        opacity: 0;
      }
      @keyframes fadeIn { to { opacity: 1; } }
      .auth-right { padding: 48px 24px; }
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

      <div class="trust-badges">
        <div class="badge"><i class="fa fa-shield-halved"></i> Secure</div>
        <div class="badge"><i class="fa fa-bolt"></i> Fast Booking</div>
        <div class="badge"><i class="fa fa-star"></i> Trusted</div>
      </div>
    </div>
  </div>

  <!-- ══ RIGHT PANEL ══ -->
  <div class="auth-right">
    <div class="login-card">

      <div class="card-eyebrow">
        <div class="card-eyebrow-dot"></div>
        Welcome back
      </div>
      <h2 class="card-title">Sign in to your account</h2>
      <p class="card-subtitle">Enter your credentials to access your dashboard.</p>

      <?php if ($errors): ?>
        <div class="alert">
          <i class="fa fa-exclamation-circle"></i>
          <?= sanitize($errors[0]) ?>
        </div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">

        <div class="form-field">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <i class="fa fa-envelope input-icon"></i>
            <input
              type="email"
              id="email"
              name="email"
              value="<?= sanitize($_POST['email'] ?? '') ?>"
              placeholder="you@email.com"
              required
              autofocus
            >
          </div>
        </div>

        <div class="form-field">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fa fa-lock input-icon"></i>
            <input
              type="password"
              id="password"
              name="password"
              placeholder="••••••••"
              required
            >
            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
              <i class="fa fa-eye" id="pwIcon"></i>
            </button>
          </div>
        </div>

        <div class="forgot-row">
          <a href="<?= base_url('modules/auth/forgot.php') ?>">Forgot password?</a>
        </div>

        <button type="submit" class="btn-signin">
          <i class="fa fa-arrow-right-to-bracket"></i>
          Sign In
        </button>

      </form>

      <div class="divider">or</div>

      <p class="register-link">
        Don't have an account?&nbsp;
        <a href="<?= base_url('modules/auth/register.php') ?>">Register as Guest</a>
      </p>

    </div>
  </div>

</div>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
<script>
  // Password visibility toggle
  const pwToggle = document.getElementById('pwToggle');
  const pwInput  = document.getElementById('password');
  const pwIcon   = document.getElementById('pwIcon');

  pwToggle.addEventListener('click', () => {
    const isText = pwInput.type === 'text';
    pwInput.type = isText ? 'password' : 'text';
    pwIcon.className = isText ? 'fa fa-eye' : 'fa fa-eye-slash';
  });

  // Remove typewriter cursor after animation
  const sub = document.getElementById('subheader');
  setTimeout(() => sub.classList.add('done'), 3700);
</script>
</body>
</html>