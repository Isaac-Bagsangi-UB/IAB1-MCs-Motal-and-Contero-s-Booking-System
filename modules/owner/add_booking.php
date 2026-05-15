<?php
// modules/owner/add_booking.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('owner', 'admin');

$db   = getDB();
$user = currentUser();

$ownerId = $user['id'];
$houseId = null; // null = see all houses (owner); set = restricted to assigned house (admin)

if ($user['role'] === 'admin') {
    $stmt = $db->prepare("SELECT owner_id, house_id FROM owner_admins WHERE admin_id = ?");
    $stmt->execute([$user['id']]);
    $link = $stmt->fetch();
    if (!$link) {
        flash('error', 'Staff account is not linked to any owner.');
        redirect('modules/admin/dashboard.php');
    }
    $ownerId = $link['owner_id'];
    $houseId = $link['house_id']; // may be null if owner didn't assign a house
}

$baseModule = $user['role'] === 'admin' ? 'admin' : 'owner';

// Get units for selection
$unitsQuery = "SELECT tu.id, tu.name, tu.price_per_night, tu.max_guests, th.name as house_name
               FROM transient_units tu
               JOIN transient_houses th ON tu.house_id = th.id
               WHERE th.owner_id = ? AND tu.is_active = 1 AND th.is_active = 1";
$params = [$ownerId];
if ($houseId) {
    $unitsQuery .= " AND th.id = ?";
    $params[] = $houseId;
}
$unitsQuery .= " ORDER BY th.name, tu.name";
$unitsStmt = $db->prepare($unitsQuery);
$unitsStmt->execute($params);
$units = $unitsStmt->fetchAll();

$errors = [];
$selectedUnit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId = intval($_POST['unit_id'] ?? 0);
    $checkin = $_POST['check_in'] ?? '';
    $checkout = $_POST['check_out'] ?? '';
    $numGuests = intval($_POST['num_guests'] ?? 0);
    $guestFirstName = trim($_POST['guest_first_name'] ?? '');
    $guestLastName = trim($_POST['guest_last_name'] ?? '');
    $guestEmail = trim($_POST['guest_email'] ?? '');
    $guestPhone = trim($_POST['guest_phone'] ?? '');
    $notes = trim($_POST['guest_notes'] ?? '');
    $downpaymentReceived = isset($_POST['downpayment_received']);

    // Validate unit
    $unitStmt = $db->prepare("SELECT tu.*, th.name as house_name FROM transient_units tu JOIN transient_houses th ON tu.house_id = th.id WHERE tu.id = ? AND th.owner_id = ?");
    $unitStmt->execute([$unitId, $ownerId]);
    $selectedUnit = $unitStmt->fetch();
    if (!$selectedUnit) {
        $errors[] = 'Selected unit not found.';
    }

    // Validate dates and other fields
    if (!$checkin || !$checkout) {
        $errors[] = 'Check-in and check-out dates are required.';
    } elseif ($checkout <= $checkin) {
        $errors[] = 'Check-out must be after check-in.';
    } elseif ($numGuests < 1) {
        $errors[] = 'Number of guests must be at least 1.';
    } elseif (!$guestFirstName || !$guestLastName || !$guestEmail) {
        $errors[] = 'Guest name and email are required.';
    } elseif (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid guest email address.';
    }

    if (!$errors) {
        // Check for date conflicts
        $ci = new DateTime($checkin);
        $co = new DateTime($checkout);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($ci, $interval, $co);
        $blockedDates = $db->prepare("SELECT date FROM unit_calendar WHERE unit_id=? AND date >= CURDATE() AND status IN ('Booked','maintenance','blocked')");
        $blockedDates->execute([$unitId]);
        $blocked = array_column($blockedDates->fetchAll(), 'date');
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
        // Handle guest
        $guestStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND role = 'guest'");
        $guestStmt->execute([$guestEmail]);
        $existingGuest = $guestStmt->fetch();

        if ($existingGuest) {
            $guestId = $existingGuest['id'];
        } else {
            // Create new guest
            $tempPassword = bin2hex(random_bytes(8)); // temporary password
            $db->prepare("INSERT INTO users (email, password, role, first_name, last_name, phone, email_verified_at, is_active) VALUES (?, ?, 'guest', ?, ?, ?, NOW(), 1)")
               ->execute([$guestEmail, password_hash($tempPassword, PASSWORD_DEFAULT), $guestFirstName, $guestLastName, $guestPhone]);
            $guestId = $db->lastInsertId();
        }

        // Calculate pricing
        $nights = nightsBetween($checkin, $checkout);
        $extraHeads = max(0, $numGuests - $selectedUnit['max_guests']);
        $extraCharge = $extraHeads * EXTRA_GUEST_RATE;
        $baseTotal = $nights * $selectedUnit['price_per_night'];
        $total = $baseTotal + $extraCharge;
        $down = round($total * 0.5, 2);
        $balance = round($total * 0.5, 2);
        $code = generateBookingCode();

        // Insert booking
        $status = $downpaymentReceived ? 'accepted' : 'pending';
        $paymentStatus = $downpaymentReceived ? 'downpaid' : 'unpaid';
        $confirmedAt = $downpaymentReceived ? date('Y-m-d H:i:s') : null;
        $paymentDeadline = $downpaymentReceived ? null : date('Y-m-d H:i:s', strtotime('+'.PAYMENT_DEADLINE_HOURS.' hours'));

        $db->prepare("INSERT INTO bookings
            (booking_code, unit_id, guest_id, check_in, check_out, num_guests, extra_guest_charge, total_nights,
             price_per_night, total_amount, downpayment_amount, remaining_balance,
             status, payment_status, guest_notes, confirmed_at, payment_deadline, cancellation_policy_acknowledged)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")
           ->execute([$code, $unitId, $guestId, $checkin, $checkout, $numGuests, $extraCharge, $nights,
                      $selectedUnit['price_per_night'], $total, $down, $balance,
                      $status, $paymentStatus, $notes, $confirmedAt, $paymentDeadline]);

        $bookingId = $db->lastInsertId();

        // If accepted, block dates
        if ($downpaymentReceived) {
            $ci = new DateTime($checkin);
            $co = new DateTime($checkout);
            foreach (new DatePeriod($ci, new DateInterval('P1D'), $co) as $dt) {
                $db->prepare("INSERT INTO unit_calendar (unit_id, date, status) VALUES (?, ?, 'Booked') ON DUPLICATE KEY UPDATE status='Booked'")
                   ->execute([$unitId, $dt->format('Y-m-d')]);
            }
        }

        flash('success', "Booking created successfully! Code: {$code}");
        redirect("modules/{$baseModule}/booking_detail.php?id={$bookingId}");
    }
}

$pageTitle = 'Add Booking';
$activePage = 'bookings';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container container-sm" style="margin-top:24px">
  <div class="steps mb-3">
    <div class="step active"><div class="step-circle">1</div><div class="step-label">Select Unit</div></div>
    <div class="step"><div class="step-circle">2</div><div class="step-label">Guest Details</div></div>
    <div class="step"><div class="step-circle">3</div><div class="step-label">Booking Details</div></div>
  </div>

  <div class="row">
    <div class="col-2">
      <div class="card">
        <div class="card-header">Add Booking</div>
        <div class="card-body">
          <?php if ($errors): ?>
            <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= sanitize($errors[0]) ?></div>
          <?php endif; ?>

          <form method="POST" id="addBookingForm">
            <div class="form-group">
              <label class="required">Select Unit</label>
              <select name="unit_id" id="unitSelect" required>
                <option value="">Choose a unit...</option>
                <?php foreach ($units as $unit): ?>
                  <option value="<?= $unit['id'] ?>" <?= ($selectedUnit && $selectedUnit['id'] == $unit['id']) ? 'selected' : '' ?>>
                    <?= sanitize($unit['house_name']) ?> — <?= sanitize($unit['name']) ?> (₱<?= number_format($unit['price_per_night']) ?>/night)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="required">Check-in Date</label>
                <input type="date" name="check_in" id="checkin"
                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                       value="<?= sanitize($_POST['check_in'] ?? '') ?>"
                       required>
              </div>
              <div class="form-group">
                <label class="required">Check-out Date</label>
                <input type="date" name="check_out" id="checkout"
                       value="<?= sanitize($_POST['check_out'] ?? '') ?>"
                       required>
              </div>
            </div>

            <div class="form-group">
              <label class="required">Number of Guests</label>
              <input type="number" name="num_guests" id="num_guests" min="1" max="999"
                     value="<?= intval($_POST['num_guests'] ?? 1) ?>" required>
              <p class="form-hint" id="maxGuestsHint">Select a unit to see guest limits.</p>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="required">Guest First Name</label>
                <input type="text" name="guest_first_name" value="<?= sanitize($_POST['guest_first_name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label class="required">Guest Last Name</label>
                <input type="text" name="guest_last_name" value="<?= sanitize($_POST['guest_last_name'] ?? '') ?>" required>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="required">Guest Email</label>
                <input type="email" name="guest_email" value="<?= sanitize($_POST['guest_email'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Guest Phone</label>
                <input type="text" name="guest_phone" value="<?= sanitize($_POST['guest_phone'] ?? '') ?>">
              </div>
            </div>

            <div class="form-group">
              <label>Special Notes / Requests</label>
              <textarea name="guest_notes" placeholder="Any special requests or notes..."><?= sanitize($_POST['guest_notes'] ?? '') ?></textarea>
            </div>

            <div class="checkbox-group mb-3">
              <input type="checkbox" name="downpayment_received" id="downpaymentReceived" <?= isset($_POST['downpayment_received']) ? 'checked' : '' ?>>
              <label for="downpaymentReceived" style="font-weight:400;cursor:pointer">Downpayment has been received</label>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">
              <i class="fa fa-plus"></i> Create Booking
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
          <div id="summaryBox">
            <div class="text-muted fs-sm text-center">Select unit and dates to see pricing</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let units = <?= json_encode($units) ?>;
const extraGuestRate = <?= EXTRA_GUEST_RATE ?>;

function getSelectedUnit() {
  const unitId = document.getElementById('unitSelect').value;
  return units.find(u => u.id == unitId) || null;
}

function updateCalc() {
  const unit = getSelectedUnit();
  const ci = document.getElementById('checkin').value;
  const co = document.getElementById('checkout').value;
  const guests = parseInt(document.getElementById('num_guests').value) || 1;
  const box = document.getElementById('summaryBox');
  const hint = document.getElementById('maxGuestsHint');

  if (!unit) {
    box.innerHTML = '<div class="text-muted fs-sm text-center">Select a unit</div>';
    hint.textContent = 'Select a unit to see guest limits.';
    return;
  }

  hint.textContent = `Up to ${unit.max_guests} guests included. Additional guests charged ₱${extraGuestRate}/head.`;

  if (ci) {
    const next = new Date(ci); next.setDate(next.getDate()+1);
    document.getElementById('checkout').min = next.toISOString().split('T')[0];
  }

  if (!ci || !co || co <= ci) {
    box.innerHTML = '<div class="text-muted fs-sm text-center">Select valid dates</div>';
    return;
  }

  const nights = Math.round((new Date(co) - new Date(ci)) / (1000 * 60 * 60 * 24));
  const extraHeads = Math.max(0, guests - unit.max_guests);
  const extraCharge = extraHeads * extraGuestRate;
  const baseTotal = nights * unit.price_per_night;
  const total = baseTotal + extraCharge;
  const downpayment = total * 0.5;
  const balance = total * 0.5;

  box.innerHTML = `
    <div class="fw-bold mb-2">${unit.name}</div>
    <div class="text-muted fs-sm mb-3">${unit.house_name}</div>
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>Check-in</span><span>${ci}</span></div>
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>Check-out</span><span>${co}</span></div>
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>Nights</span><span>${nights}</span></div>
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>₱${unit.price_per_night.toLocaleString()} × ${nights}</span><span>₱${baseTotal.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
    ${extraHeads > 0 ? `
    <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;color:var(--warning)"><span>Extra ${extraHeads} guest(s) × ₱${extraGuestRate}</span><span>₱${extraCharge.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>` : ''}
    <div class="flex-between fw-bold" style="padding:10px 0;border-bottom:1px solid var(--border);font-size:15px"><span>Total</span><span style="color:var(--accent)">₱${total.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
    <div class="flex-between" style="padding:6px 0;font-size:13px;color:var(--warning)"><span>Downpayment (50%)</span><span>₱${downpayment.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
    <div class="flex-between" style="padding:6px 0;font-size:13px;color:var(--text-muted)"><span>Balance at checkout</span><span>₱${balance.toLocaleString('en-PH',{minimumFractionDigits:2})}</span></div>
  `;
}

document.addEventListener('DOMContentLoaded', function() {
  updateCalc();
  document.getElementById('unitSelect').addEventListener('change', updateCalc);
  document.getElementById('checkin').addEventListener('change', updateCalc);
  document.getElementById('checkout').addEventListener('change', updateCalc);
  document.getElementById('num_guests').addEventListener('input', updateCalc);
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>