<?php
// modules/admin/dashboard.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('admin');

$db   = getDB();
$user = currentUser();

$stmt = $db->prepare("SELECT owner_id FROM owner_admins WHERE admin_id=?");
$stmt->execute([$user['id']]);
$ownerId = $stmt->fetchColumn();

if (!$ownerId) {
    flash('error','Your admin account is not linked to any owner. Contact your owner.');
    redirect('modules/auth/logout.php');
}

$pending  = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN transient_units tu ON b.unit_id=tu.id JOIN transient_houses th ON tu.house_id=th.id WHERE th.owner_id=? AND b.status='pending'"); $pending->execute([$ownerId]); $pending=$pending->fetchColumn();
$accepted = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN transient_units tu ON b.unit_id=tu.id JOIN transient_houses th ON tu.house_id=th.id WHERE th.owner_id=? AND b.status='accepted'"); $accepted->execute([$ownerId]); $accepted=$accepted->fetchColumn();
$units    = $db->prepare("SELECT COUNT(*) FROM transient_units tu JOIN transient_houses th ON tu.house_id=th.id WHERE th.owner_id=? AND tu.is_active=1"); $units->execute([$ownerId]); $units=$units->fetchColumn();
$houses   = $db->prepare("SELECT COUNT(*) FROM transient_houses WHERE owner_id=? AND is_active=1"); $houses->execute([$ownerId]); $houses=$houses->fetchColumn();

$highPriorityBookings = $db->prepare("
    SELECT b.*, u.first_name, u.last_name, tu.name as unit_name
    FROM bookings b
    JOIN users u ON b.guest_id=u.id
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE th.owner_id=? AND b.status='pending'
      AND b.id = (
        SELECT b2.id
        FROM bookings b2
        JOIN transient_units tu2 ON b2.unit_id=tu2.id
        JOIN transient_houses th2 ON tu2.house_id=th2.id
        WHERE th2.owner_id = ? AND b2.status='pending'
          AND b2.unit_id = b.unit_id
          AND b2.check_in = b.check_in
          AND b2.check_out = b.check_out
        ORDER BY b2.created_at ASC
        LIMIT 1
      )
    ORDER BY b.created_at ASC LIMIT 8
");
$highPriorityBookings->execute([$ownerId,$ownerId]);
$highPriorityBookings = $highPriorityBookings->fetchAll();

$mediumPriorityBookings = $db->prepare("
    SELECT b.*, u.first_name, u.last_name, tu.name as unit_name
    FROM bookings b
    JOIN users u ON b.guest_id=u.id
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE th.owner_id=? AND b.status='accepted' AND b.payment_status IN ('unpaid','downpaid')
    ORDER BY FIELD(b.payment_status, 'unpaid', 'downpaid'), b.payment_deadline ASC
    LIMIT 8
");
$mediumPriorityBookings->execute([$ownerId]);
$mediumPriorityBookings = $mediumPriorityBookings->fetchAll();

$pageTitle  = 'Admin Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="page-header mt-3">
    <h1>Welcome, <?= sanitize($user['first_name']) ?>!</h1>
    <p>Staff panel — managing properties on behalf of the owner</p>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa fa-building"></i></div>
      <div><div class="stat-value"><?= $houses ?></div><div class="stat-label">Houses</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa fa-door-open"></i></div>
      <div><div class="stat-value"><?= $units ?></div><div class="stat-label">Units</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fa fa-clock"></i></div>
      <div><div class="stat-value"><?= $pending ?></div><div class="stat-label">Pending Bookings</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $accepted ?></div><div class="stat-label">Accepted Bookings</div></div>
    </div>
  </div>

  <div class="row" style="gap:24px;">
    <div class="col-3">
      <div class="row" style="gap:16px;">
        <div class="col">
          <div class="card">
            <div class="card-header">
              Booking Requests <span class="badge badge-priority-high">High Priority</span>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Code</th><th>Guest</th><th>Unit</th><th>Check-in</th><th>Requested</th></tr></thead>
                <tbody>
                  <?php foreach ($highPriorityBookings as $b): ?>
                  <tr onclick="location.href='<?= base_url('modules/admin/booking_detail.php?id='.$b['id']) ?>'" style="cursor:pointer">
                    <td><strong><?= sanitize($b['booking_code']) ?></strong></td>
                    <td><?= sanitize($b['first_name'].' '.$b['last_name']) ?></td>
                    <td><?= sanitize($b['unit_name']) ?></td>
                    <td><?= formatDate($b['check_in']) ?></td>
                    <td><?= formatDate($b['created_at']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if (!$highPriorityBookings): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:30px">No high priority requests.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col">
          <div class="card">
            <div class="card-header">
              Payment Verification <span class="badge badge-priority-medium">Medium Priority</span>
            </div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Code</th><th>Guest</th><th>Unit</th><th>Payment</th><th>Deadline</th></tr></thead>
                <tbody>
                  <?php foreach ($mediumPriorityBookings as $b): ?>
                  <tr onclick="location.href='<?= base_url('modules/admin/booking_detail.php?id='.$b['id']) ?>'" style="cursor:pointer">
                    <td><strong><?= sanitize($b['booking_code']) ?></strong></td>
                    <td><?= sanitize($b['first_name'].' '.$b['last_name']) ?></td>
                    <td><?= sanitize($b['unit_name']) ?></td>
                    <td><span class="badge badge-<?= $b['payment_status'] ?>"><?= ucwords(str_replace('_',' ',$b['payment_status'])) ?></span></td>
                    <td><?= $b['payment_deadline'] ? date('M j, Y H:i', strtotime($b['payment_deadline'])) : '—' ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if (!$mediumPriorityBookings): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:30px">No payment verification items.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-fixed-300">
      <div class="card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <a href="<?= base_url('modules/admin/bookings.php?tab=pending') ?>" class="btn btn-warning"><i class="fa fa-clock"></i> Pending (<?= $pending ?>)</a>
          <a href="<?= base_url('modules/admin/calendar.php') ?>" class="btn btn-outline"><i class="fa fa-calendar"></i> Manage Calendar</a>
          <a href="<?= base_url('modules/admin/units.php') ?>" class="btn btn-outline"><i class="fa fa-door-open"></i> Manage Units</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
