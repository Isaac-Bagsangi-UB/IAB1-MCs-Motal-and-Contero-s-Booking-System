<?php
// cron/expire_bookings.php
// Run this every 15 minutes via XAMPP Task Scheduler or cron:
// */15 * * * * php /path/to/mctbs/cron/expire_bookings.php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = getDB();

// 1. Cancel accepted bookings past payment deadline with no verified payment
$expired = $db->query("
    SELECT b.*, u.email, u.first_name, u.last_name,
           tu.name as unit_name, th.owner_id
    FROM bookings b
    JOIN users u ON b.guest_id=u.id
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE b.status='accepted'
    AND b.payment_status='unpaid'
    AND b.payment_deadline < NOW()
")->fetchAll();

foreach ($expired as $b) {
    $db->prepare("UPDATE bookings SET status='cancelled', cancellation_reason='Payment deadline expired' WHERE id=?")
       ->execute([$b['id']]);

    // Free up calendar dates
    $db->prepare("
        UPDATE unit_calendar SET status='Available'
        WHERE unit_id=? AND date BETWEEN ? AND ? AND status='Booked'
    ")->execute([$b['unit_id'], $b['check_in'], $b['check_out']]);

    // Notify guest
    createNotification($b['guest_id'],'booking_cancelled','Booking Cancelled',"Your booking {$b['booking_code']} was cancelled due to missed payment deadline.");
    sendBookingStatusEmail($b['email'], $b['first_name'].' '.$b['last_name'], $b['booking_code'], 'cancelled', 'Payment deadline was not met.');

    // Notify owner/admin that slot is now available
    $ownerUsers = $db->prepare("
        SELECT u.id FROM users u
        LEFT JOIN owner_admins oa ON oa.owner_id=u.id
        WHERE u.id=? OR oa.owner_id=?
    ");
    $ownerUsers->execute([$b['owner_id'], $b['owner_id']]);
    foreach ($ownerUsers->fetchAll() as $owner) {
        createNotification($owner['id'],'payment_expired','Payment Expired - Slot Available',"Guest {$b['first_name']} {$b['last_name']} did not pay for booking {$b['booking_code']}. The dates are now available for other bookings.",base_url("modules/owner/bookings.php?unit_id={$b['unit_id']}"));
    }

    // Check if there are queued bookings for same dates/unit
    $queued = $db->prepare("
        SELECT bq.*, bk.guest_id, bk.booking_code, u.email, u.first_name, u.last_name
        FROM booking_queue bq
        JOIN bookings bk ON bq.booking_id=bk.id
        JOIN users u ON bk.guest_id=u.id
        WHERE bq.unit_id=? AND bq.queue_expires_at > NOW()
        ORDER BY bq.created_at ASC LIMIT 1
    ");
    $queued->execute([$b['unit_id']]);
    $next = $queued->fetch();
    if ($next) {
        createNotification($next['guest_id'],'queue_slot_available','A Unit Slot is Available!',"A slot opened up for your queued booking {$next['booking_code']}. Log in to proceed.",base_url("modules/guest/booking_detail.php?code={$next['booking_code']}"));
        sendMail($next['email'], "Booking Slot Available — {$next['booking_code']}",
            "<p>Hello {$next['first_name']},</p><p>A slot has opened up for your queued booking. Please log in to MCTBS to proceed.</p>");
    }

    echo "Cancelled booking: {$b['booking_code']}\n";
}

// 2. Expire queue entries older than QUEUE_EXPIRY_DAYS
$db->query("DELETE FROM booking_queue WHERE queue_expires_at < NOW()");

// 3. Auto-complete past-checkout accepted bookings (optional)
$pastDue = $db->query("
    SELECT b.*, th.owner_id, tu.name as unit_name
    FROM bookings b
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE b.status='accepted' AND b.payment_status='paid' AND b.check_out < CURDATE()
")->fetchAll();

foreach ($pastDue as $b) {
    $db->prepare("UPDATE bookings SET status='completed', completed_at=NOW() WHERE id=?")->execute([$b['id']]);
    $rcptNo = 'RCP-'.strtoupper(substr(uniqid(),-6)).'-'.rand(100,999);
    $db->prepare("INSERT IGNORE INTO receipts (booking_id,receipt_number) VALUES (?,?)")->execute([$b['id'],$rcptNo]);
    echo "Auto-completed booking: {$b['booking_code']}\n";
}

echo "Cron complete: " . date('Y-m-d H:i:s') . "\n";
