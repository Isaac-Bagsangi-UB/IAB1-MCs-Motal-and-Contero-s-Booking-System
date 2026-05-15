<?php
// modules/owner/booking_detail.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireRole('owner','admin');

$db   = getDB();
$user = currentUser();
$ownerId = $user['id'];
if ($user['role']==='admin') {
    $stmt = $db->prepare("SELECT owner_id FROM owner_admins WHERE admin_id=?");
    $stmt->execute([$user['id']]);
    $ownerId = $stmt->fetchColumn();
}

$id = intval($_GET['id'] ?? 0);
$stmt = $db->prepare("
    SELECT b.*, u.first_name, u.last_name, u.email, u.phone,
           tu.name as unit_name, tu.price_per_night, tu.max_guests as unit_max_guests,
           th.name as house_name, th.owner_id
    FROM bookings b
    JOIN users u ON b.guest_id=u.id
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE b.id=? AND th.owner_id=?
");
$stmt->execute([$id, $ownerId]);
$booking = $stmt->fetch();
if (!$booking) { flash('error','Booking not found.'); redirect("modules/{$user['role']}/bookings.php"); }

$baseModule = $user['role']==='admin' ? 'admin' : 'owner';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'accept' && $booking['status'] === 'pending') {
        // Owner can accept any pending booking (first come first serve)
        // No conflict check - if guest doesn't pay, cron will auto-cancel and free the dates
        $deadline = date('Y-m-d H:i:s', strtotime('+'.PAYMENT_DEADLINE_HOURS.' hours'));
        $db->prepare("UPDATE bookings SET status='accepted', payment_deadline=? WHERE id=?")->execute([$deadline,$id]);
        $checkin  = new DateTime($booking['check_in']);
        $checkout = new DateTime($booking['check_out']);
        foreach (new DatePeriod($checkin, new DateInterval('P1D'), $checkout) as $dt) {
            $db->prepare("INSERT INTO unit_calendar (unit_id,date,status) VALUES (?,?,'Booked') ON DUPLICATE KEY UPDATE status='Booked'")->execute([$booking['unit_id'],$dt->format('Y-m-d')]);
        }
        $guestName = $booking['first_name'].' '.$booking['last_name'];
        createNotification($booking['guest_id'],'booking_accepted','Booking Accepted',"Your booking {$booking['booking_code']} has been accepted. Please pay within 24 hours.",base_url("modules/guest/payment.php?code={$booking['booking_code']}"));
        sendPaymentDeadlineEmail($booking['email'],$guestName,$booking['booking_code'],date('F j, Y h:i A',strtotime($deadline)));
        notifyOwnerAndAdmins($ownerId,$booking['unit_id'],'booking_accepted',"Booking Accepted","{$booking['booking_code']} has been accepted.");
        flash('success','Booking accepted. Guest notified.');

    } elseif ($act === 'reject' && $booking['status'] === 'pending') {
        $reason = trim($_POST['reason'] ?? '');
        $db->prepare("UPDATE bookings SET status='rejected', rejection_reason=? WHERE id=?")->execute([$reason,$id]);
        createNotification($booking['guest_id'],'booking_rejected','Booking Rejected',"Your booking {$booking['booking_code']} was not accepted. ".($reason?"Reason: $reason":'You may choose another unit.'));
        sendBookingStatusEmail($booking['email'],$booking['first_name'].' '.$booking['last_name'],$booking['booking_code'],'rejected',$reason?:'You may still choose other available units.');
        flash('success','Booking rejected.');

    } elseif ($act === 'verify_payment') {
        $pid = intval($_POST['payment_id']);
        $db->prepare("UPDATE payments SET status='verified', verified_by=?, verified_at=NOW() WHERE id=?")->execute([$user['id'],$pid]);
        $totalPaid = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND status='verified'");
        $totalPaid->execute([$id]);
        $totalPaid = floatval($totalPaid->fetchColumn());
        if ($totalPaid >= $booking['total_amount']) {
            $db->prepare("UPDATE bookings SET payment_status='paid' WHERE id=?")->execute([$id]);
        } elseif ($totalPaid >= $booking['downpayment_amount']) {
            $db->prepare("UPDATE bookings SET payment_status='downpaid' WHERE id=?")->execute([$id]);
        }
        if ($booking['additional_downpayment_required'] > 0 && $totalPaid >= $booking['downpayment_amount']) {
            $db->prepare("UPDATE bookings SET additional_downpayment_required=0 WHERE id=?")->execute([$id]);
            $ci2 = new DateTime($booking['check_in']);
            $co2 = new DateTime($booking['check_out']);
            foreach (new DatePeriod($ci2, new DateInterval('P1D'), $co2) as $dt) {
                $db->prepare("INSERT INTO unit_calendar (unit_id,date,status) VALUES (?,?,'Booked') ON DUPLICATE KEY UPDATE status='Booked'")->execute([$booking['unit_id'],$dt->format('Y-m-d')]);
            }
        }
        createNotification($booking['guest_id'],'payment_verified','Payment Verified',"Your payment for {$booking['booking_code']} has been verified!");
        flash('success','Payment verified.');

    } elseif ($act === 'reject_payment') {
        $pid = intval($_POST['payment_id']);
        $db->prepare("UPDATE payments SET status='rejected' WHERE id=?")->execute([$pid]);
        createNotification($booking['guest_id'],'payment_rejected','Payment Rejected',"Your payment for {$booking['booking_code']} was rejected. Please resubmit.");
        flash('error','Payment rejected.');

    } elseif ($act === 'owner_cancel') {
        $reason = trim($_POST['cancel_reason'] ?? '');
        if (!$reason) {
            flash('error','Please provide a cancellation reason.');
        } else {
            $db->prepare("UPDATE bookings SET status='cancelled', cancellation_reason=?, cancelled_by=?, cancelled_by_role=?, refund_status='requested' WHERE id=?")
               ->execute([$reason,$user['id'],$user['role'],$id]);
            $ci = new DateTime($booking['check_in']);
            $co = new DateTime($booking['check_out']);
            foreach (new DatePeriod($ci, new DateInterval('P1D'), $co) as $dt) {
                $db->prepare("UPDATE unit_calendar SET status='available' WHERE unit_id=? AND date=?")->execute([$booking['unit_id'],$dt->format('Y-m-d')]);
            }
            createNotification($booking['guest_id'],'booking_cancelled','Booking Cancelled by Owner',
                "Your booking {$booking['booking_code']} was cancelled. Reason: {$reason}. Your downpayment is refundable.",
                base_url("modules/guest/booking_detail.php?code={$booking['booking_code']}"));
            sendBookingStatusEmail($booking['email'],$booking['first_name'].' '.$booking['last_name'],$booking['booking_code'],'cancelled',$reason);
            flash('success','Booking cancelled. Guest notified.');
        }

    } elseif ($act === 'mark_refunded') {
        $db->prepare("UPDATE bookings SET refund_status='refunded' WHERE id=?")->execute([$id]);
        createNotification($booking['guest_id'],'refund_processed','Downpayment Refunded',"Your downpayment for {$booking['booking_code']} has been refunded.");
        flash('success','Marked as refunded.');

    } elseif ($act === 'edit_booking') {
        $newCI      = $_POST['new_check_in']   ?? '';
        $newCO      = $_POST['new_check_out']  ?? '';
        $newGuests  = intval($_POST['new_num_guests'] ?? 1);
        $editReason = trim($_POST['edit_reason'] ?? '');
        if (!$newCI || !$newCO || $newCO <= $newCI) {
            flash('error','Invalid dates.');
        } elseif (!$editReason) {
            flash('error','Please provide a reason for the edit.');
        } else {
            // Allow date edits even with conflicts - if guest doesn't pay, cron will auto-cancel
            $nights      = nightsBetween($newCI,$newCO);
            $maxGuests   = intval($booking['unit_max_guests']);
            $extraHeads  = max(0,$newGuests-$maxGuests);
            $extraCharge = $extraHeads * EXTRA_GUEST_RATE;
            $newTotal    = ($nights * $booking['price_per_night']) + $extraCharge;
            $newDown     = round($newTotal*0.5,2);
            $newBalance  = round($newTotal*0.5,2);
            $paidStmt    = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND status='verified'");
            $paidStmt->execute([$id]);
            $alreadyPaid = floatval($paidStmt->fetchColumn());
            $addlRequired = max(0,round($newDown-$alreadyPaid,2));

            $db->prepare("INSERT INTO booking_edits (booking_id,edited_by,old_check_in,old_check_out,old_num_guests,old_total,new_check_in,new_check_out,new_num_guests,new_total,reason,additional_downpayment) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$id,$user['id'],$booking['check_in'],$booking['check_out'],$booking['num_guests'],$booking['total_amount'],$newCI,$newCO,$newGuests,$newTotal,$editReason,$addlRequired]);

            $ci = new DateTime($booking['check_in']);
            $co = new DateTime($booking['check_out']);
            foreach (new DatePeriod($ci, new DateInterval('P1D'), $co) as $dt) {
                $db->prepare("UPDATE unit_calendar SET status='Available' WHERE unit_id=? AND date=? AND status='Booked'")->execute([$booking['unit_id'],$dt->format('Y-m-d')]);
            }

            $db->prepare("UPDATE bookings SET check_in=?,check_out=?,num_guests=?,total_nights=?,extra_guest_charge=?,total_amount=?,downpayment_amount=?,remaining_balance=?,additional_downpayment_required=? WHERE id=?")
               ->execute([$newCI,$newCO,$newGuests,$nights,$extraCharge,$newTotal,$newDown,$newBalance,$addlRequired,$id]);

            if ($addlRequired <= 0) {
                $ci2 = new DateTime($newCI);
                $co2 = new DateTime($newCO);
                foreach (new DatePeriod($ci2, new DateInterval('P1D'), $co2) as $dt) {
                    $db->prepare("INSERT INTO unit_calendar (unit_id,date,status) VALUES (?,?,'Booked') ON DUPLICATE KEY UPDATE status='Booked'")->execute([$booking['unit_id'],$dt->format('Y-m-d')]);
                }
            }

            $addlMsg = $addlRequired > 0 ? ' Additional downpayment of '.formatMoney($addlRequired).' required.' : '';
            createNotification($booking['guest_id'],'booking_edited','Booking Updated',
                "Your booking {$booking['booking_code']} was updated. New dates: {$newCI} to {$newCO}, Guests: {$newGuests}, New total: ".formatMoney($newTotal).".{$addlMsg}",
                base_url("modules/guest/booking_detail.php?code={$booking['booking_code']}"));
            sendBookingStatusEmail($booking['email'],$booking['first_name'].' '.$booking['last_name'],$booking['booking_code'],'accepted',"Booking details updated. New dates: {$newCI} to {$newCO}.{$addlMsg}");
            flash('success','Booking updated. Guest notified.'.$addlMsg);
        }
    } elseif ($act === 'notify_guest') {
        $msg = trim($_POST['notify_message'] ?? '');
        if ($msg) {
            createNotification($booking['guest_id'],'admin_message','Message from '.sanitize($booking['house_name']),$msg);
            sendAdminMessageEmail($booking['email'],$booking['first_name'].' '.$booking['last_name'],$booking['booking_code'],$msg,$booking['house_name']);
            flash('success','Guest notified.');
        }
    } elseif ($act === 'delete_review') {
        $reviewCheck = $db->prepare("SELECT id FROM booking_reviews WHERE booking_id=?");
        $reviewCheck->execute([$id]);
        $reviewExists = $reviewCheck->fetch();
        if (!$reviewExists) {
            flash('error', 'Review not found.');
        } else {
            $db->prepare("DELETE FROM booking_reviews WHERE booking_id=?")
               ->execute([$id]);
            flash('success', 'Review has been deleted.');
        }
    } elseif ($act === 'archive_booking') {
        $archiveNote = trim($_POST['archive_note'] ?? '');
        if (!$archiveNote) {
            flash('error', 'Please provide an archive note.');
        } elseif (!in_array($booking['status'], ['completed', 'cancelled'])) {
            flash('error', 'Only completed or cancelled bookings can be archived.');
        } else {
            // Archive booking
            $archiveStmt = $db->prepare("
                INSERT INTO bookings_archive (
                    booking_code, unit_id, guest_id, check_in, check_out, num_guests,
                    total_nights, price_per_night, total_amount, downpayment_amount,
                    remaining_balance, status, payment_status, cancellation_policy_acknowledged,
                    guest_notes, admin_notes, archive_note, rejection_reason, cancellation_reason,
                    cancelled_by, payment_deadline, confirmed_at, completed_at,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            $archiveStmt->execute([
                $booking['booking_code'], $booking['unit_id'], $booking['guest_id'],
                $booking['check_in'], $booking['check_out'], $booking['num_guests'],
                $booking['total_nights'], $booking['price_per_night'], $booking['total_amount'],
                $booking['downpayment_amount'], $booking['remaining_balance'], $booking['status'],
                $booking['payment_status'], $booking['cancellation_policy_acknowledged'],
                $booking['guest_notes'], $booking['admin_notes'], $archiveNote,
                $booking['rejection_reason'], $booking['cancellation_reason'],
                $booking['cancelled_by'], $booking['payment_deadline'], $booking['confirmed_at'],
                $booking['completed_at'], $booking['created_at'], $booking['updated_at']
            ]);

            // Archive payments
            $payments = $db->prepare("SELECT * FROM payments WHERE booking_id=?");
            $payments->execute([$id]);
            $payments = $payments->fetchAll();

            if (!empty($payments)) {
                $paymentArchiveStmt = $db->prepare("
                    INSERT INTO payments_archive (
                        booking_id, payment_type, payment_method, amount, reference_number,
                        receipt_path, num_guests, checkout_date, notes, status,
                        verified_by, verified_at, submitted_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($payments as $payment) {
                    $paymentArchiveStmt->execute([
                        $payment['booking_id'], $payment['payment_type'], $payment['payment_method'],
                        $payment['amount'], $payment['reference_number'], $payment['receipt_path'],
                        $payment['num_guests'], $payment['checkout_date'], $payment['notes'],
                        $payment['status'], $payment['verified_by'], $payment['verified_at'],
                        $payment['submitted_at']
                    ]);
                }
            }

            // Delete from active tables
            $db->prepare("DELETE FROM payments WHERE booking_id=?")->execute([$id]);
            $db->prepare("DELETE FROM bookings WHERE id=?")->execute([$id]);

            flash('success', 'Booking and associated transaction records have been archived.');
            redirect("modules/{$baseModule}/bookings.php");
        }
    }

    redirect("modules/{$baseModule}/booking_detail.php?id={$id}");
}

// Reload
$stmt->execute([$id, $ownerId]);
$booking = $stmt->fetch();

$payments = $db->prepare("SELECT p.*, u.first_name as vby FROM payments p LEFT JOIN users u ON p.verified_by=u.id WHERE p.booking_id=? ORDER BY p.submitted_at DESC");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$damages = $db->prepare("SELECT bd.*, u.first_name, u.last_name FROM booking_damages bd JOIN users u ON bd.added_by=u.id WHERE bd.booking_id=? ORDER BY bd.created_at ASC");
$damages->execute([$id]);
$damages = $damages->fetchAll();
$totalDamages = array_sum(array_column($damages,'total_price'));
$finalBalance = $booking['remaining_balance'] + $totalDamages;

$edits = $db->prepare("SELECT be.*, u.first_name, u.last_name FROM booking_edits be JOIN users u ON be.edited_by=u.id WHERE be.booking_id=? ORDER BY be.created_at DESC");
$edits->execute([$id]);
$edits = $edits->fetchAll();

$review = $db->prepare("SELECT br.*, u.first_name, u.last_name FROM booking_reviews br JOIN users u ON br.guest_id=u.id WHERE br.booking_id=?");
$review->execute([$id]);
$review = $review->fetch();

$totalVerifiedPaid = array_sum(array_column(array_filter($payments, fn($p)=>$p['status']==='verified'),'amount'));

$pageTitle  = 'Booking '.$booking['booking_code'];
$activePage = 'bookings';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:1400px;margin:0 auto;padding:0 24px">
  <!-- Page Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
    <div>
      <a href="<?= base_url("modules/{$baseModule}/bookings.php") ?>" class="text-muted fs-sm"><i class="fa fa-arrow-left"></i> Back to Bookings</a>
      <h1 style="font-size:22px;font-weight:800;margin-top:4px"><?= sanitize($booking['booking_code']) ?></h1>
      <p class="text-muted fs-sm"><?= sanitize($booking['unit_name']) ?> — <?= sanitize($booking['house_name']) ?></p>
    </div>
    <div class="flex-center gap-2">
      <span class="badge badge-<?= $booking['status'] ?>" style="font-size:13px;padding:6px 16px"><?= ucfirst($booking['status']) ?></span>
      <span class="badge badge-<?= $booking['payment_status'] ?>" style="font-size:13px;padding:6px 16px"><?= ucwords(str_replace('_',' ',$booking['payment_status'])) ?></span>
    </div>
  </div>

  <!-- 3-Column Grid -->
  <div style="display:grid;grid-template-columns:1fr 1fr 320px;gap:20px;align-items:start">

    <!-- COL 1: Booking Info -->
    <div>
      <!-- Booking Details -->
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-calendar-check" style="color:var(--accent)"></i> Booking Summary</div>
        <div class="card-body" style="padding:0">

          <!-- Property & Dates -->
          <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
            <div style="font-weight:700;font-size:14px"><?= sanitize($booking['unit_name']) ?></div>
            <div style="color:var(--text-muted);font-size:12px;margin-top:2px"><?= sanitize($booking['house_name']) ?></div>
          </div>

          <table style="width:100%;font-size:13px">
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:8px 16px;color:var(--text-muted)">Check-in</td>
              <td style="padding:8px 16px;text-align:right"><?= formatDate($booking['check_in']) ?></td>
            </tr>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:8px 16px;color:var(--text-muted)">Check-out</td>
              <td style="padding:8px 16px;text-align:right"><?= formatDate($booking['check_out']) ?></td>
            </tr>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:8px 16px;color:var(--text-muted)">Nights</td>
              <td style="padding:8px 16px;text-align:right"><?= $booking['total_nights'] ?> night<?= $booking['total_nights'] > 1 ? 's' : '' ?></td>
            </tr>
            <tr>
              <td style="padding:8px 16px;color:var(--text-muted)">Guests</td>
              <td style="padding:8px 16px;text-align:right"><?= $booking['num_guests'] ?> guest<?= $booking['num_guests'] > 1 ? 's' : '' ?></td>
            </tr>
          </table>

          <!-- Financial Breakdown -->
          <div style="background:var(--bg);border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:10px 16px">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">Charges</div>
          </div>
          <table style="width:100%;font-size:13px">
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:8px 16px;color:var(--text-muted)"><?= $booking['total_nights'] ?> × <?= formatMoney($booking['price_per_night']) ?></td>
              <td style="padding:8px 16px;text-align:right"><?= formatMoney($booking['total_nights'] * $booking['price_per_night']) ?></td>
            </tr>
            <?php if ($booking['extra_guest_charge'] > 0): ?>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:8px 16px;color:var(--text-muted)">Extra guest charge</td>
              <td style="padding:8px 16px;text-align:right;color:var(--warning)"><?= formatMoney($booking['extra_guest_charge']) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:8px 16px;font-weight:600">Total</td>
              <td style="padding:8px 16px;text-align:right;font-weight:600"><?= formatMoney($booking['total_amount']) ?></td>
            </tr>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:8px 16px;color:var(--success)">Downpayment paid</td>
              <td style="padding:8px 16px;text-align:right;color:var(--success)">− <?= formatMoney($booking['downpayment_amount']) ?></td>
            </tr>
            <?php if ($totalDamages > 0): ?>
            <tr style="border-bottom:1px solid var(--border);background:#fff8f8">
              <td style="padding:8px 16px;color:var(--danger)"><i class="fa fa-exclamation-triangle fa-xs"></i> Damage charges</td>
              <td style="padding:8px 16px;text-align:right;color:var(--danger)"><?= formatMoney($totalDamages) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($booking['additional_downpayment_required'] > 0): ?>
            <tr style="border-bottom:1px solid var(--border);background:#fff3cd">
              <td style="padding:8px 16px;color:#856404">Additional downpayment</td>
              <td style="padding:8px 16px;text-align:right;color:#856404;font-weight:600"><?= formatMoney($booking['additional_downpayment_required']) ?></td>
            </tr>
            <?php endif; ?>
          </table>

          <!-- Final Balance — prominent -->
          <div style="padding:14px 16px;background:var(--bg);border-top:2px solid var(--border);display:flex;justify-content:space-between;align-items:center">
            <div>
              <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px">Final balance due</div>
              <div style="font-size:20px;font-weight:700;color:var(--danger)"><?= formatMoney($finalBalance) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px">Verified paid</div>
              <div style="font-size:16px;font-weight:600;color:var(--success)"><?= formatMoney($totalVerifiedPaid) ?></div>
            </div>
          </div>

          <!-- Deadline -->
          <?php if ($booking['payment_deadline']): ?>
          <div style="padding:10px 16px;background:#fff3cd;border-top:1px solid #ffc107;display:flex;align-items:center;gap:8px;font-size:12px;color:#856404">
            <i class="fa fa-clock"></i>
            <span>Pay by <strong><?= date('M j, Y h:i A', strtotime($booking['payment_deadline'])) ?></strong></span>
          </div>
          <?php endif; ?>

          <!-- Guest notes -->
          <?php if ($booking['guest_notes']): ?>
          <div style="padding:10px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)">
            <div style="font-weight:600;margin-bottom:4px">Notes</div>
            <?= sanitize($booking['guest_notes']) ?>
          </div>
          <?php endif; ?>
          </table>
        </div>
      </div>

      <!-- Edit History -->
      <?php if ($edits): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-history" style="color:var(--accent)"></i> Edit History</div>
        <?php foreach ($edits as $e): ?>
        <div class="card-body" style="border-bottom:1px solid var(--border);font-size:12px">
          <div class="flex-between mb-1">
            <span class="fw-bold"><?= sanitize($e['first_name'].' '.$e['last_name']) ?></span>
            <span class="text-muted"><?= date('M j, Y H:i', strtotime($e['created_at'])) ?></span>
          </div>
          <div style="color:var(--text-muted);line-height:1.8">
            <div><span style="color:#e74c3c">Before:</span> <?= formatDate($e['old_check_in']) ?> → <?= formatDate($e['old_check_out']) ?> · <?= $e['old_num_guests'] ?> guests · <?= formatMoney($e['old_total']) ?></div>
            <div><span style="color:#27ae60">After:</span> <?= formatDate($e['new_check_in']) ?> → <?= formatDate($e['new_check_out']) ?> · <?= $e['new_num_guests'] ?> guests · <?= formatMoney($e['new_total']) ?></div>
            <?php if ($e['additional_downpayment'] > 0): ?>
            <div style="color:var(--warning)"><i class="fa fa-exclamation-triangle"></i> +<?= formatMoney($e['additional_downpayment']) ?> addl. downpayment</div>
            <?php endif; ?>
            <?php if ($e['reason']): ?><div style="font-style:italic">Reason: <?= sanitize($e['reason']) ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>

    <!-- COL 2: Guest Info, Payments, Edit History -->
    <div>
      <!-- Guest Info -->
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-user" style="color:var(--accent)"></i> Guest Information</div>
        <div class="card-body">
          <div class="fw-bold" style="font-size:15px;margin-bottom:6px"><?= sanitize($booking['first_name'].' '.$booking['last_name']) ?></div>
          <div class="fs-sm text-muted mb-1"><i class="fa fa-envelope"></i> <?= sanitize($booking['email']) ?></div>
          <div class="fs-sm text-muted"><i class="fa fa-phone"></i> <?= sanitize($booking['phone'] ?? '—') ?></div>
        </div>
      </div>

      <!-- Payments -->
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-money-bill" style="color:var(--accent)"></i> Payment Submissions</div>
        <?php if (!$payments): ?>
          <div class="card-body text-muted fs-sm">No payments submitted yet.</div>
        <?php else: ?>
          <?php foreach ($payments as $p): ?>
          <div class="card-body" style="border-bottom:1px solid var(--border)">
            <div class="flex-between mb-1">
              <span class="fw-bold"><?= formatMoney($p['amount']) ?></span>
              <span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
            </div>
            <div class="fs-sm text-muted">
              <?= ucwords(str_replace('_',' ',$p['payment_method'])) ?> · <?= ucfirst($p['payment_type']) ?> · Ref#: <?= sanitize($p['reference_number'] ?? '—') ?>
            </div>
            <div class="fs-sm text-muted"><?= date('M j, Y H:i', strtotime($p['submitted_at'])) ?></div>
            <?php if ($p['notes']): ?><div class="fs-sm mt-1"><?= sanitize($p['notes']) ?></div><?php endif; ?>
            <?php if ($p['receipt_path']): ?>
              <a href="<?= base_url('uploads/'.$p['receipt_path']) ?>" target="_blank" class="btn btn-outline btn-sm mt-1"><i class="fa fa-image"></i> Receipt</a>
            <?php endif; ?>
            <?php if ($p['status']==='pending'): ?>
            <div class="btn-group mt-2">
              <form method="POST" style="display:inline" data-confirm="Are you sure all information is correct?">
                <input type="hidden" name="action" value="verify_payment">
                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check"></i> Verify</button>
              </form>
              <form method="POST" style="display:inline" data-confirm="Reject this payment?">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fa fa-times"></i> Reject</button>
              </form>
            </div>
            <?php elseif ($p['status']==='verified' && $p['vby']): ?>
              <div class="fs-sm text-muted mt-1"><i class="fa fa-check-circle" style="color:var(--success)"></i> Verified by <?= sanitize($p['vby']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Refund Tracking -->
      <?php if ($booking['status']==='cancelled' && $booking['cancelled_by_role'] && $booking['refund_status']!=='none'): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-rotate-left" style="color:var(--accent)"></i> Refund Status</div>
        <div class="card-body">
          <div class="flex-between mb-2">
            <span class="fw-bold">Status</span>
            <span class="badge <?= $booking['refund_status']==='refunded'?'badge-accepted':'badge-pending' ?>"><?= ucfirst($booking['refund_status']) ?></span>
          </div>
          <?php if ($booking['refund_details']): ?>
            <div class="fs-sm mb-2"><strong>Guest's Refund Details:</strong><br><?= sanitize($booking['refund_details']) ?></div>
            <?php if ($booking['refund_status']==='requested'): ?>
            <form method="POST" data-confirm="Mark as refunded?">
              <input type="hidden" name="action" value="mark_refunded">
              <button type="submit" class="btn btn-success btn-block"><i class="fa fa-check"></i> Mark as Refunded</button>
            </form>
            <?php endif; ?>
          <?php else: ?>
            <p class="fs-sm text-muted">Waiting for guest to submit refund details.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- COL 3: Actions -->
    <div>
      <!-- Status Actions -->
      <?php if ($booking['status']==='pending'): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-tasks" style="color:var(--accent)"></i> Actions</div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <form method="POST">
            <input type="hidden" name="action" value="accept">
            <button type="submit" class="btn btn-success btn-block"><i class="fa fa-check"></i> Accept Booking</button>
          </form>
          <button class="btn btn-danger btn-block" onclick="openModal('rejectModal')"><i class="fa fa-times"></i> Reject Booking</button>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($booking['status']==='accepted'): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-tasks" style="color:var(--accent)"></i> Actions</div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <a href="<?= base_url("modules/{$baseModule}/checkout.php?id={$id}") ?>" class="btn btn-primary btn-block">
          <i class="fa fa-flag-checkered"></i> Proceed to Checkout
        </a>

          <button class="btn btn-warning btn-block" onclick="openModal('editModal')"><i class="fa fa-edit"></i> Edit Booking</button>
          <button class="btn btn-danger btn-block" onclick="openModal('cancelModal')"><i class="fa fa-ban"></i> Cancel Booking</button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Guest Review -->
      <?php if ($review): ?>
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="fa fa-star" style="color:var(--warning)"></i> Guest Review</span>
          <form method="POST" style="margin:0" data-confirm="Delete this review? This action cannot be undone.">
            <input type="hidden" name="action" value="delete_review">
            <button type="submit" class="btn btn-sm btn-outline-danger" style="border:none;padding:2px 8px;">
              <i class="fa fa-trash"></i>
            </button>
          </form>
        </div>
        <div class="card-body">
          <div class="flex-between mb-2">
            <span class="fw-bold"><?= sanitize($review['first_name'].' '.$review['last_name']) ?></span>
            <span class="text-muted fs-sm"><?= formatDate($review['created_at']) ?></span>
          </div>
          <div style="margin-bottom:8px">
            <?php for ($s=1;$s<=5;$s++): ?>
              <i class="fa fa-star" style="color:<?= $s<=$review['rating']?'var(--warning)':'var(--border)' ?>"></i>
            <?php endfor; ?>
            <span class="fs-sm text-muted" style="margin-left:4px"><?= $review['rating'] ?>/5</span>
          </div>
          <p style="font-size:13px;line-height:1.7"><?= sanitize($review['comment']) ?></p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Archive Booking -->
      <?php if (in_array($booking['status'], ['completed', 'cancelled'])): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="fa fa-archive" style="color:var(--warning)"></i> Archive Transaction Records</div>
        <div class="card-body">
          <p class="text-muted fs-sm mb-3">Archiving will permanently move this booking and all associated payment records to the archive. Archived records are automatically deleted after 3 years.</p>
          <form method="POST" data-confirm="Archive this booking? This will permanently move all transaction records to the archive and they will be automatically deleted after 3 years.">
            <input type="hidden" name="action" value="archive_booking">
            <div class="form-group">
              <label class="required">Archive Note <span class="text-muted">(required)</span></label>
              <textarea name="archive_note" rows="3" placeholder="Reason for archiving this booking..." required></textarea>
            </div>
            <button type="submit" class="btn btn-warning btn-block"><i class="fa fa-archive"></i> Archive Booking</button>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <!-- Notify Guest -->
      <!-- <div class="card mb-3">
        <div class="card-header"><i class="fa fa-paper-plane" style="color:var(--accent)"></i> Message Guest</div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="notify_guest">
            <div class="form-group">
              <textarea name="notify_message" rows="3" placeholder="Type a message to the guest..."></textarea>
            </div>
            <button type="submit" class="btn btn-secondary btn-block"><i class="fa fa-paper-plane"></i> Send Message</button>
          </form>
        </div>
      </div> -->
    </div>
  </div>
</div>

<!-- ── MODALS ── -->

<!-- Reject Modal -->
<div id="rejectModal" class="modal-overlay" onclick="if(event.target===this)closeModal('rejectModal')">
  <div class="modal-box">
    <div class="modal-header">
      <span><i class="fa fa-times-circle" style="color:var(--danger)"></i> Reject Booking</span>
      <button onclick="closeModal('rejectModal')" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="reject">
        <div class="form-group">
          <label>Rejection Reason <span class="text-muted fs-sm">(optional)</span></label>
          <textarea name="reason" rows="3" placeholder="e.g. Dates unavailable..."></textarea>
        </div>
        <div class="btn-group">
          <button type="submit" class="btn btn-danger">Reject Booking</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal-box">
    <div class="modal-header">
      <span><i class="fa fa-edit" style="color:var(--warning)"></i> Edit Booking Details</span>
      <button onclick="closeModal('editModal')" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <p class="fs-sm text-muted mb-2">Update based on guest's request. Dates will be checked for conflicts.</p>
      <form method="POST">
        <input type="hidden" name="action" value="edit_booking">
        <div class="form-row">
          <div class="form-group">
            <label class="required">New Check-in</label>
            <input type="date" name="new_check_in" value="<?= $booking['check_in'] ?>" required>
          </div>
          <div class="form-group">
            <label class="required">New Check-out</label>
            <input type="date" name="new_check_out" value="<?= $booking['check_out'] ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label class="required">Number of Guests</label>
          <input type="number" name="new_num_guests" min="1" value="<?= $booking['num_guests'] ?>" required>
          <p class="form-hint">Max without extra charge: <?= $booking['unit_max_guests'] ?>. Extra guests charged ₱<?= number_format(EXTRA_GUEST_RATE) ?>/head.</p>
        </div>
        <div class="form-group">
          <label class="required">Reason for Edit</label>
          <textarea name="edit_reason" rows="2" placeholder="e.g. Guest requested date change via Messenger..." required></textarea>
        </div>
        <div class="btn-group">
          <button type="submit" class="btn btn-warning"><i class="fa fa-save"></i> Apply Changes</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="modal-overlay" onclick="if(event.target===this)closeModal('cancelModal')">
  <div class="modal-box">
    <div class="modal-header">
      <span><i class="fa fa-ban" style="color:var(--danger)"></i> Cancel Booking</span>
      <button onclick="closeModal('cancelModal')" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="policy-box mb-3">
        <strong>Important:</strong> Since you are cancelling this booking, the guest's downpayment of <strong><?= formatMoney($booking['downpayment_amount']) ?></strong> is <strong>refundable</strong>. The guest will be prompted to submit their refund details.
      </div>
      <form method="POST" data-confirm="Cancel this booking? The guest's downpayment will be refundable.">
        <input type="hidden" name="action" value="owner_cancel">
        <div class="form-group">
          <label class="required">Reason for Cancellation</label>
          <textarea name="cancel_reason" rows="3" placeholder="e.g. Unit under renovation, emergency maintenance..." required></textarea>
        </div>
        <div class="btn-group">
          <button type="submit" class="btn btn-danger"><i class="fa fa-ban"></i> Confirm Cancel</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('cancelModal')">Go Back</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.modal-overlay {
  display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;
  align-items:center;justify-content:center;padding:20px;
}
.modal-overlay.open { display:flex; }
.modal-box {
  background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.2);
  width:100%;max-width:500px;overflow:hidden;
}
.modal-header {
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 20px;border-bottom:1px solid var(--border);font-weight:700;font-size:16px;
}
.modal-close {
  background:none;border:none;cursor:pointer;font-size:22px;color:var(--text-muted);line-height:1;
}
.modal-close:hover { color:var(--danger); }
.modal-body { padding:20px; }
</style>

<script>
function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => {
      m.classList.remove('open');
      document.body.style.overflow = '';
    });
  }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>