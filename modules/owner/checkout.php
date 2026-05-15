<?php
// modules/owner/checkout.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/mailer.php';
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
$baseModule = $user['role']==='admin' ? 'admin' : 'owner';

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
if (!$booking) { flash('error','Booking not found.'); redirect("modules/{$baseModule}/bookings.php"); }
if ($booking['status'] !== 'accepted') { flash('info','Booking is not in accepted state.'); redirect("modules/{$baseModule}/booking_detail.php?id={$id}"); }

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'add_damage') {
        $desc  = trim($_POST['damage_desc'] ?? '');
        $qty   = intval($_POST['damage_qty'] ?? 1);
        $price = floatval($_POST['damage_price'] ?? 0);
        if ($desc && $qty > 0 && $price > 0) {
            $totalPrice = $qty * $price;
            $db->prepare("INSERT INTO booking_damages (booking_id,description,quantity,unit_price,total_price,added_by) VALUES (?,?,?,?,?,?)")
               ->execute([$id,$desc,$qty,$price,$totalPrice,$user['id']]);
            $db->prepare("UPDATE bookings SET remaining_balance = remaining_balance + ? WHERE id=?")->execute([$totalPrice,$id]);
            createNotification($booking['guest_id'],'damage_added','Damage Charge Added',
                "A damage charge has been added to your booking {$booking['booking_code']}.",
                base_url("modules/guest/booking_detail.php?code={$booking['booking_code']}"));
            flash('success','Damage charge added.');
        } else {
            flash('error','Please fill in all damage fields correctly.');
        }

    } elseif ($act === 'delete_damage') {
        $did = intval($_POST['damage_id']);
        $dmg = $db->prepare("SELECT * FROM booking_damages WHERE id=? AND booking_id=?");
        $dmg->execute([$did,$id]);
        $dmg = $dmg->fetch();
        if ($dmg) {
            $db->prepare("DELETE FROM booking_damages WHERE id=?")->execute([$did]);
            $db->prepare("UPDATE bookings SET remaining_balance = remaining_balance - ? WHERE id=?")->execute([$dmg['total_price'],$id]);
            flash('success','Damage charge removed.');
        }

    } elseif ($act === 'record_payment') {
        $method = $_POST['payment_method'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $ref    = trim($_POST['reference_number'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        if (!in_array($method,['cash','gcash','bank_transfer']) || $amount <= 0) {
            flash('error','Invalid payment details.');
        } else {
            $receiptPath = null;
            if (!empty($_FILES['receipt']['name'])) {
                $up = uploadFile('receipt','receipts',['image/jpeg','image/png','image/webp','application/pdf']);
                if ($up['success']) $receiptPath = $up['path'];
            }
            $db->prepare("INSERT INTO payments (booking_id,payment_type,payment_method,amount,reference_number,receipt_path,notes,status) VALUES (?,?,?,?,?,?,?,'verified')")
               ->execute([$id,'balance',$method,$amount,$ref,$receiptPath,$notes]);
            $totalPaid = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id=? AND status='verified'");
            $totalPaid->execute([$id]);
            $totalPaid = floatval($totalPaid->fetchColumn());
            if ($totalPaid >= $booking['total_amount']) {
                $db->prepare("UPDATE bookings SET payment_status='paid' WHERE id=?")->execute([$id]);
            }
            createNotification($booking['guest_id'],'payment_verified','Payment Recorded',
                "A payment of ".formatMoney($amount)." has been recorded for {$booking['booking_code']}.",
                base_url("modules/guest/booking_detail.php?code={$booking['booking_code']}"));
            flash('success','Payment recorded.');
        }

    } elseif ($act === 'complete') {
        $db->prepare("UPDATE bookings SET status='completed', completed_at=NOW() WHERE id=?")->execute([$id]);
        $rcptNo = 'RCP-'.strtoupper(substr(uniqid(),-6)).'-'.rand(100,999);
        $db->prepare("INSERT INTO receipts (booking_id,receipt_number) VALUES (?,?)")->execute([$id,$rcptNo]);
        createNotification($booking['guest_id'],'booking_completed','Booking Completed',
            "Your stay at {$booking['unit_name']} is complete. Thank you!",
            base_url("modules/guest/receipt.php?code={$booking['booking_code']}"));
        sendReceiptEmail($booking['email'],$booking['first_name'].' '.$booking['last_name'],$booking['booking_code']);
        flash('success','Booking completed! Receipt sent to guest.');
        redirect("modules/{$baseModule}/booking_detail.php?id={$id}");
    }

    redirect("modules/{$baseModule}/checkout.php?id={$id}");
}

// Reload
$stmt->execute([$id, $ownerId]);
$booking = $stmt->fetch();

$damages = $db->prepare("SELECT bd.*, u.first_name, u.last_name FROM booking_damages bd JOIN users u ON bd.added_by=u.id WHERE bd.booking_id=? ORDER BY bd.created_at ASC");
$damages->execute([$id]);
$damages = $damages->fetchAll();
$totalDamages = array_sum(array_column($damages,'total_price'));
$finalBalance = $booking['remaining_balance'] + $totalDamages;

$payments = $db->prepare("SELECT p.*, u.first_name as vby FROM payments p LEFT JOIN users u ON p.verified_by=u.id WHERE p.booking_id=? ORDER BY p.submitted_at DESC");
$payments->execute([$id]);
$payments = $payments->fetchAll();
$totalVerifiedPaid = array_sum(array_column(array_filter($payments, fn($p)=>$p['status']==='verified'),'amount'));
$remainingDue = max(0, $finalBalance - $totalVerifiedPaid + $booking['downpayment_amount']);

$pageTitle  = 'Checkout — '.$booking['booking_code'];
$activePage = 'bookings';
include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:1200px;margin:0 auto;padding:0 24px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
    <div>
      <a href="<?= base_url("modules/{$baseModule}/booking_detail.php?id={$id}") ?>" class="text-muted fs-sm"><i class="fa fa-arrow-left"></i> Back to Booking</a>
      <h1 style="font-size:22px;font-weight:800;margin-top:4px"><i class="fa fa-flag-checkered"></i> Checkout — <?= sanitize($booking['booking_code']) ?></h1>
      <p class="text-muted fs-sm"><?= sanitize($booking['unit_name']) ?> · <?= sanitize($booking['first_name'].' '.$booking['last_name']) ?></p>
    </div>
    <form method="POST" data-confirm="Mark this booking as complete and send receipt to guest?">
      <input type="hidden" name="action" value="complete">
      <button type="submit" class="btn btn-primary btn-lg"><i class="fa fa-flag-checkered"></i> Complete & Send Receipt</button>
    </form>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;align-items:start">

    <!-- COL 1: Breakdown -->
    <div>
      <div class="card mb-3">
        <div class="card-header" style="background:var(--primary);color:#fff"><i class="fa fa-calculator"></i> Checkout Breakdown</div>
        <div class="card-body" style="padding:0">
          <table style="width:100%;font-size:13px">
            <tr style="background:#f7f8fa"><td colspan="2" style="padding:10px 16px;font-weight:700;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px">Original Charges</td></tr>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:10px 16px;color:var(--text-muted)"><?= $booking['total_nights'] ?> night(s) × <?= formatMoney($booking['price_per_night']) ?></td>
              <td style="padding:10px 16px;text-align:right"><?= formatMoney($booking['total_nights'] * $booking['price_per_night']) ?></td>
            </tr>
            <?php if ($booking['extra_guest_charge'] > 0): ?>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:10px 16px;color:var(--text-muted)">Extra Guest Charge</td>
              <td style="padding:10px 16px;text-align:right;color:var(--warning)"><?= formatMoney($booking['extra_guest_charge']) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="border-bottom:2px solid var(--border);background:#fafafa">
              <td style="padding:10px 16px;font-weight:700">Total Amount</td>
              <td style="padding:10px 16px;text-align:right;font-weight:700"><?= formatMoney($booking['total_amount']) ?></td>
            </tr>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:10px 16px;color:var(--success)">Downpayment Paid</td>
              <td style="padding:10px 16px;text-align:right;color:var(--success)">−<?= formatMoney($booking['downpayment_amount']) ?></td>
            </tr>
            <tr style="border-bottom:1px solid var(--border)">
              <td style="padding:10px 16px;color:var(--text-muted)">Base Balance</td>
              <td style="padding:10px 16px;text-align:right"><?= formatMoney($booking['remaining_balance']) ?></td>
            </tr>
            <?php if ($damages): ?>
            <tr style="background:#fff8f8"><td colspan="2" style="padding:10px 16px;font-weight:700;color:var(--danger);font-size:11px;text-transform:uppercase;letter-spacing:.5px"><i class="fa fa-exclamation-triangle"></i> Damage Charges</td></tr>
            <?php foreach ($damages as $d): ?>
            <tr style="border-bottom:1px solid var(--border);background:#fff8f8">
              <td style="padding:8px 16px;font-size:12px;color:var(--text-muted)"><?= sanitize($d['description']) ?> × <?= $d['quantity'] ?> @ <?= formatMoney($d['unit_price']) ?></td>
              <td style="padding:8px 16px;text-align:right;color:var(--danger)"><?= formatMoney($d['total_price']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="border-bottom:2px solid var(--border);background:#fff0f0">
              <td style="padding:10px 16px;color:var(--danger);font-weight:700">Total Damages</td>
              <td style="padding:10px 16px;text-align:right;color:var(--danger);font-weight:700"><?= formatMoney($totalDamages) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="background:#fef3cd">
              <td style="padding:12px 16px;font-weight:700;font-size:15px">FINAL BALANCE DUE</td>
              <td style="padding:12px 16px;text-align:right;font-weight:800;font-size:16px;color:var(--danger)"><?= formatMoney($finalBalance) ?></td>
            </tr>
            <tr style="background:#eafaf1">
              <td style="padding:10px 16px;color:var(--success)">Total Verified Paid</td>
              <td style="padding:10px 16px;text-align:right;font-weight:700;color:var(--success)"><?= formatMoney($totalVerifiedPaid) ?></td>
            </tr>
          </table>
        </div>
      </div>

      <!-- Add Damage -->
      <div class="card">
        <div class="card-header"><i class="fa fa-plus" style="color:var(--danger)"></i> Add Damage Charge</div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="add_damage">
            <div class="form-group">
              <label class="required">Description</label>
              <input type="text" name="damage_desc" placeholder="e.g. Broken plate" required>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="required">Qty</label>
                <input type="number" name="damage_qty" min="1" value="1" required>
              </div>
              <div class="form-group">
                <label class="required">Unit Price (₱)</label>
                <input type="number" name="damage_price" min="0" step="0.01" placeholder="0.00" required>
              </div>
            </div>
            <button type="submit" class="btn btn-danger btn-block"><i class="fa fa-plus"></i> Add Charge</button>
          </form>
        </div>
        <?php if ($damages): ?>
        <div class="card-footer">
          <strong class="fs-sm">Current Damage Charges:</strong>
          <?php foreach ($damages as $d): ?>
          <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border);font-size:12px">
            <span><?= sanitize($d['description']) ?> ×<?= $d['quantity'] ?></span>
            <div class="flex-center gap-1">
              <span style="color:var(--danger)"><?= formatMoney($d['total_price']) ?></span>
              <form method="POST" data-confirm="Remove?">
                <input type="hidden" name="action" value="delete_damage">
                <input type="hidden" name="damage_id" value="<?= $d['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="padding:2px 8px"><i class="fa fa-trash"></i></button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- COL 2: Record Payment -->
    <div>
      <div class="card mb-3">
        <div class="card-header" style="background:var(--success);color:#fff"><i class="fa fa-money-bill"></i> Record Payment (Staff)</div>
        <div class="card-body">
          <div style="background:var(--bg);border-radius:8px;padding:12px;margin-bottom:16px;text-align:center">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">Amount Due</div>
            <div style="font-size:28px;font-weight:800;color:var(--danger)"><?= formatMoney($finalBalance) ?></div>
            <div style="font-size:12px;color:var(--success);margin-top:4px">Verified Paid: <?= formatMoney($totalVerifiedPaid) ?></div>
          </div>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="record_payment">
            <div class="form-group">
              <label class="required">Payment Method</label>
              <select name="payment_method" required>
                <option value="">Select</option>
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>
            <div class="form-group">
              <label class="required">Amount (₱)</label>
              <input type="number" name="amount" step="0.01" min="0" value="<?= $finalBalance ?>" required>
            </div>
            <div class="form-group">
              <label>Reference Number</label>
              <input type="text" name="reference_number" placeholder="Optional for cash">
            </div>
            <div class="form-group">
              <label>Receipt Photo</label>
              <input type="file" name="receipt" accept="image/*,application/pdf" data-preview-for="receiptPreview">
              <div id="receiptPreview" class="photo-preview-grid"></div>
            </div>
            <div class="form-group">
              <label>Notes</label>
              <textarea name="notes" rows="2" placeholder="Optional..."></textarea>
            </div>
            <button type="submit" class="btn btn-success btn-block"><i class="fa fa-check"></i> Record Payment</button>
          </form>
        </div>
      </div>
    </div>

    <!-- COL 3: Payment History -->
    <div>
      <div class="card">
        <div class="card-header"><i class="fa fa-history" style="color:var(--accent)"></i> All Payment Submissions</div>
        <?php if (!$payments): ?>
          <div class="card-body text-muted fs-sm">No payments yet.</div>
        <?php else: ?>
          <?php foreach ($payments as $p): ?>
          <div class="card-body" style="border-bottom:1px solid var(--border);font-size:13px">
            <div class="flex-between mb-1">
              <span class="fw-bold"><?= formatMoney($p['amount']) ?></span>
              <span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span>
            </div>
            <div class="text-muted fs-sm"><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?> · <?= ucfirst($p['payment_type']) ?></div>
            <div class="text-muted fs-sm">Ref#: <?= sanitize($p['reference_number'] ?? '—') ?></div>
            <div class="text-muted fs-sm"><?= date('M j, Y H:i', strtotime($p['submitted_at'])) ?></div>
            <?php if ($p['receipt_path']): ?>
              <a href="<?= base_url('uploads/'.$p['receipt_path']) ?>" target="_blank" class="btn btn-outline btn-sm mt-1"><i class="fa fa-image"></i> Receipt</a>
            <?php endif; ?>
            <?php if ($p['vby']): ?>
              <div class="fs-sm text-muted mt-1"><i class="fa fa-check-circle" style="color:var(--success)"></i> Verified by <?= sanitize($p['vby']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>