<?php
// includes/mailer.php
// All email sending goes through this file.
// To configure SMTP credentials, edit: config/mail.php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../vendor/phpmailer/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Core send function — all other functions call this.
 * Returns true on success, false on failure.
 * On failure, error is logged to PHP error log.
 */
function sendMail(string $to, string $subject, string $htmlBody, string $toName = ''): bool
{
    preg_match('/href=["\']([^"\']+)["\']/', $htmlBody, $matches);
    $link = isset($matches[1]) ? html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8') : null;

    if ($link) {
        $_SESSION['dev_mail'][] = [
            'to'      => $to,
            'subject' => $subject,
            'link'    => $link,
        ];
    }
    return true;
}

/**
 * Wraps email body in a consistent branded HTML template.
 */
function wrapEmailTemplate(string $subject, string $body): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$subject}</title>
  <style>
    body { margin:0; padding:0; background:#f0f2f5; font-family:'Segoe UI',Arial,sans-serif; }
    .wrapper { max-width:600px; margin:32px auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.1); }
    .header { background:#2c3e50; padding:24px 32px; }
    .header h1 { color:#fff; margin:0; font-size:20px; font-weight:700; }
    .header p  { color:rgba(255,255,255,.65); margin:4px 0 0; font-size:13px; }
    .body { padding:32px; color:#2d3748; line-height:1.75; font-size:15px; }
    .body p { margin:0 0 16px; }
    .body a.btn {
      display:inline-block; background:#e67e22; color:#fff; padding:12px 28px;
      border-radius:7px; text-decoration:none; font-weight:700; font-size:15px;
      margin:8px 0 16px;
    }
    .footer { background:#f7f8fa; border-top:1px solid #e2e8f0; padding:16px 32px; font-size:12px; color:#a0aec0; text-align:center; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>🏠 MCTBS</h1>
      <p>Motal and Conteros Transient Booking System</p>
    </div>
    <div class="body">
      {$body}
    </div>
    <div class="footer">
      This is an automated message from MCTBS. Please do not reply to this email.
    </div>
  </div>
</body>
</html>
HTML;
}

// ══════════════════════════════════════════════════════════
// Specific email functions — called throughout the system
// ══════════════════════════════════════════════════════════

function sendInviteEmail(string $email, string $token, string $role): bool
{
    $link      = base_url("modules/auth/register.php?token={$token}&email=" . urlencode($email));
    $roleLabel = ucfirst($role);
    $body = <<<HTML
<p>Hello,</p>
<p>You have been invited to join <strong>MCTBS</strong> as a <strong>{$roleLabel}</strong>.</p>
<p>Click the button below to create your account. This invitation link expires in <strong>24 hours</strong>.</p>
<p><a href="{$link}" class="btn">Create My Account</a></p>
<p style="font-size:13px;color:#718096">Or copy and paste this link into your browser:<br>
<a href="{$link}" style="color:#e67e22;word-break:break-all">{$link}</a></p>
<p>If you did not expect this invitation, you can safely ignore this email.</p>
HTML;
    return sendMail($email, "You're invited to join MCTBS as {$roleLabel}", $body, $email);
}

function sendVerificationEmail(string $email, string $token): bool
{
    $link = base_url("modules/auth/verify.php?token={$token}");
    $body = <<<HTML
<p>Hello,</p>
<p>Thank you for registering with <strong>MCTBS</strong>!</p>
<p>Please verify your email address by clicking the button below. This link expires in <strong>24 hours</strong>.</p>
<p><a href="{$link}" class="btn">Verify My Email</a></p>
<p style="font-size:13px;color:#718096">Or copy and paste this link:<br>
<a href="{$link}" style="color:#e67e22;word-break:break-all">{$link}</a></p>
HTML;
    return sendMail($email, 'Verify your MCTBS email address', $body);
}

function sendPasswordResetEmail(string $email, string $token): bool
{
    $link = base_url("modules/auth/reset.php?token={$token}");
    $body = <<<HTML
<p>Hello,</p>
<p>We received a request to reset your MCTBS password.</p>
<p>Click the button below to choose a new password. This link expires in <strong>1 hour</strong>.</p>
<p><a href="{$link}" class="btn">Reset My Password</a></p>
<p style="font-size:13px;color:#718096">Or copy and paste this link:<br>
<a href="{$link}" style="color:#e67e22;word-break:break-all">{$link}</a></p>
<p style="font-size:13px;color:#718096">If you did not request a password reset, no action is needed.</p>
HTML;
    return sendMail($email, 'MCTBS Password Reset Request', $body);
}

function sendBookingStatusEmail(string $guestEmail, string $guestName, string $bookingCode, string $status, string $note = ''): bool
{
    $labels = [
        'accepted'  => ['Booking Accepted ✅',   '#27ae60'],
        'rejected'  => ['Booking Not Accepted',  '#e74c3c'],
        'completed' => ['Stay Completed 🎉',     '#2980b9'],
        'cancelled' => ['Booking Cancelled',     '#7f8c8d'],
    ];
    [$label, $color] = $labels[$status] ?? [ucfirst($status), '#2c3e50'];
    $noteHtml = $note ? "<p style='background:#fff8e1;border-left:4px solid #f39c12;padding:10px 14px;border-radius:4px;font-size:14px'><strong>Note:</strong> " . htmlspecialchars($note) . "</p>" : '';
    $link     = base_url("modules/guest/booking_detail.php?code={$bookingCode}");

    $body = <<<HTML
<p>Hello <strong>{$guestName}</strong>,</p>
<p>Your booking <strong style="color:{$color}">{$bookingCode}</strong> has been updated.</p>
<p style="font-size:18px;font-weight:700;color:{$color}">{$label}</p>
{$noteHtml}
<p><a href="{$link}" class="btn">View Booking Details</a></p>
HTML;
    return sendMail($guestEmail, "MCTBS — {$bookingCode}: {$label}", $body, $guestName);
}

function sendPaymentDeadlineEmail(string $guestEmail, string $guestName, string $bookingCode, string $deadline): bool
{
    $link = base_url("modules/guest/payment.php?code={$bookingCode}");
    $body = <<<HTML
<p>Hello <strong>{$guestName}</strong>,</p>
<p>Great news — your booking <strong>{$bookingCode}</strong> has been <strong style="color:#27ae60">accepted!</strong></p>
<p>Please complete your downpayment to confirm your reservation.</p>
<p style="background:#fff3cd;border-left:4px solid #f39c12;padding:10px 14px;border-radius:4px;font-size:14px">
  <strong>⚠️ Payment Deadline:</strong> {$deadline}<br>
  If payment is not received by this time, your booking will be automatically cancelled.
</p>
<p><a href="{$link}" class="btn">Pay Now</a></p>
HTML;
    return sendMail($guestEmail, "Action Required: Pay for {$bookingCode} before {$deadline}", $body, $guestName);
}

function sendPaymentVerifiedEmail(string $guestEmail, string $guestName, string $bookingCode, string $amount): bool
{
    $link = base_url("modules/guest/booking_detail.php?code={$bookingCode}");
    $body = <<<HTML
<p>Hello <strong>{$guestName}</strong>,</p>
<p>Your payment of <strong style="color:#27ae60">{$amount}</strong> for booking <strong>{$bookingCode}</strong> has been <strong>verified</strong>. ✅</p>
<p>Your reservation is being processed. We'll send you a receipt once your stay is confirmed.</p>
<p><a href="{$link}" class="btn">View Booking</a></p>
HTML;
    return sendMail($guestEmail, "Payment Verified — {$bookingCode}", $body, $guestName);
}

function sendReceiptEmail(string $guestEmail, string $guestName, string $bookingCode): bool
{
    $link = base_url("modules/guest/receipt.php?code={$bookingCode}");
    $body = <<<HTML
<p>Hello <strong>{$guestName}</strong>,</p>
<p>Your reservation is <strong style="color:#27ae60">confirmed!</strong> 🎉</p>
<p>Booking code: <strong>{$bookingCode}</strong></p>
<p>Click below to view and print your official digital receipt.</p>
<p><a href="{$link}" class="btn">View My Receipt</a></p>
<p>Thank you for choosing MCTBS. We look forward to your stay!</p>
HTML;
    return sendMail($guestEmail, "Booking Confirmed — {$bookingCode} | Your Receipt", $body, $guestName);
}

function sendAdminMessageEmail(string $guestEmail, string $guestName, string $bookingCode, string $message, string $houseName = 'Admin'): bool
{
    $link = base_url("modules/guest/booking_detail.php?code={$bookingCode}");
    $body = <<<HTML
<p>Hello <strong>{$guestName}</strong>,</p>
<p>You have a message from <strong>{$houseName}</strong> regarding your booking <strong>{$bookingCode}</strong>:</p>
<p style="background:#f0f2f5;padding:14px 18px;border-radius:6px;font-size:14px">{$message}</p>
<p><a href="{$link}" class="btn">View Booking</a></p>
HTML;
    return sendMail($guestEmail, "Message about your booking {$bookingCode}", $body, $guestName);
}