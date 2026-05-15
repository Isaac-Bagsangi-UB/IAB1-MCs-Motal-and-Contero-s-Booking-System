<?php
// config/mail.php
// ─────────────────────────────────────────────────────────────
// MCTBS Mail Configuration
// Edit ONLY this file to set up email sending.
// ─────────────────────────────────────────────────────────────
//
// OPTION 1 — Gmail (recommended for testing)
//   1. Go to your Google Account → Security → 2-Step Verification (enable it)
//   2. Then go to: myaccount.google.com/apppasswords
//   3. Create an App Password for "Mail"
//   4. Paste the 16-character app password into MAIL_PASSWORD below
//
// OPTION 2 — Mailtrap (best for development, catches all emails)
//   Sign up free at mailtrap.io → Inboxes → SMTP Settings
//   Use the credentials they give you below
//
// OPTION 3 — Other SMTP (Outlook, Yahoo, custom server)
//   Fill in your provider's SMTP details below
// ─────────────────────────────────────────────────────────────

return [

    // ── Sender identity ──────────────────────────────────────
    'from_email' => 'noreply@mctbs.com',       // Can be your Gmail address
    'from_name'  => 'MCTBS Booking System',

    // ── SMTP credentials ─────────────────────────────────────

    // Gmail example:
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'tls',                     // 'tls' for port 587, 'ssl' for port 465
    'username'   => 'yourgmail@gmail.com',     // Your Gmail address
    'password'   => 'dmom bptj zjyg rgjr',     // 16-char App Password (NOT your Gmail password)

    // Mailtrap example (comment out Gmail above, uncomment below):
    // 'host'       => 'sandbox.smtp.mailtrap.io',
    // 'port'       => 2525,
    // 'encryption' => 'tls',
    // 'username'   => 'your_mailtrap_username',
    // 'password'   => 'your_mailtrap_password',

    // Outlook / Hotmail example:
    // 'host'       => 'smtp.office365.com',
    // 'port'       => 587,
    // 'encryption' => 'tls',
    // 'username'   => 'you@outlook.com',
    // 'password'   => 'your_password',

    // ── Debug (set to 0 in production) ───────────────────────
    // 0 = off, 1 = client messages, 2 = client+server messages
    'debug' => 0,
];

