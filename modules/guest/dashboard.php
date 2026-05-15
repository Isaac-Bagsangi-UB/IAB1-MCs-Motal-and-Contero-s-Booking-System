<?php
// modules/guest/dashboard.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('guest');

$db   = getDB();
$user = currentUser();

$bookings = $db->prepare("
    SELECT b.*, tu.name as unit_name, th.name as house_name, th.city
    FROM bookings b
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE b.guest_id=?
    ORDER BY b.created_at DESC
");
$bookings->execute([$user['id']]);
$all = $bookings->fetchAll();

$pending   = array_filter($all, fn($b)=>$b['status']==='pending');
$accepted  = array_filter($all, fn($b)=>$b['status']==='accepted');
$completed = array_filter($all, fn($b)=>in_array($b['status'],['completed','rejected','cancelled']));

$pageTitle  = 'My Bookings';
$activePage = 'bookings';
include __DIR__ . '/../../includes/header.php';
?>

<style>
     :root {
      --amber-50:  #fffbeb;
      --amber-100: #fef3c7;
      --amber-300: #fcd34d;
      --amber-400: #fbbf24;
      --amber-500: #f59e0b;
      --amber-600: #d97706;
      --amber-700: #b45309;
      --amber-800: #92400e;
      --white:     #ffffff;
      --gray-50:   #f9fafb;
      --gray-100:  #f3f4f6;
      --gray-300:  #d1d5db;
      --gray-500:  #6b7280;
      --gray-700:  #374151;
      --gray-900:  #111827;
    }

</style>

<div class="container">
  <div class="page-header-row page-header mt-3">
    <div>
      <h1>My Bookings</h1>
      <p>Track and manage all your reservations</p>
    </div>
  </div>
  
  <div class="tabs">
    <button class="tab-btn" data-tab="tab-pending">Pending <span class="badge badge-pending" style="margin-left:4px"><?= count($pending) ?></span></button>
    <button class="tab-btn" data-tab="tab-accepted">Accepted <span class="badge badge-accepted" style="margin-left:4px"><?= count($accepted) ?></span></button>
    <button class="tab-btn" data-tab="tab-completed">History</button>
  </div>

  <?php foreach (['tab-pending'=>$pending,'tab-accepted'=>$accepted,'tab-completed'=>$completed] as $tabId=>$list): ?>
  <div class="tab-pane" id="<?= $tabId ?>">
    <?php if (!$list): ?>
      <div class="empty-state"><i class="fa fa-calendar"></i><h3>No bookings here</h3></div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach ($list as $b): ?>
      <div class="card">
        <div class="card-body">
          <div class="flex-between flex-wrap" style="gap:12px">
            <div>
              <div class="fw-bold" style="font-size:16px"><?= sanitize($b['unit_name']) ?></div>
              <div class="text-muted fs-sm"><?= sanitize($b['house_name'].' — '.$b['city']) ?></div>
              <div class="flex-center gap-2 mt-1 fs-sm">
                <span><i class="fa fa-calendar-alt"></i> <?= formatDate($b['check_in']) ?> → <?= formatDate($b['check_out']) ?></span>
                <span><i class="fa fa-moon"></i> <?= $b['total_nights'] ?> nights</span>
                <span><i class="fa fa-users"></i> <?= $b['num_guests'] ?> guests</span>
              </div>
            </div>
            <div style="text-align:right">
              <div class="fw-bold" style="font-size:18px;color:var(--accent)"><?= formatMoney($b['total_amount']) ?></div>
              <div class="mt-1">
                <span class="badge badge-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span>
                <span class="badge badge-<?= $b['payment_status'] ?>" style="margin-left:4px"><?= ucwords(str_replace('_',' ',$b['payment_status'])) ?></span>
              </div>
              <div class="fs-sm text-muted mt-1"><?= sanitize($b['booking_code']) ?></div>
            </div>
          </div>
        </div>
        <div class="card-footer flex-between">
          <span class="fs-sm text-muted">Booked <?= formatDate($b['created_at']) ?></span>
          <div class="btn-group">
            <?php if ($b['status']==='accepted' && $b['payment_status']==='unpaid'): ?>
              <a href="<?= base_url('modules/guest/payment.php?code='.$b['booking_code']) ?>" class="btn btn-warning btn-sm"><i class="fa fa-money-bill"></i> Pay Now</a>
            <?php endif; ?>
            <?php if ($b['status']==='completed'): ?>
              <a href="<?= base_url('modules/guest/receipt.php?code='.$b['booking_code']) ?>" class="btn btn-success btn-sm"><i class="fa fa-file-alt"></i> Receipt</a>
            <?php endif; ?>
            <a href="<?= base_url('modules/guest/booking_detail.php?code='.$b['booking_code']) ?>" class="btn btn-outline btn-sm">View Details</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
