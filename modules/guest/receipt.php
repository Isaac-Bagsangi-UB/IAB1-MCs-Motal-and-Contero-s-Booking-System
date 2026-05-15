<?php
// modules/guest/receipt.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('guest');

$db   = getDB();
$user = currentUser();
$code = $_GET['code'] ?? '';

$stmt = $db->prepare("
    SELECT b.*, r.receipt_number, r.generated_at,
           tu.name as unit_name, th.name as house_name, th.address, th.city,
           u.first_name, u.last_name, u.email, u.phone
    FROM bookings b
    JOIN receipts r ON r.booking_id=b.id
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    JOIN users u ON b.guest_id=u.id
    WHERE b.booking_code=? AND b.guest_id=? AND b.status='completed'
");
$stmt->execute([$code, $user['id']]);
$data = $stmt->fetch();
if (!$data) { flash('error','Receipt not found or booking not completed.'); redirect('modules/guest/dashboard.php'); }

$payments = $db->prepare("SELECT * FROM payments WHERE booking_id=? AND status='verified' ORDER BY submitted_at");
$payments->execute([$data['id']]);
$payments = $payments->fetchAll();
$totalPaid = array_sum(array_column($payments, 'amount'));

$damages = $db->prepare("SELECT * FROM booking_damages WHERE booking_id=? ORDER BY created_at");
$damages->execute([$data['id']]);
$damages = $damages->fetchAll();
$totalDamages = array_sum(array_column($damages, 'total_price'));

$pageTitle  = 'Receipt '.$data['receipt_number'];
$activePage = '';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container container-sm" style="margin-top:24px">
  <div class="flex-between mb-3">
    <a href="<?= base_url('modules/guest/booking_detail.php?code='.$code) ?>" class="text-muted fs-sm"><i class="fa fa-arrow-left"></i> Back</a>
    <button onclick="window.print()" class="btn btn-outline btn-sm"><i class="fa fa-print"></i> Print</button>
  </div>

  <div class="card" id="receiptBox">
    <div style="background:var(--amber-500);padding:28px 32px;text-align:center;color:#fff">
      <h1 style="font-size:24px;font-weight:700;margin-bottom:4px">MCTBS</h1>
      <p style="opacity:.8;font-size:14px">Motal and Conteros Transient Booking System</p>
    </div>

    <div class="card-body">
      <div class="receipt-box mb-3">
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px">Official Booking Receipt</div>
        <h2><?= sanitize($data['receipt_number']) ?></h2>
        <div class="badge badge-completed" style="font-size:13px;padding:6px 16px;margin-top:8px">CONFIRMED</div>
      </div>

      <div class="row mb-3">
        <div class="col">
          <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Guest Information</h4>
          <div><?= sanitize($data['first_name'].' '.$data['last_name']) ?></div>
          <div class="text-muted fs-sm"><?= sanitize($data['email']) ?></div>
          <div class="text-muted fs-sm"><?= sanitize($data['phone'] ?? '') ?></div>
        </div>
        <div class="col">
          <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Property</h4>
          <div class="fw-bold"><?= sanitize($data['unit_name']) ?></div>
          <div class="text-muted fs-sm"><?= sanitize($data['house_name']) ?></div>
          <div class="text-muted fs-sm"><?= sanitize($data['address'].', '.$data['city']) ?></div>
        </div>
      </div>

      <div class="divider"></div>

      <div class="row mb-3">
        <div class="col">
          <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Stay Details</h4>
          <table style="width:100%;font-size:13px">
            <tr><td style="padding:5px 0;color:var(--text-muted)">Booking Code</td><td><strong><?= sanitize($data['booking_code']) ?></strong></td></tr>
            <tr><td style="padding:5px 0;color:var(--text-muted)">Check-in</td><td><?= formatDate($data['check_in']) ?></td></tr>
            <tr><td style="padding:5px 0;color:var(--text-muted)">Check-out</td><td><?= formatDate($data['check_out']) ?></td></tr>
            <tr><td style="padding:5px 0;color:var(--text-muted)">Nights</td><td><?= $data['total_nights'] ?></td></tr>
            <tr><td style="padding:5px 0;color:var(--text-muted)">Guests</td><td><?= $data['num_guests'] ?></td></tr>
          </table>
        </div>
        <div class="col">
          <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Payment Summary</h4>
          <table style="width:100%;font-size:13px">
            <tr><td style="padding:5px 0;color:var(--text-muted)">Price/Night</td><td><?= formatMoney($data['price_per_night']) ?></td></tr>
            <tr><td style="padding:5px 0;color:var(--text-muted)"><?= $data['total_nights'] ?> night(s)</td><td><?= formatMoney($data['total_nights'] * $data['price_per_night']) ?></td></tr>
            <?php if ($data['extra_guest_charge'] > 0): ?>
            <tr><td style="padding:5px 0;color:var(--text-muted)">Extra Guest Charge</td><td><?= formatMoney($data['extra_guest_charge']) ?></td></tr>
            <?php endif; ?>
            <tr style="border-top:1px solid var(--border)"><td style="padding:5px 0;font-weight:700">Total Amount</td><td style="font-weight:700"><?= formatMoney($data['total_amount']) ?></td></tr>
            <tr><td style="padding:5px 0;color:var(--text-muted)">Downpayment (50%)</td><td><?= formatMoney($data['downpayment_amount']) ?></td></tr>
            <tr><td style="padding:5px 0;color:var(--text-muted)">Base Balance</td><td><?= formatMoney($data['remaining_balance']) ?></td></tr>
            <?php if ($totalDamages > 0): ?>
            <tr style="color:var(--danger)"><td style="padding:5px 0">Damage Charges</td><td><?= formatMoney($totalDamages) ?></td></tr>
            <?php endif; ?>
            <tr style="border-top:1px solid var(--border)"><td style="padding:5px 0;font-weight:700;color:var(--success)">Total Paid</td><td style="font-weight:700;color:var(--success)"><?= formatMoney($totalPaid) ?></td></tr>
          </table>
        </div>
      </div>

      <?php if ($payments): ?>
      <div class="divider"></div>
      <!-- <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Payment Transactions</h4> -->
      <table style="width:100%;font-size:13px">
        <?php if ($damages): ?>
      <h4 style="font-size:14px;font-weight:700;margin:16px 0 10px;color:var(--danger);text-transform:uppercase;letter-spacing:.5px"><i class="fa fa-exclamation-triangle"></i> Damage Charges</h4>
      <table style="width:100%;font-size:13px;margin-bottom:16px">
        <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($damages as $d): ?>
          <tr>
            <td style="padding:6px 0"><?= sanitize($d['description']) ?></td>
            <td><?= $d['quantity'] ?></td>
            <td><?= formatMoney($d['unit_price']) ?></td>
            <td style="color:var(--danger)"><?= formatMoney($d['total_price']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr style="border-top:1px solid var(--border);font-weight:700;color:var(--danger)">
            <td colspan="3" style="padding:6px 0">Total Damage Charges</td>
            <td><?= formatMoney($totalDamages) ?></td>
          </tr>
        </tbody>
      </table>
      <?php endif; ?>
      <h4 style="font-size:14px;font-weight:700;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Payment Transactions</h4>
      <table style="width:100%;font-size:13px">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td style="padding:6px 0"><?= date('M j, Y', strtotime($p['submitted_at'])) ?></td>
            <td><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></td>
            <td><?= sanitize($p['reference_number'] ?? '—') ?></td>
            <td><?= formatMoney($p['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <div class="divider"></div>
      <div style="text-align:center;color:var(--text-muted);font-size:12px;padding-top:8px">
        <p>Receipt generated: <?= date('F j, Y H:i A', strtotime($data['generated_at'])) ?></p>
        <p style="margin-top:4px">Thank you for choosing MCTBS. We look forward to your stay!</p>
      </div>
    </div>
  </div>
</div>

<style>
@media print {
  .topnav, .site-footer, button, a.btn { display: none !important; }
  .page-wrapper { padding-top: 0 !important; }
  .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
