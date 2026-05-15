<?php
// modules/guest/payment.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireRole('guest');

$db   = getDB();
$user = currentUser();
$code = $_GET['code'] ?? '';

$stmt = $db->prepare("
    SELECT b.*, tu.name as unit_name, tu.price_per_night,
           th.name as house_name, th.owner_id
    FROM bookings b
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE b.booking_code=? AND b.guest_id=?
");
$stmt->execute([$code, $user['id']]);
$booking = $stmt->fetch();

if (!$booking) { flash('error','Booking not found.'); redirect('modules/guest/dashboard.php'); }
if ($booking['status'] !== 'accepted') {
    flash('info','This booking is not yet accepted or payment already submitted.');
    redirect("modules/guest/booking_detail.php?code={$code}");
}

// Check deadline
if ($booking['payment_deadline'] && strtotime($booking['payment_deadline']) < time()) {
    flash('error','Payment deadline has passed. Booking was cancelled.');
    redirect('modules/guest/dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method   = $_POST['payment_method']   ?? '';
    $amount   = floatval($_POST['amount']  ?? 0);
    $ref      = trim($_POST['reference_number'] ?? '');
    $notes    = trim($_POST['notes']       ?? '');
    $payType  = $_POST['payment_type']     ?? 'downpayment';
    $checkoutDate = $_POST['checkout_date'] ?? $booking['check_out'];
    $numGuests    = intval($_POST['num_guests'] ?? $booking['num_guests']);

    $allowed_methods = ['cash','gcash','bank_transfer'];
    if (!in_array($method, $allowed_methods)) $errors[] = 'Invalid payment method.';
    if ($amount <= 0) $errors[] = 'Invalid amount.';
    if ($payType === 'downpayment') {
        if ($booking['additional_downpayment_required'] > 0) {
            if ($amount < $booking['additional_downpayment_required']) {
                $errors[] = 'Amount must be at least ' . formatMoney($booking['additional_downpayment_required']) . ' to fulfill the required additional downpayment.';
            }
        } else {
            if ($amount < $booking['downpayment_amount']) {
                $errors[] = 'Amount must be at least ' . formatMoney($booking['downpayment_amount']) . ' (50% downpayment required).';
            }
        }
    }
    if ($method !== 'cash' && !$ref) $errors[] = 'Reference number is required for GCash/Bank Transfer.';
    if (empty($_FILES['receipt']['name']) && $method !== 'cash') $errors[] = 'Please upload your payment receipt.';

    if (!$errors) {
        $receiptPath = null;
        if (!empty($_FILES['receipt']['name'])) {
            $up = uploadFile('receipt', 'receipts', ['image/jpeg','image/png','image/webp','application/pdf']);
            if ($up['success']) $receiptPath = $up['path'];
            else $errors[] = $up['error'];
        }

        if (!$errors) {
            $db->prepare("INSERT INTO payments (booking_id,payment_type,payment_method,amount,reference_number,receipt_path,num_guests,checkout_date,notes,status)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$booking['id'],$payType,$method,$amount,$ref,$receiptPath,$numGuests,$checkoutDate,$notes,'pending']);

            // Update payment status
            $totalVerified = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND status='verified'");
            $totalVerified->execute([$booking['id']]);
            $totalVerified = floatval($totalVerified->fetchColumn()) + $amount; // include current pending
            
            if ($booking['payment_status'] === 'unpaid') {
                $db->prepare("UPDATE bookings SET payment_status='downpaid' WHERE id=?")->execute([$booking['id']]);
            }

            notifyOwnerAndAdmins(
                $booking['owner_id'], $booking['unit_id'],
                'payment_submitted', 'Payment Submitted',
                "{$user['first_name']} submitted payment for {$code}.",
                base_url("modules/owner/booking_detail.php?id={$booking['id']}")
            );

            flash('success','Payment submitted! Please wait for admin verification.');
            redirect("modules/guest/booking_detail.php?code={$code}");
        }
    }
}

// Previous payments
$prevPayments = $db->prepare("SELECT * FROM payments WHERE booking_id=? ORDER BY submitted_at DESC");
$prevPayments->execute([$booking['id']]);
$prevPayments = $prevPayments->fetchAll();
$totalPaid = array_sum(array_column(array_filter($prevPayments, fn($p)=>$p['status']==='verified'), 'amount'));

$damages = $db->prepare("SELECT * FROM booking_damages WHERE booking_id=? ORDER BY created_at ASC");
$damages->execute([$booking['id']]);
$damages = $damages->fetchAll();
$totalDamages = array_sum(array_column($damages, 'total_price'));
$finalBalance = $booking['remaining_balance'] + $totalDamages;

if ($booking['payment_status'] === 'unpaid') {
    $paymentType = 'downpayment';
    $amountDue   = $booking['downpayment_amount'];
} elseif ($booking['additional_downpayment_required'] > 0) {
    $paymentType = 'downpayment';
    $amountDue   = $booking['additional_downpayment_required'];
} else {
    $paymentType = 'balance';
    $amountDue   = $finalBalance;
}

$pageTitle  = 'Submit Payment';
$activePage = '';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container" style="margin-top:24px; max-width:1400px;">
  <!-- Steps -->
  <div class="steps mb-3">
    <div class="step done"><div class="step-circle"><i class="fa fa-check"></i></div><div class="step-label">Booking Details</div></div>
    <?php
$stepLabel = 'Balance Payment';
if ($paymentType === 'downpayment' && $booking['additional_downpayment_required'] > 0) $stepLabel = 'Additional Downpayment';
elseif ($paymentType === 'downpayment') $stepLabel = 'Downpayment';
?>
<div class="step active"><div class="step-circle">2</div><div class="step-label"><?= $stepLabel ?></div></div>
    <div class="step"><div class="step-circle">3</div><div class="step-label">Confirmation</div></div>
  </div>

  <?php if ($booking['additional_downpayment_required'] > 0 && $paymentType === 'downpayment'): ?>
  <div class="alert alert-warning mb-3">
    <i class="fa fa-exclamation-triangle"></i>
    <strong>Additional Downpayment Required</strong> — Your booking dates/guests were updated. Please pay the additional downpayment of <strong><?= formatMoney($booking['additional_downpayment_required']) ?></strong> to confirm your new dates.
  </div>
  <?php endif; ?>

  <?php if ($paymentType === 'balance'): ?>
  <div class="alert alert-info mb-3">
    <i class="fa fa-info-circle"></i>
    <strong>Balance Payment</strong> — Your downpayment has been verified. Please settle your remaining balance below.
    <?php if ($totalDamages > 0): ?>
    <br><span style="color:var(--danger)"><i class="fa fa-exclamation-triangle"></i> Includes ₱<?= number_format($totalDamages,2) ?> in damage charges.</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($booking['payment_deadline']): ?>
  <div class="alert alert-warning">
    <i class="fa fa-clock"></i>
    <strong>Payment deadline:</strong> <?= date('F j, Y h:i A', strtotime($booking['payment_deadline'])) ?>
    — Pay before the deadline or your booking will be automatically cancelled.
  </div>
  <?php endif; ?>

  <div style="display:grid; grid-template-columns:1fr 1.6fr 1fr; gap:20px; align-items:start;">
    <!-- COLUMN 1: Payment Instructions only -->
    <div>
      <?php if ($paymentType === 'downpayment'): ?>
      <div class="card mb-3">
        <div class="card-header">Payment Instructions</div>
        <div class="card-body">
          <div class="tabs">
            <button class="tab-btn" data-tab="gcash-tab">GCash</button>
            <button class="tab-btn" data-tab="bank-tab">Bank Transfer</button>
            <button class="tab-btn" data-tab="cash-tab">Cash</button>
          </div>
          <div class="tab-pane" id="gcash-tab">
            <div style="text-align:center;padding:20px">
              <div style="width:160px;height:160px;background:var(--bg);border:2px dashed var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:12px;color:var(--text-muted)">GCash QR Code<br>(add your QR here)</div>
              <p class="fs-sm">GCash Number: <strong>09XX-XXX-XXXX</strong></p>
              <p class="fs-sm text-muted">Account Name: <strong>Owner Name</strong></p>
              <p class="fs-sm text-muted">Send at least: <strong style="color:var(--accent)"><?= formatMoney($booking['downpayment_amount']) ?></strong></p>
            </div>
          </div>
          <div class="tab-pane" id="bank-tab">
            <div style="padding:16px">
              <table style="width:100%;font-size:13px">
                <tr><td style="padding:6px 0;color:var(--text-muted)">Bank</td><td><strong>BDO / BPI / Metrobank</strong></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Account Name</td><td><strong>Owner Name Here</strong></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Account Number</td><td><strong>XXXX-XXXX-XXXX</strong></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Minimum Amount</td><td><strong style="color:var(--accent)"><?= formatMoney($booking['downpayment_amount']) ?></strong></td></tr>
              </table>
              <p class="fs-sm text-muted mt-2">Use booking code <strong><?= $code ?></strong> as reference.</p>
            </div>
          </div>
          <div class="tab-pane" id="cash-tab">
            <div style="padding:16px;font-size:13px;color:var(--text-muted)">
              <p>For cash payments, coordinate directly with the property owner/staff.</p>
              <p class="mt-2">Select <strong>Cash</strong> below and submit after paying in person.</p>
            </div>
          </div>
        </div>
      </div>
      

      <?php else: ?>
      <!-- BALANCE PAYMENT BREAKDOWN -->
      <div class="card mb-3">
        <div class="card-header" style="color:var(--success)"><i class="fa fa-receipt"></i> Payment Breakdown</div>
        <div class="card-body">
          <table style="width:100%;font-size:14px">
            <tr style="background:var(--bg)">
              <td colspan="2" style="padding:8px 10px;font-weight:700">Original Charges</td>
            </tr>
            <tr>
              <td style="padding:7px 10px;color:var(--text-muted)"><?= $booking['total_nights'] ?> night(s) × <?= formatMoney($booking['price_per_night']) ?></td>
              <td style="padding:7px 10px;text-align:right"><?= formatMoney($booking['total_nights'] * $booking['price_per_night']) ?></td>
            </tr>
            <?php if ($booking['extra_guest_charge'] > 0): ?>
            <tr>
              <td style="padding:7px 10px;color:var(--text-muted)">Extra Guest Charge</td>
              <td style="padding:7px 10px;text-align:right"><?= formatMoney($booking['extra_guest_charge']) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="border-top:1px solid var(--border)">
              <td style="padding:7px 10px;font-weight:700">Total Amount</td>
              <td style="padding:7px 10px;text-align:right;font-weight:700"><?= formatMoney($booking['total_amount']) ?></td>
            </tr>
            <tr>
              <td style="padding:7px 10px;color:var(--success)">Downpayment Paid (50%)</td>
              <td style="padding:7px 10px;text-align:right;color:var(--success)">− <?= formatMoney($booking['downpayment_amount']) ?></td>
            </tr>
            <tr style="border-top:1px solid var(--border)">
              <td style="padding:7px 10px;color:var(--text-muted)">Base Balance</td>
              <td style="padding:7px 10px;text-align:right"><?= formatMoney($booking['remaining_balance']) ?></td>
            </tr>
            <?php if ($damages): ?>
            <tr style="background:#fff8f8">
              <td colspan="2" style="padding:8px 10px;font-weight:700;color:var(--danger)"><i class="fa fa-exclamation-triangle"></i> Damage Charges</td>
            </tr>
            <?php foreach ($damages as $d): ?>
            <tr style="background:#fff8f8">
              <td style="padding:6px 10px;font-size:13px;color:var(--text-muted)"><?= sanitize($d['description']) ?> × <?= $d['quantity'] ?> @ <?= formatMoney($d['unit_price']) ?></td>
              <td style="padding:6px 10px;text-align:right;color:var(--danger)"><?= formatMoney($d['total_price']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="border-top:1px solid var(--border);background:#fff8f8">
              <td style="padding:7px 10px;color:var(--danger)">Total Damages</td>
              <td style="padding:7px 10px;text-align:right;color:var(--danger)"><?= formatMoney($totalDamages) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="border-top:2px solid var(--border);background:var(--bg)">
              <td style="padding:10px;font-weight:700;font-size:16px">FINAL BALANCE DUE</td>
              <td style="padding:10px;text-align:right;font-weight:700;font-size:16px;color:var(--danger)"><?= formatMoney($finalBalance) ?></td>
            </tr>
          </table>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">Payment Instructions</div>
        <div class="card-body">
          <div class="tabs">
            <button class="tab-btn" data-tab="gcash-tab2">GCash</button>
            <button class="tab-btn" data-tab="bank-tab2">Bank Transfer</button>
            <button class="tab-btn" data-tab="cash-tab2">Cash</button>
          </div>
          <div class="tab-pane" id="gcash-tab2">
            <div style="text-align:center;padding:20px">
              <div style="width:160px;height:160px;background:var(--bg);border:2px dashed var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:12px;color:var(--text-muted)">GCash QR Code<br>(add your QR here)</div>
              <p class="fs-sm">GCash Number: <strong>09XX-XXX-XXXX</strong></p>
              <p class="fs-sm text-muted">Account Name: <strong>Owner Name</strong></p>
              <p class="fs-sm text-muted">Send exactly: <strong style="color:var(--danger)"><?= formatMoney($finalBalance) ?></strong></p>
            </div>
          </div>
          <div class="tab-pane" id="bank-tab2">
            <div style="padding:16px">
              <table style="width:100%;font-size:13px">
                <tr><td style="padding:6px 0;color:var(--text-muted)">Bank</td><td><strong>BDO / BPI / Metrobank</strong></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Account Name</td><td><strong>Owner Name Here</strong></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Account Number</td><td><strong>XXXX-XXXX-XXXX</strong></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Amount</td><td><strong style="color:var(--danger)"><?= formatMoney($finalBalance) ?></strong></td></tr>
              </table>
              <p class="fs-sm text-muted mt-2">Use booking code <strong><?= $code ?></strong> as reference.</p>
            </div>
          </div>
          <div class="tab-pane" id="cash-tab2">
            <div style="padding:16px;font-size:13px;color:var(--text-muted)">
              <p>For cash payments, coordinate directly with the property owner/staff.</p>
              <p class="mt-2">Select <strong>Cash</strong> below and submit after paying in person.</p>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>


    <div>
      <!-- Payment Form -->
      <div class="card">
        <div class="card-header">Submit Payment Details</div>
        <div class="card-body">
          <?php if ($errors): ?>
            <div class="alert alert-danger mb-3">
              <i class="fa fa-exclamation-circle"></i> 
              <strong>Please correct the following:</strong>
              <ul style="margin:8px 0 0 20px">
                <?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <div class="alert alert-info fs-sm">
            <i class="fa fa-info-circle"></i>
            <strong>Note:</strong> Make sure your inputs are correct. Incorrect information may delay verification.
          </div>
          <form method="POST" enctype="multipart/form-data">
            <div class="form-row">
              <div class="form-group">
                <label class="required">Payment Method</label>
                <select name="payment_method" required class="<?= in_array('Invalid payment method.',$errors) ? 'error' : '' ?>">
                  <option value="">Select method</option>
                  <option value="gcash" <?= ($_POST['payment_method']??'')==='gcash'?'selected':'' ?>>GCash</option>
                  <option value="bank_transfer" <?= ($_POST['payment_method']??'')==='bank_transfer'?'selected':'' ?>>Bank Transfer</option>
                  <option value="cash" <?= ($_POST['payment_method']??'')==='cash'?'selected':'' ?>>Cash</option>
                </select>
              </div>
              <div class="form-group">
                <label class="required">Amount Paid (₱)</label>
                <input type="number" name="amount" step="0.01" min="0"
                       value="<?= $_POST['amount'] ?? $amountDue ?>" required
                       class="<?= in_array('Invalid amount.',$errors) ? 'error' : '' ?>">
              </div>
            </div>
            <div class="form-group">
              <label>Reference Number <span class="text-muted fs-sm">(required for GCash/Bank)</span></label>
                <input type="text" name="reference_number" value="<?= sanitize($_POST['reference_number'] ?? '') ?>" placeholder="Transaction/Reference number">
            </div>
            <input type="hidden" name="num_guests" value="<?= $booking['num_guests'] ?>">
            <input type="hidden" name="checkout_date" value="<?= $booking['check_out'] ?>">
            <div class="form-group">
              <label>Payment Receipt / Screenshot</label>
              <input type="file" name="receipt" accept="image/*,application/pdf" data-preview-for="receiptPreview">
              <img id="receiptPreview" src="" style="display:none;max-height:150px;margin-top:8px;border-radius:6px">
              <p class="form-hint">Upload screenshot of GCash/bank confirmation. Not required for cash.</p>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea name="notes" placeholder="Any additional notes..."><?= sanitize($_POST['notes'] ?? '') ?></textarea>
            </div>
            <input type="hidden" name="payment_type" value="<?= $paymentType ?>">
            <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fa fa-paper-plane"></i> Submit Payment</button>
          </form>
        </div>
      </div>
    </div>


    <div>
      <!-- Booking Summary -->
      <div class="col-3">
        <div class="card" style="position:sticky;top:80px">
          <div class="card-header">Booking Summary</div>
          <div class="card-body" style="font-size:13px">
            <div class="fw-bold mb-1"><?= sanitize($booking['unit_name']) ?></div>
            <div class="text-muted mb-3"><?= sanitize($booking['house_name']) ?></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border)"><span class="text-muted">Code</span><span class="fw-bold"><?= $code ?></span></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border)"><span class="text-muted">Check-in</span><span><?= formatDate($booking['check_in']) ?></span></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border)"><span class="text-muted">Check-out</span><span><?= formatDate($booking['check_out']) ?></span></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border)"><span class="text-muted">Nights</span><span><?= $booking['total_nights'] ?></span></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border)"><span class="text-muted">Total Amount</span><strong><?= formatMoney($booking['total_amount']) ?></strong></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border);color:var(--success)"><span>Amount Verified</span><strong><?= formatMoney($totalPaid) ?></strong></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border)"><span class="text-muted">Downpayment (50%)</span><span><?= formatMoney($booking['downpayment_amount']) ?></span></div>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border)"><span class="text-muted">Base Balance</span><span><?= formatMoney($booking['remaining_balance']) ?></span></div>
            <?php if ($totalDamages > 0): ?>
            <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border);color:var(--danger)"><span>Damage Charges</span><span><?= formatMoney($totalDamages) ?></span></div>
            <?php endif; ?>
            <div class="flex-between fw-bold" style="padding:5px 0;color:var(--danger)"><span>Final Balance Due</span><span><?= formatMoney($finalBalance) ?></span></div>
          </div>
        </div>
        <?php if ($prevPayments): ?>
        <div class="card mt-3">
          <div class="card-header">Previous Submissions</div>
          <?php foreach ($prevPayments as $p): ?>
          <div class="card-body" style="border-bottom:1px solid var(--border);font-size:12px">
            <div class="flex-between"><span><?= formatMoney($p['amount']) ?></span><span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></div>
            <div class="text-muted"><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?> — <?= date('M j H:i', strtotime($p['submitted_at'])) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
