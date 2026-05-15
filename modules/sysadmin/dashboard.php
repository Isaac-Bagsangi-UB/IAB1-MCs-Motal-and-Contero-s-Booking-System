<?php
// modules/sysadmin/dashboard.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole('sysadmin');

$db = getDB();
$errors = [];

// Send invite to owner
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } else {
        $chk = $db->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $errors[] = 'This email is already registered.';
        } else {
            $token      = generateToken();
        $expiry     = date('Y-m-d H:i:s', strtotime('+' . INVITE_EXPIRY_HOURS . ' hours'));
        $user       = currentUser();
        $inviteRole = in_array($_POST['invite_role'] ?? '', ['owner','sysadmin']) ? $_POST['invite_role'] : 'owner';
        $db->prepare("INSERT INTO invitations (email,token,role,invited_by,expires_at) VALUES (?,?,?,?,?)")
           ->execute([$email,$token,$inviteRole,$user['id'],$expiry]);
        sendInviteEmail($email, $token, $inviteRole);
        flash('success', "Invitation sent to {$email} as " . ucfirst($inviteRole) . ".");
        redirect('modules/sysadmin/dashboard.php');
        }
    }
}

// Stats
$totalOwners  = $db->query("SELECT COUNT(*) FROM users WHERE role='owner' AND is_active=1")->fetchColumn();
$totalHouses  = $db->query("SELECT COUNT(*) FROM transient_houses WHERE is_active=1")->fetchColumn();
$totalUnits   = $db->query("SELECT COUNT(*) FROM transient_units WHERE is_active=1")->fetchColumn();
$totalGuests  = $db->query("SELECT COUNT(*) FROM users WHERE role='guest' AND is_active=1")->fetchColumn();
$totalBookings= $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();

$pageTitle  = 'System Admin Dashboard';
$activePage = 'dashboard';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="page-header-row page-header mt-3">
    <div>
      <h1>System Admin Dashboard</h1>
      <p>Manage owners and system-wide settings</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('inviteModal').style.display='flex'">
      <i class="fa fa-plus"></i> Add Personel
    </button>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa fa-users"></i></div>
      <div><div class="stat-value"><?= $totalOwners ?></div><div class="stat-label">Total Owners</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon purple"><i class="fa fa-user"></i></div>
      <div><div class="stat-value"><?= $totalGuests ?></div><div class="stat-label">Registered Guests</div></div>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= sanitize($errors[0]) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      Recent Owners
      <a href="<?= base_url('modules/sysadmin/owners.php') ?>" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Properties</th><th>Joined</th><th>Status</th></tr></thead>
        <tbody>
          <?php
          $owners = $db->query("SELECT u.*, COUNT(th.id) as house_count FROM users u
            LEFT JOIN transient_houses th ON th.owner_id=u.id
            WHERE u.role='owner' GROUP BY u.id ORDER BY u.created_at DESC LIMIT 10")->fetchAll();
          foreach ($owners as $o): ?>
          <tr>
            <td><?= sanitize($o['first_name'].' '.$o['last_name']) ?></td>
            <td><?= sanitize($o['email']) ?></td>
            <td><?= $o['house_count'] ?></td>
            <td><?= formatDate($o['created_at']) ?></td>
            <td>
              <?php if (!$o['is_active']): ?>
                <span class="badge badge-cancelled">Deleted</span>
              <?php elseif ($o['is_deactivated']): ?>
                <span class="badge badge-pending">Deactivated</span>
              <?php else: ?>
                <span class="badge badge-accepted">Active</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$owners): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:30px">No owners yet. Send an invite to get started.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Invite Modal -->
<div id="inviteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center">
  <div class="card" style="width:100%;max-width:440px;margin:20px">
    <div class="card-header">
      Send Invitation
      <button onclick="document.getElementById('inviteModal').style.display='none'" style="background:none;border:none;cursor:pointer;font-size:18px">&times;</button>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="form-group">
          <label class="required">Email Address</label>
          <input type="email" name="email" placeholder="email@example.com" required autofocus>
          <p class="form-hint">An invitation link will be sent to this email (expires in 24 hours).</p>
        </div>
        <div class="form-group">
          <label class="required">Role</label>
          <select name="invite_role" required>
            <option value="owner">Owner</option>
            <option value="sysadmin">System Admin</option>
          </select>
        </div>
        <div class="btn-group">
          <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> Send Invite</button>
          <button type="button" class="btn btn-outline" onclick="document.getElementById('inviteModal').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<br><br>
<div class="backup-action-wrapper">
  <a href="http://localhost/mctbs/modules/sysadmin/backup.php" 
     class="btn btn-primary backup-btn">
    <i class="fa fa-download"></i>
    Create Backup
  </a>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
