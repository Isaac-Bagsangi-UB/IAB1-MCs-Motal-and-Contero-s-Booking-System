<?php
// modules/owner/bookings.php
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

/**
 * Fetch bookings filtered by owner and optionally by a specific house.
 * When $houseId is set (admin role), only that house's bookings are returned.
 */
function getBookings($db, $ownerId, $status, $houseId = null) {
    $q = "SELECT b.*, u.first_name, u.last_name, u.email, u.phone,
                 tu.name AS unit_name, th.name AS house_name
          FROM bookings b
          JOIN users u ON b.guest_id = u.id
          JOIN transient_units tu ON b.unit_id = tu.id
          JOIN transient_houses th ON tu.house_id = th.id
          WHERE th.owner_id = ?";

    $params = [$ownerId];

    // Restrict admin to their assigned house only
    if ($houseId) {
        $q .= " AND th.id = ?";
        $params[] = $houseId;
    }

    if ($status === 'pending') {
        $q .= " AND b.status = 'pending'";
    } elseif ($status === 'accepted') {
        $q .= " AND b.status = 'accepted'";
    } elseif ($status === 'completed') {
        $q .= " AND b.status IN ('completed','cancelled')";
    }

    $q .= " ORDER BY b.created_at DESC";
    $stmt = $db->prepare($q);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$pending   = getBookings($db, $ownerId, 'pending',   $houseId);
$accepted  = getBookings($db, $ownerId, 'accepted',  $houseId);
$completed = getBookings($db, $ownerId, 'completed', $houseId);

// Show which house this admin is managing (for the page subtitle)
$assignedHouseName = null;
if ($user['role'] === 'admin' && $houseId) {
    $hStmt = $db->prepare("SELECT name FROM transient_houses WHERE id = ?");
    $hStmt->execute([$houseId]);
    $assignedHouseName = $hStmt->fetchColumn();
}

$pageTitle  = 'Bookings';
$activePage = 'bookings';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="page-header-row page-header mt-3">
    <div>
      <h1>Bookings</h1>
      <p>
        <?php if ($assignedHouseName): ?>
          Managing bookings for <strong><?= sanitize($assignedHouseName) ?></strong>
        <?php else: ?>
          Manage all booking requests and reservations
        <?php endif; ?>
      </p>
    </div>
    <a href="<?= base_url("modules/{$baseModule}/add_booking.php") ?>" class="btn btn-primary">
      <i class="fa fa-plus"></i> Add Booking
    </a>
  </div>

  <div class="tabs">
    <button class="tab-btn" data-tab="pending">
      Pending <span class="badge badge-pending" style="margin-left:6px"><?= count($pending) ?></span>
    </button>
    <button class="tab-btn" data-tab="accepted">
      Accepted <span class="badge badge-accepted" style="margin-left:6px"><?= count($accepted) ?></span>
    </button>
    <button class="tab-btn" data-tab="completed">Completed / Cancelled</button>
  </div>

  <!-- PENDING TAB -->
  <div class="tab-pane" id="pending">
    <?php if (!$pending): ?>
      <div class="empty-state"><i class="fa fa-clock"></i><h3>No pending requests</h3><p>New booking requests will appear here.</p></div>
    <?php else: ?>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th><th>Guest</th><th>Unit</th>
              <th>Check-in</th><th>Check-out</th>
              <th>Guests</th><th>Total</th><th>Requested</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending as $b): ?>
            <tr>
              <td><strong><?= sanitize($b['booking_code']) ?></strong></td>
              <td>
                <div><?= sanitize($b['first_name'] . ' ' . $b['last_name']) ?></div>
                <div class="fs-sm text-muted"><?= sanitize($b['email']) ?></div>
              </td>
              <td>
                <div><?= sanitize($b['unit_name']) ?></div>
                <div class="fs-sm text-muted"><?= sanitize($b['house_name']) ?></div>
              </td>
              <td><?= formatDate($b['check_in']) ?></td>
              <td><?= formatDate($b['check_out']) ?></td>
              <td><?= $b['num_guests'] ?></td>
              <td><?= formatMoney($b['total_amount']) ?></td>
              <td><?= formatDate($b['created_at']) ?></td>
              <td>
                <a href="<?= base_url("modules/{$baseModule}/booking_detail.php?id={$b['id']}") ?>" class="btn btn-outline btn-sm">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ACCEPTED TAB -->
  <div class="tab-pane" id="accepted">
    <?php if (!$accepted): ?>
      <div class="empty-state"><i class="fa fa-check-circle"></i><h3>No accepted bookings</h3></div>
    <?php else: ?>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th><th>Guest</th><th>Unit</th>
              <th>Check-in</th><th>Check-out</th>
              <th>Total</th><th>Payment</th><th>Deadline</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accepted as $b): ?>
            <tr>
              <td><strong><?= sanitize($b['booking_code']) ?></strong></td>
              <td><?= sanitize($b['first_name'] . ' ' . $b['last_name']) ?></td>
              <td><?= sanitize($b['unit_name']) ?></td>
              <td><?= formatDate($b['check_in']) ?></td>
              <td><?= formatDate($b['check_out']) ?></td>
              <td><?= formatMoney($b['total_amount']) ?></td>
              <td>
                <span class="badge badge-<?= $b['payment_status'] ?>">
                  <?= ucwords(str_replace('_', ' ', $b['payment_status'])) ?>
                </span>
              </td>
              <td>
                <?php if ($b['payment_deadline']): ?>
                  <?= date('M j, Y H:i', strtotime($b['payment_deadline'])) ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td>
                <a href="<?= base_url("modules/{$baseModule}/booking_detail.php?id={$b['id']}") ?>" class="btn btn-outline btn-sm">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- COMPLETED TAB -->
  <div class="tab-pane" id="completed">
    <?php if (!$completed): ?>
      <div class="empty-state"><i class="fa fa-history"></i><h3>No completed bookings yet</h3></div>
    <?php else: ?>
    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th><th>Guest</th><th>Unit</th>
              <th>Check-in</th><th>Check-out</th>
              <th>Total</th><th>Status</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($completed as $b): ?>
            <tr>
              <td><strong><?= sanitize($b['booking_code']) ?></strong></td>
              <td><?= sanitize($b['first_name'] . ' ' . $b['last_name']) ?></td>
              <td><?= sanitize($b['unit_name']) ?></td>
              <td><?= formatDate($b['check_in']) ?></td>
              <td><?= formatDate($b['check_out']) ?></td>
              <td><?= formatMoney($b['total_amount']) ?></td>
              <td><span class="badge badge-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
              <td>
                <a href="<?= base_url("modules/{$baseModule}/booking_detail.php?id={$b['id']}") ?>" class="btn btn-outline btn-sm">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>