<?php
// modules/owner/admins.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole('owner');

$db   = getDB();
$user = currentUser();
$errors = [];

// Fetch owner's active houses for the dropdown
$housesStmt = $db->prepare("SELECT id, name FROM transient_houses WHERE owner_id = ? AND is_active = 1 ORDER BY name ASC");
$housesStmt->execute([$user['id']]);
$houses = $housesStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'invite') {
        $email   = trim($_POST['email'] ?? '');
        $houseId = intval($_POST['house_id'] ?? 0);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif (!$houseId) {
            $errors[] = 'Please select a transient house to assign this staff to.';
        } else {
            // Verify the house belongs to this owner
            $houseChk = $db->prepare("SELECT id FROM transient_houses WHERE id = ? AND owner_id = ?");
            $houseChk->execute([$houseId, $user['id']]);
            if (!$houseChk->fetch()) {
                $errors[] = 'Invalid house selected.';
            } else {
                $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
                $chk->execute([$email]);
                if ($chk->fetch()) {
                    $errors[] = 'This email is already registered.';
                } else {
                    $token  = generateToken();
                    $expiry = date('Y-m-d H:i:s', strtotime('+' . INVITE_EXPIRY_HOURS . ' hours'));
                    $db->prepare("
                        INSERT INTO invitations (email, token, role, invited_by, owner_id, house_id, expires_at)
                        VALUES (?, ?, 'admin', ?, ?, ?, ?)
                    ")->execute([$email, $token, $user['id'], $user['id'], $houseId, $expiry]);
                    sendInviteEmail($email, $token, 'admin');
                    flash('success', "Invitation sent to {$email}.");
                    redirect('modules/owner/admins.php');
                }
            }
        }

    } elseif ($act === 'remove') {
        $adminId = intval($_POST['admin_id']);
        $db->prepare("DELETE FROM owner_admins WHERE owner_id = ? AND admin_id = ?")->execute([$user['id'], $adminId]);
        flash('success', 'Staff removed.');
        redirect('modules/owner/admins.php');
    }
}

// Get admins for this owner (with their assigned house)
$adminsStmt = $db->prepare("
    SELECT u.*, oa.created_at AS added_at, oa.house_id, th.name AS assigned_house
    FROM owner_admins oa
    JOIN users u ON oa.admin_id = u.id
    LEFT JOIN transient_houses th ON oa.house_id = th.id
    WHERE oa.owner_id = ?
    ORDER BY oa.created_at DESC
");
$adminsStmt->execute([$user['id']]);
$admins = $adminsStmt->fetchAll();

// Pending invitations (with assigned house)
$pendingStmt = $db->prepare("
    SELECT i.*, th.name AS assigned_house
    FROM invitations i
    LEFT JOIN transient_houses th ON i.house_id = th.id
    WHERE i.owner_id = ? AND i.role = 'admin' AND i.used_at IS NULL AND i.expires_at > NOW()
    ORDER BY i.created_at DESC
");
$pendingStmt->execute([$user['id']]);
$pendingInvites = $pendingStmt->fetchAll();

$pageTitle  = 'Staff Management';
$activePage = 'admins';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="page-header-row page-header mt-3">
    <div>
      <h1>Staff Management</h1>
      <p>Invite and manage your property staff</p>
    </div>
    <?php if ($houses): ?>
      <button class="btn btn-primary" onclick="document.getElementById('inviteModal').style.display='flex'">
        <i class="fa fa-plus"></i> Invite Staff
      </button>
    <?php else: ?>
      <span class="text-muted fs-sm" style="align-self:center">
        <i class="fa fa-info-circle"></i> Add a transient house first before inviting staff.
      </span>
    <?php endif; ?>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= sanitize($errors[0]) ?></div>
  <?php endif; ?>

  <!-- Staff Cards -->
  <?php if ($admins): ?>
  <div class="unit-grid mb-3">
    <?php foreach ($admins as $a): ?>
    <div class="card">
      <div class="card-body" style="display:flex;align-items:center;gap:16px">
        <?php if ($a['profile_photo']): ?>
          <img src="<?= base_url('uploads/' . $a['profile_photo']) ?>" class="avatar-sm" style="width:52px;height:52px;border-radius:50%">
        <?php else: ?>
          <div class="avatar-initials" style="width:52px;height:52px;font-size:18px">
            <?= strtoupper(substr($a['first_name'], 0, 1) . substr($a['last_name'], 0, 1)) ?>
          </div>
        <?php endif; ?>
        <div style="flex:1">
          <div class="fw-bold"><?= sanitize($a['first_name'] . ' ' . $a['last_name']) ?></div>
          <div class="fs-sm text-muted"><?= sanitize($a['email']) ?></div>
          <div class="fs-sm text-muted"><?= sanitize($a['phone'] ?? '') ?></div>
          <!-- Assigned house -->
          <div class="fs-sm mt-1" style="display:flex;align-items:center;gap:5px">
            <i class="fa fa-house" style="color:var(--primary)"></i>
            <?php if ($a['assigned_house']): ?>
              <span><?= sanitize($a['assigned_house']) ?></span>
            <?php else: ?>
              <span class="text-muted">No house assigned</span>
            <?php endif; ?>
          </div>
          <div class="fs-sm text-muted mt-1">Added: <?= formatDate($a['added_at']) ?></div>
        </div>
        <div>
          <?php if ($a['is_active'] && !$a['is_deactivated']): ?>
            <span class="badge badge-accepted">Active</span>
          <?php else: ?>
            <span class="badge badge-cancelled">Inactive</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-footer">
        <form method="POST" data-confirm="Remove this staff from your team?">
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm"><i class="fa fa-user-minus"></i> Remove Staff</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="empty-state mb-3">
      <i class="fa fa-user-shield"></i>
      <h3>No staff yet</h3>
      <p>Invite team members to help manage your properties.</p>
    </div>
  <?php endif; ?>

  <!-- Pending Invites -->
  <?php if ($pendingInvites): ?>
  <div class="card">
    <div class="card-header">Pending Invitations</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Email</th>
            <th>Assigned House</th>
            <th>Sent</th>
            <th>Expires</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingInvites as $inv): ?>
          <tr>
            <td><?= sanitize($inv['email']) ?></td>
            <td>
              <?php if ($inv['assigned_house']): ?>
                <i class="fa fa-house fa-xs" style="color:var(--primary)"></i>
                <?= sanitize($inv['assigned_house']) ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= formatDate($inv['created_at']) ?></td>
            <td><?= date('M j, Y H:i', strtotime($inv['expires_at'])) ?></td>
            <td><span class="badge badge-pending">Pending</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Invite Modal -->
<?php if ($houses): ?>
<div id="inviteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:440px;margin:20px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span>Invite New Staff</span>
      <button onclick="document.getElementById('inviteModal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:18px">&times;</button>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="invite">

        <div class="form-group">
          <label class="required">Staff's Email Address</label>
          <input type="email" name="email" placeholder="staff@email.com" required autofocus
                 value="<?= sanitize($_POST['email'] ?? '') ?>">
          <p class="form-hint">They will receive a staff invitation link valid for <?= INVITE_EXPIRY_HOURS ?> hours.</p>
        </div>

        <div class="form-group">
          <label class="required">Assign to Transient House</label>
          <select name="house_id" required>
            <option value="">— Select a house —</option>
            <?php foreach ($houses as $h): ?>
              <option value="<?= $h['id'] ?>" <?= (($_POST['house_id'] ?? '') == $h['id']) ? 'selected' : '' ?>>
                <?= sanitize($h['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="form-hint">This staff can only manage bookings for the selected house.</p>
        </div>

        <div class="btn-group">
          <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Send Invite</button>
          <button type="button" class="btn btn-outline" onclick="document.getElementById('inviteModal').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>