<?php
// modules/guest/booking_detail.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireRole('guest');

$db   = getDB();
$user = currentUser();
$code = $_GET['code'] ?? '';

$stmt = $db->prepare("
    SELECT b.*, tu.name as unit_name, tu.price_per_night,
           th.name as house_name, th.city, th.owner_id
    FROM bookings b
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE b.booking_code=? AND b.guest_id=?
");
$stmt->execute([$code, $user['id']]);
$booking = $stmt->fetch();
if (!$booking) { flash('error','Booking not found.'); redirect('modules/guest/dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'submit_refund') {
    $refundDetails = trim($_POST['refund_details'] ?? '');
    if (!$refundDetails) {
        flash('error', 'Please provide your refund details.');
    } else {
        $db->prepare("UPDATE bookings SET refund_details=? WHERE id=?")->execute([$refundDetails, $booking['id']]);
        // Notify owner
        $unitStmt = $db->prepare("SELECT th.owner_id FROM transient_houses th JOIN transient_units tu ON tu.house_id=th.id JOIN bookings b ON b.unit_id=tu.id WHERE b.id=?");
        $unitStmt->execute([$booking['id']]);
        $ownerId = $unitStmt->fetchColumn();
        notifyOwnerAndAdmins($ownerId, $booking['unit_id'], 'refund_requested', 'Refund Details Submitted',
            "{$user['first_name']} submitted refund details for {$booking['booking_code']}.",
            base_url("modules/owner/booking_detail.php?id={$booking['id']}"));
        flash('success', 'Refund details submitted. The owner will process your refund shortly.');
    }
    redirect("modules/guest/booking_detail.php?code={$code}");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'submit_review') {
    $rating  = intval($_POST['rating'] ?? 5);
    $comment = trim($_POST['comment'] ?? '');
    if ($rating < 1 || $rating > 5) $rating = 5;
    // Check if review already exists
    $reviewCheck = $db->prepare("SELECT id FROM booking_reviews WHERE booking_id=?");
    $reviewCheck->execute([$booking['id']]);
    $reviewExists = $reviewCheck->fetch();
    if (!$comment) {
        flash('error', 'Please write a comment for your review.');
    } elseif ($reviewExists) {
        flash('error', 'You have already submitted a review for this booking.');
    } elseif ($booking['status'] !== 'completed') {
        flash('error', 'You can only review completed bookings.');
    } else {
        $db->prepare("INSERT INTO booking_reviews (booking_id,guest_id,rating,comment) VALUES (?,?,?,?)")
           ->execute([$booking['id'],$user['id'],$rating,$comment]);
        // Notify owner and admins
        $unitStmt = $db->prepare("SELECT th.owner_id, th.id as house_id FROM transient_houses th JOIN transient_units tu ON tu.house_id=th.id JOIN bookings b ON b.unit_id=tu.id WHERE b.id=?");
        $unitStmt->execute([$booking['id']]);
        $unitRow = $unitStmt->fetch();
        if ($unitRow) {
            notifyOwnerAndAdmins(
                $unitRow['owner_id'], $booking['unit_id'],
                'new_review', 'New Guest Review',
                "{$user['first_name']} {$user['last_name']} left a " . $rating . "-star review on booking {$booking['booking_code']}.",
                base_url("modules/owner/booking_detail.php?id={$booking['id']}")
            );
        }
        flash('success', 'Thank you for your review!');
    }
    redirect("modules/guest/booking_detail.php?code={$code}");
}

// Delete review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delete_review') {
    $reviewCheck = $db->prepare("SELECT id FROM booking_reviews WHERE booking_id=? AND guest_id=?");
    $reviewCheck->execute([$booking['id'], $user['id']]);
    $reviewExists = $reviewCheck->fetch();
    if (!$reviewExists) {
        flash('error', 'Review not found or you do not have permission to delete it.');
    } else {
        $db->prepare("DELETE FROM booking_reviews WHERE booking_id=? AND guest_id=?")
           ->execute([$booking['id'], $user['id']]);
        flash('success', 'Your review has been deleted.');
    }
    redirect("modules/guest/booking_detail.php?code={$code}");
}

// Cancel booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'cancel') {
    $reason = trim($_POST['reason'] ?? '');
    $db->prepare("UPDATE bookings SET status='cancelled', cancellation_reason=?, cancelled_by=? WHERE id=?")
       ->execute([$reason, $user['id'], $booking['id']]);
    notifyOwnerAndAdmins($booking['owner_id'], $booking['unit_id'], 'booking_cancelled', 'Booking Cancelled', "{$code} was cancelled by the guest.", null);
    flash('info','Your booking has been cancelled. Note: downpayment is non-refundable.');
    redirect("modules/guest/booking_detail.php?code={$code}");
}

$payments = $db->prepare("SELECT * FROM payments WHERE booking_id=? ORDER BY submitted_at DESC");
$payments->execute([$booking['id']]);
$payments = $payments->fetchAll();
$totalPaid = array_sum(array_column(array_filter($payments, fn($p)=>$p['status']==='verified'), 'amount'));

$damages = $db->prepare("SELECT * FROM booking_damages WHERE booking_id=? ORDER BY created_at ASC");
$damages->execute([$booking['id']]);
$damages = $damages->fetchAll();
$totalDamages = array_sum(array_column($damages, 'total_price'));
$finalBalance = $booking['remaining_balance'] + $totalDamages;

$receipt = $db->prepare("SELECT * FROM receipts WHERE booking_id=?");
$receipt->execute([$booking['id']]);
$receipt = $receipt->fetch();

$edits = $db->prepare("SELECT * FROM booking_edits WHERE booking_id=? ORDER BY created_at DESC");
$edits->execute([$booking['id']]);
$edits = $edits->fetchAll();

$review = $db->prepare("SELECT * FROM booking_reviews WHERE booking_id=?");
$review->execute([$booking['id']]);
$review = $review->fetch();

$pageTitle  = 'Booking '.$code;
$activePage = '';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container" style="margin-top:24px">
  <a href="<?= base_url('modules/guest/dashboard.php') ?>" class="text-muted fs-sm"><i class="fa fa-arrow-left"></i> My Bookings</a>

  <div class="page-header-row page-header mt-2">
    <div>
      <h1><?= sanitize($code) ?></h1>
      <p><?= sanitize($booking['unit_name']) ?> — <?= sanitize($booking['house_name']) ?></p>
    </div>
    <span class="badge badge-<?= $booking['status'] ?>" style="font-size:14px;padding:6px 16px"><?= ucfirst($booking['status']) ?></span>
  </div>

  <?php if ($booking['status']==='accepted' && $booking['payment_status']==='unpaid'): ?>
  <div class="alert alert-warning">
    <i class="fa fa-clock"></i>
    <strong>Action Required!</strong> Please complete your payment before
    <strong><?= date('F j, Y h:i A', strtotime($booking['payment_deadline'])) ?></strong>.
    <a href="<?= base_url('modules/guest/payment.php?code='.$code) ?>" class="btn btn-warning btn-sm" style="margin-left:12px">Pay Now</a>
  </div>
  <?php endif; ?>

  <?php if ($booking['status']==='completed' && $receipt): ?>
  <div class="alert alert-success">
    <i class="fa fa-check-circle"></i> Your booking is confirmed!
    <a href="<?= base_url('modules/guest/receipt.php?code='.$code) ?>" class="btn btn-success btn-sm" style="margin-left:12px"><i class="fa fa-file"></i> View Receipt</a>
  </div>
  <?php endif; ?>

  <div class="row">
    <div class="col-2">
      <div class="card mb-3">
  <div class="card-header">Booking Details</div>
  <div class="card-body" style="padding:0">

    <!-- Dates & Stay Info -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border-bottom:1px solid var(--border)">
      <div style="padding:12px 16px;border-right:1px solid var(--border)">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;text-transform:uppercase;letter-spacing:.04em">Check-in</div>
        <div style="font-weight:600;font-size:14px"><?= formatDate($booking['check_in']) ?></div>
      </div>
      <div style="padding:12px 16px">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;text-transform:uppercase;letter-spacing:.04em">Check-out</div>
        <div style="font-weight:600;font-size:14px"><?= formatDate($booking['check_out']) ?></div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;border-bottom:1px solid var(--border)">
      <div style="padding:10px 16px;border-right:1px solid var(--border);text-align:center">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px">Nights</div>
        <div style="font-weight:600"><?= $booking['total_nights'] ?></div>
      </div>
      <div style="padding:10px 16px;border-right:1px solid var(--border);text-align:center">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px">Guests</div>
        <div style="font-weight:600"><?= $booking['num_guests'] ?></div>
      </div>
      <div style="padding:10px 16px;text-align:center">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:2px">Per night</div>
        <div style="font-weight:600"><?= formatMoney($booking['price_per_night']) ?></div>
      </div>
    </div>

    <!-- Financial Breakdown -->
    <table style="width:100%;font-size:13px">
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:8px 16px;color:var(--text-muted)"><?= $booking['total_nights'] ?> nights × <?= formatMoney($booking['price_per_night']) ?></td>
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
        <td style="padding:8px 16px;color:var(--success)">Downpayment (50%)</td>
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
        <td style="padding:8px 16px;color:#856404"><i class="fa fa-info-circle fa-xs"></i> Additional downpayment due</td>
        <td style="padding:8px 16px;text-align:right;font-weight:600;color:#856404"><?= formatMoney($booking['additional_downpayment_required']) ?></td>
      </tr>
      <?php endif; ?>
    </table>

    <!-- Final Balance + Verified Paid — prominent footer -->
    <div style="display:grid;grid-template-columns:1fr 1fr;border-top:2px solid var(--border)">
      <div style="padding:14px 16px;border-right:1px solid var(--border)">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px">Verified paid</div>
        <div style="font-size:18px;font-weight:700;color:var(--success)"><?= formatMoney($totalPaid) ?></div>
      </div>
      <div style="padding:14px 16px">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px">Balance due</div>
        <div style="font-size:18px;font-weight:700;color:var(--danger)"><?= formatMoney($finalBalance) ?></div>
      </div>
    </div>

    <!-- Guest Notes -->
    <?php if ($booking['guest_notes']): ?>
    <div style="padding:10px 16px;border-top:1px solid var(--border);font-size:13px">
      <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Your notes</div>
      <?= sanitize($booking['guest_notes']) ?>
    </div>
    <?php endif; ?>

    <!-- Rejection Reason -->
    <?php if ($booking['rejection_reason']): ?>
    <div style="padding:10px 16px;border-top:1px solid var(--border);background:#fff8f8;font-size:13px">
      <div style="font-size:11px;color:var(--danger);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em"><i class="fa fa-times-circle fa-xs"></i> Rejection reason</div>
      <div style="color:var(--danger)"><?= sanitize($booking['rejection_reason']) ?></div>
    </div>
    <?php endif; ?>

  </div>
</div>

      <!-- Refund Section -->
<?php if ($booking['status']==='cancelled' && $booking['cancelled_by_role'] && $booking['refund_status']!=='none'): ?>
<div class="card mb-3">
  <div class="card-header"><i class="fa fa-money-bill-transfer"></i> Downpayment Refund</div>
  <div class="card-body">
    <?php if ($booking['refund_status']==='refunded'): ?>
      <div class="alert alert-success"><i class="fa fa-check-circle"></i> Your downpayment has been refunded!</div>
    <?php elseif ($booking['refund_details']): ?>
      <div class="alert alert-info"><i class="fa fa-clock"></i> Refund details submitted. Waiting for owner to process.</div>
      <div class="fs-sm text-muted mt-1"><strong>Your submitted details:</strong><br><?= sanitize($booking['refund_details']) ?></div>
    <?php else: ?>
      <div class="policy-box mb-3">
        <strong>Your downpayment is refundable!</strong> Please submit your GCash number or bank account details below so the owner can process your refund.
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="submit_refund">
        <div class="form-group">
          <label class="required">GCash Number / Bank Account Details</label>
          <textarea name="refund_details" rows="3" placeholder="e.g. GCash: 09XX-XXX-XXXX (Juan Dela Cruz)&#10;or&#10;BDO: 1234-5678-9012 (Juan Dela Cruz)" required></textarea>
        </div>
        <button type="submit" class="btn btn-success btn-block"><i class="fa fa-paper-plane"></i> Submit Refund Details</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Edit History -->
<?php if ($edits): ?>
<div class="card mb-3">
  <div class="card-header"><i class="fa fa-history"></i> Booking Edit History</div>
  <?php foreach ($edits as $e): ?>
  <div class="card-body" style="border-bottom:1px solid var(--border);font-size:13px">
    <div class="fw-bold mb-1" style="color:var(--warning)"><i class="fa fa-edit"></i> Booking Updated</div>
    <div class="text-muted">
      <div>Previous: <?= formatDate($e['old_check_in']) ?> → <?= formatDate($e['old_check_out']) ?> (<?= $e['old_num_guests'] ?> guests) — <?= formatMoney($e['old_total']) ?></div>
      <div>Updated: <?= formatDate($e['new_check_in']) ?> → <?= formatDate($e['new_check_out']) ?> (<?= $e['new_num_guests'] ?> guests) — <?= formatMoney($e['new_total']) ?></div>
      <?php if ($e['additional_downpayment'] > 0): ?>
        <div style="color:var(--warning)"><i class="fa fa-exclamation-triangle"></i> Additional downpayment required: <?= formatMoney($e['additional_downpayment']) ?></div>
      <?php endif; ?>
      <?php if ($e['reason']): ?>
        <div>Reason: <?= sanitize($e['reason']) ?></div>
      <?php endif; ?>
      <div class="text-muted fs-sm"><?= formatDate($e['created_at']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

      <!-- Damages -->
<?php if ($damages): ?>
<div class="card mb-3">
  <div class="card-header" style="color:var(--danger)"><i class="fa fa-exclamation-triangle"></i> Damage Charges</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
      <tbody>
        <?php foreach ($damages as $d): ?>
        <tr>
          <td><?= sanitize($d['description']) ?></td>
          <td><?= $d['quantity'] ?></td>
          <td><?= formatMoney($d['unit_price']) ?></td>
          <td><?= formatMoney($d['total_price']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer flex-between">
    <span class="fw-bold">Total Damage Charges</span>
    <span class="fw-bold" style="color:var(--danger)"><?= formatMoney($totalDamages) ?></span>
  </div>
</div>
<?php endif; ?>

      <!-- Payments -->
      <?php if ($payments): ?>
      <div class="card">
        <div class="card-header">My Payment Submissions</div>
        <?php foreach ($payments as $p): ?>
        <div class="card-body" style="border-bottom:1px solid var(--border);font-size:13px">
          <div class="flex-between mb-1">
            <span class="fw-bold"><?= formatMoney($p['amount']) ?> — <?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></span>
            <span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
          </div>
          <div class="text-muted">Ref#: <?= sanitize($p['reference_number'] ?? '—') ?></div>
          <div class="text-muted"><?= date('M j, Y H:i', strtotime($p['submitted_at'])) ?></div>
          <?php if ($p['receipt_path']): ?>
            <a href="<?= base_url('uploads/'.$p['receipt_path']) ?>" target="_blank" class="btn btn-outline btn-sm mt-1"><i class="fa fa-image"></i> Receipt</a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-fixed-300">
      <div class="card">
        <div class="card-header">Actions</div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <?php if ($booking['status']==='accepted' && $booking['payment_status']!=='paid'): ?>
            <a href="<?= base_url('modules/guest/payment.php?code='.$code) ?>" class="btn btn-primary btn-block">
              <i class="fa fa-money-bill"></i> Submit Payment
            </a>
          <?php endif; ?>

          <?php if ($booking['status']==='completed' && $receipt): ?>
            <a href="<?= base_url('modules/guest/receipt.php?code='.$code) ?>" class="btn btn-success btn-block">
              <i class="fa fa-file-alt"></i> View Receipt
            </a>
          <?php endif; ?>

          <?php if ($booking['status']==='completed'): ?>
            <?php if (!$review): ?>
            <div class="card" style="border:1px solid var(--border);border-radius:var(--radius);padding:16px">
              <div class="fw-bold mb-2"><i class="fa fa-star" style="color:var(--warning)"></i> Leave a Review</div>
              <form method="POST">
                <input type="hidden" name="action" value="submit_review">
                <div class="form-group">
                  <label>Rating</label>
                  <div id="starRating" style="display:flex;gap:6px;font-size:24px;cursor:pointer;margin-bottom:8px">
                    <?php for ($s=1;$s<=5;$s++): ?>
                      <i class="fa fa-star" data-value="<?= $s ?>" style="color:var(--border)" onclick="setRating(<?= $s ?>)"></i>
                    <?php endfor; ?>
                  </div>
                  <input type="hidden" name="rating" id="ratingInput" value="5">
                </div>
                <div class="form-group">
                  <label class="required">Your Review</label>
                  <textarea name="comment" rows="3" placeholder="Share your experience..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-paper-plane"></i> Submit Review</button>
              </form>
            </div>
            <?php else: ?>
            <div class="card" style="border:1px solid var(--border);border-radius:var(--radius);padding:16px;background:var(--bg)">
              <div class="fw-bold mb-1"><i class="fa fa-star" style="color:var(--warning)"></i> Your Review</div>
              <div style="margin-bottom:6px">
                <?php for ($s=1;$s<=5;$s++): ?>
                  <i class="fa fa-star" style="color:<?= $s<=$review['rating']?'var(--warning)':'var(--border)' ?>"></i>
                <?php endfor; ?>
              </div>
              <p class="fs-sm text-muted"><?= sanitize($review['comment']) ?></p>
              <div class="fs-sm text-muted mt-1"><?= formatDate($review['created_at']) ?></div>
              <form method="POST" style="margin-top:8px" data-confirm="Delete your review? This action cannot be undone.">
                <input type="hidden" name="action" value="delete_review">
                <button type="submit" class="btn btn-sm btn-outline" style="color:var(--danger);border-color:var(--danger)">
                  <i class="fa fa-trash"></i> Delete Review
                </button>
              </form>
            </div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (in_array($booking['status'],['pending','accepted'])): ?>
            <div>
              <div class="policy-box" style="margin-bottom:10px">
                <strong>Cancellation Notice</strong>
                Downpayment is non-refundable. Are you sure you want to cancel?
              </div>
              <form method="POST" data-confirm="Cancel this booking? Downpayment is non-refundable.">
                <input type="hidden" name="action" value="cancel">
                <div class="form-group">
                  <label>Reason for Cancellation</label>
                  <textarea name="reason" rows="3" placeholder="Optional reason..."></textarea>
                </div>
                <button type="submit" class="btn btn-outline btn-block" style="color:var(--danger);border-color:var(--danger)">
                  <i class="fa fa-times"></i> Cancel Booking Request
                </button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header">Booking Status Timeline</div>
        <div class="card-body">
          <?php
          $steps = [
            'pending'   => ['Requested','fa-clock','warning'],
            'accepted'  => ['Accepted','fa-check','success'],
            'payment'   => ['Payment Submitted','fa-money-bill','info'],
            'completed' => ['Completed','fa-flag-checkered','success'],
          ];
          $currentStatus = $booking['status'];
          $hasPayment = !empty($payments);
          ?>
          <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
            <div class="flex-center gap-2" style="color:var(--success)"><i class="fa fa-check-circle"></i> Booking Requested</div>
            <div class="flex-center gap-2" style="color:<?= in_array($currentStatus,['accepted','completed'])?'var(--success)':'var(--text-muted)' ?>">
              <i class="fa fa-<?= in_array($currentStatus,['accepted','completed'])?'check-circle':'circle' ?>"></i> Booking Accepted
            </div>
            <div class="flex-center gap-2" style="color:<?= $hasPayment?'var(--success)':'var(--text-muted)' ?>">
              <i class="fa fa-<?= $hasPayment?'check-circle':'circle' ?>"></i> Payment Submitted
            </div>
            <div class="flex-center gap-2" style="color:<?= $currentStatus==='completed'?'var(--success)':'var(--text-muted)' ?>">
              <i class="fa fa-<?= $currentStatus==='completed'?'check-circle':'circle' ?>"></i> Confirmed & Complete
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function setRating(val) {
    document.getElementById('ratingInput').value = val;
    document.querySelectorAll('#starRating .fa-star').forEach(function(star, i) {
        star.style.color = i < val ? 'var(--warning)' : 'var(--border)';
    });
}
// Set default 5 stars on load
document.addEventListener('DOMContentLoaded', function() { setRating(5); });
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
