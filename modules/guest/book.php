<?php
// modules/guest/book.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireRole('guest');

$db   = getDB();
$user = currentUser();

$unitId   = intval($_GET['unit_id'] ?? 0);
$preCI    = $_GET['checkin']  ?? '';
$preCO    = $_GET['checkout'] ?? '';
$preGuests= intval($_GET['guests'] ?? 1);

$stmt = $db->prepare("
    SELECT tu.*, th.name as house_name, th.owner_id,
           th.id as house_id, th.address, th.city
    FROM transient_units tu
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE tu.id=? AND tu.is_active=1 AND th.is_active=1
");
$stmt->execute([$unitId]);
$unit = $stmt->fetch();
if (!$unit) { flash('error','Unit not found.'); redirect('modules/public/home.php'); }

// Get blocked dates
$blockedDates = $db->prepare("SELECT date FROM unit_calendar WHERE unit_id=? AND date >= CURDATE() AND status IN ('Booked','maintenance','blocked')");
$blockedDates->execute([$unitId]);
$blocked = array_column($blockedDates->fetchAll(), 'date');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkin   = $_POST['check_in']   ?? '';
    $checkout  = $_POST['check_out']  ?? '';
    $numGuests = intval($_POST['num_guests'] ?? 0);
    $notes     = trim($_POST['guest_notes'] ?? '');
    $ackPolicy = isset($_POST['ack_policy']);

    if (!$checkin || !$checkout) $errors[] = 'Check-in and check-out dates are required.';
    elseif ($checkout <= $checkin) $errors[] = 'Check-out must be after check-in.';
    elseif ($numGuests < 1) $errors[] = "Number of guests must be at least 1.";
    elseif (!$ackPolicy) $errors[] = 'You must acknowledge the cancellation policy.';
    else {
        // Check for date conflicts
        $ci  = new DateTime($checkin);
        $co  = new DateTime($checkout);
        $interval = new DateInterval('P1D');
        $period   = new DatePeriod($ci, $interval, $co);
        foreach ($period as $dt) {
            if (in_array($dt->format('Y-m-d'), $blocked)) {
                $errors[] = 'One or more selected dates are unavailable. Please choose different dates.';
                break;
            }
        }

        // Check for conflicting pending/accepted booking
        if (!$errors) {
            $conflict = $db->prepare("
                SELECT id FROM bookings
                WHERE unit_id=? AND status IN ('pending','accepted')
                AND check_in < ? AND check_out > ?
            ");
            $conflict->execute([$unitId, $checkout, $checkin]);
            if ($conflict->fetch()) {
                $errors[] = 'These dates conflict with an existing booking. Please choose different dates.';
            }
        }
    }

    if (!$errors) {
        $nights      = nightsBetween($checkin, $checkout);
        $extraHeads  = max(0, $numGuests - $unit['max_guests']);
        $extraCharge = $extraHeads * EXTRA_GUEST_RATE;
        $baseTotal   = $nights * $unit['price_per_night'];
        $total       = $baseTotal + $extraCharge;
        $down        = round($total * 0.5, 2);
        $balance     = round($total * 0.5, 2);
        $code        = generateBookingCode();

        $db->prepare("INSERT INTO bookings
            (booking_code,unit_id,guest_id,check_in,check_out,num_guests,extra_guest_charge,total_nights,
             price_per_night,total_amount,downpayment_amount,remaining_balance,
             guest_notes,cancellation_policy_acknowledged)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
           ->execute([$code,$unitId,$user['id'],$checkin,$checkout,$numGuests,$extraCharge,$nights,
                      $unit['price_per_night'],$total,$down,$balance,$notes]);

        $bookingId = $db->lastInsertId();

        // Notify owner + admins - ensure notification is created
        $ownerId = $unit['owner_id'];
        
        // Create notification for owner
        $db->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)")
           ->execute([$ownerId, 'new_booking', 'New Booking Request', 
               "{$user['first_name']} {$user['last_name']} requested booking {$code} for {$unit['name']}.",
               base_url("modules/owner/booking_detail.php?id={$bookingId}")]);
        
        // Notify admins under this owner
        $admins = $db->prepare("SELECT admin_id FROM owner_admins WHERE owner_id = ?");
        $admins->execute([$ownerId]);
        foreach ($admins->fetchAll() as $admin) {
            $db->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)")
               ->execute([$admin['admin_id'], 'new_booking', 'New Booking Request', 
                   "{$user['first_name']} {$user['last_name']} requested booking {$code} for {$unit['name']}.",
                   base_url("modules/owner/booking_detail.php?id={$bookingId}")]);
        }

        // Also notify via email
        require_once __DIR__ . '/../../includes/mailer.php';
        $ownerUser = $db->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
        $ownerUser->execute([$ownerId]);
        $ownerInfo = $ownerUser->fetch();
        if ($ownerInfo) {
            sendMail($ownerInfo['email'], "New Booking Request - {$code}", "
                <p>Hello {$ownerInfo['first_name']},</p>
                <p>You have a new booking request:</p>
                <ul>
                    <li><strong>Code:</strong> {$code}</li>
                    <li><strong>Guest:</strong> {$user['first_name']} {$user['last_name']}</li>
                    <li><strong>Unit:</strong> {$unit['name']}</li>
                    <li><strong>Check-in:</strong> {$checkin}</li>
                    <li><strong>Check-out:</strong> {$checkout}</li>
                    <li><strong>Guests:</strong> {$numGuests}</li>
                    <li><strong>Total:</strong> ₱" . number_format($total, 2) . "</li>
                </ul>
                <p><a href='" . base_url("modules/owner/booking_detail.php?id={$bookingId}") . "'>Click here to view and respond to this request</a></p>
            ");
        }

        flash('success', "Booking request submitted! Code: {$code}. We'll notify you once accepted.");
        redirect("modules/guest/booking_detail.php?code={$code}");
    }
}

$pageTitle  = 'Book '.$unit['name'];
$activePage = '';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container container-sm" style="margin-top:24px">
  <!-- Steps -->
  <div class="steps mb-3">
    <div class="step active"><div class="step-circle">1</div><div class="step-label">Booking Details</div></div>
    <div class="step"><div class="step-circle">2</div><div class="step-label">Payment</div></div>
    <div class="step"><div class="step-circle">3</div><div class="step-label">Confirmation</div></div>
  </div>

  <div class="row">
    <div class="col-2">
      <div class="card">
        <div class="card-header">Booking Request — <?= sanitize($unit['name']) ?></div>
        <div class="card-body">
          <?php if ($errors): ?>
            <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= sanitize($errors[0]) ?></div>
          <?php endif; ?>

          <form method="POST" id="bookForm">
            <div class="form-row">
              <div class="form-group">
                <label class="required">Check-in Date</label>
                <input type="date" name="check_in" id="checkin"
                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                       value="<?= sanitize($preCI ?: ($_POST['check_in'] ?? '')) ?>"
                       required>
              </div>
              <div class="form-group">
                <label class="required">Check-out Date</label>
                <input type="date" name="check_out" id="checkout"
                       value="<?= sanitize($preCO ?: ($_POST['check_out'] ?? '')) ?>"
                       required>
              </div>
            </div>
            <div class="form-group">
              <label class="required">Number of Guests</label>
              <input type="number" name="num_guests" id="num_guests" min="1" max="999"
                     value="<?= intval($_POST['num_guests'] ?? $preGuests) ?>" required>
              <p class="form-hint">Up to <?= $unit['max_guests'] ?> guests included. Additional guests charged ₱<?= number_format(EXTRA_GUEST_RATE) ?>/head.</p>
            </div>
            <div class="form-group">
              <label>Special Notes / Requests</label>
              <textarea name="guest_notes" placeholder="Any special requests or notes..."><?= sanitize($_POST['guest_notes'] ?? '') ?></textarea>
            </div>

            <div class="policy-box">
              <strong><i class="fa fa-info-circle"></i> Cancellation & Payment Policy</strong>
              <ul style="margin:8px 0 0 16px;font-size:12px;line-height:1.8">
                <li>A downpayment of <strong>50% of total amount</strong> is required upon acceptance.</li>
                <li>Payment must be completed within <strong>24 hours</strong> of acceptance or your booking will be automatically cancelled.</li>
                <li>Downpayments are <strong>non-refundable</strong> upon cancellation.</li>
                <li>If your request is rejected, you may choose other available units.</li>
              </ul>
            </div>

            <div class="checkbox-group mb-3">
              <input type="checkbox" name="ack_policy" id="ackPolicy" <?= isset($_POST['ack_policy'])?'checked':'' ?>>
              <label for="ackPolicy" style="font-weight:400;cursor:pointer">I have read and agree to the cancellation and payment policy.</label>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">
              <i class="fa fa-calendar-check"></i> Submit Booking Request
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Price summary sidebar -->
    <div class="col-fixed-300">
      <div class="card" style="position:sticky;top:80px">
        <div class="card-header">Price Summary</div>
        <div class="card-body">
          <div class="fw-bold mb-2"><?= sanitize($unit['name']) ?></div>
          <div class="text-muted fs-sm mb-3"><?= sanitize($unit['house_name'].' — '.$unit['city']) ?></div>
          <div id="summaryBox">
            <div class="text-muted fs-sm text-center">Select dates to see pricing</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const pricePerNight  = <?= floatval($unit['price_per_night']) ?>;
const maxGuests      = <?= intval($unit['max_guests']) ?>;
const extraGuestRate = <?= EXTRA_GUEST_RATE ?>;
const blockedDates   = <?= json_encode($blocked) ?>;

function updateCalc() {
  const ci     = document.getElementById('checkin').value;
  const co     = document.getElementById('checkout').value;
  const guests = parseInt(document.getElementById('num_guests').value) || 1;
  const box    = document.getElementById('summaryBox');

  if (ci) {
    const next = new Date(ci); next.setDate(next.getDate()+1);
    document.getElementById('checkout').min = next.toISOString().split('T')[0];
  }

  if (!ci || !co || co <= ci) { box.innerHTML = '<div class="text-muted fs-sm text-center">Select valid dates</div>'; return; }

  const nights      = Math.round((new Date(co)-new Date(ci))/(1000*60*60*24));
  const extraHeads  = Math.max(0, guests - maxGuests);
  const extraCharge = extraHeads * extraGuestRate;
  const baseTotal   = nights * pricePerNight;
  const total       = baseTotal + extraCharge;
  const downpayment = total * 0.5;
  const balance     = total * 0.5;

  box.innerHTML = `
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>Check-in</span><span>${ci}</span></div>
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>Check-out</span><span>${co}</span></div>
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>Nights</span><span>${nights}</span></div>
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>₱${pricePerNight.toLocaleString()} × ${nights}</span><span>₱${baseTotal.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
    ${extraHeads > 0 ? `
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;color:var(--warning)"><span>Extra ${extraHeads} guest(s) × ₱${extraGuestRate}</span><span>₱${extraCharge.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>` : ''}
    <div class="flex-between fw-bold" style="padding:10px 0;border-bottom:1px solid var(--border);font-size:15px"><span>Total</span><span style="color:var(--accent)">₱${total.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
    <div class="flex-between" style="padding:6px 0;font-size:13px;color:var(--warning)"><span>Downpayment (50%)</span><span>₱${downpayment.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
    <div class="flex-between" style="padding:6px 0;font-size:13px;color:var(--text-muted)"><span>Balance at checkout</span><span>₱${balance.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
  `;
}
document.addEventListener('DOMContentLoaded', function() {
  updateCalc();
  document.getElementById('checkin').addEventListener('change', updateCalc);
  document.getElementById('checkout').addEventListener('change', updateCalc);
  document.getElementById('num_guests').addEventListener('input', updateCalc);
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
