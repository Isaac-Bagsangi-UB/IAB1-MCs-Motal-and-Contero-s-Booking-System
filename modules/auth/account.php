<?php
// modules/auth/account.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('sysadmin','owner','admin','guest');

$db   = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pass   = $_POST['confirm_password'] ?? '';
    if (!password_verify($pass, $user['password'])) {
        flash('error', 'Incorrect password. Action not performed.');
        redirect('modules/auth/account.php');
    }
    if ($action === 'deactivate') {
        $db->prepare("UPDATE users SET is_deactivated=1 WHERE id=?")->execute([$user['id']]);
        session_destroy();
        redirect('modules/auth/login.php');
    } elseif ($action === 'delete') {
        // Soft delete — account is hidden immediately but data is retained.
        // Permanent purge is scheduled 3 years from this date via a cleanup job.
        $db->prepare("UPDATE users SET is_active=0, deleted_at=NOW() WHERE id=?")->execute([$user['id']]);
        session_destroy();
        redirect('modules/auth/login.php');
    }
}

$pageTitle  = 'Account Settings';
$activePage = 'account';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container container-sm">
  <div class="page-header mt-3">
    <h1>Session & Account</h1>
    <p>Manage your account status</p>
  </div>
<!-- 
  <div class="card mb-3">
    <div class="card-header">Deactivate Account</div>
    <div class="card-body">
      <p class="text-muted fs-sm mb-2">Temporarily deactivate your account. You can contact support to reactivate it.</p>
      <form method="POST" data-confirm="Are you sure you want to deactivate your account?">
        <input type="hidden" name="action" value="deactivate">
        <div class="form-group">
          <label class="required">Confirm with your password</label>
          <input type="password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn btn-warning">Deactivate Account</button>
      </form>
    </div>
  </div> -->

  <div class="card">
    <div class="card-header" style="color:var(--danger)">Delete Account</div>
    <div class="card-body">
      <p class="text-muted fs-sm mb-2">
        This will <strong>deactivate your account immediately</strong> — you will be logged out
        and will no longer be able to sign in.
      </p>
      <div class="alert alert-warning fs-sm" style="border-left:4px solid #ffc107;padding:10px 14px;border-radius:6px;background:#fff9e6;margin-bottom:12px">
        <i class="fa fa-info-circle"></i>
        <strong>Your data is not immediately erased.</strong> For compliance and audit purposes,
        account records are retained and scheduled for <strong>permanent deletion after 3 years</strong>
        from the date of this request. If you change your mind before then, contact support to restore your account.
      </div>
      <form method="POST" data-confirm="Are you sure you want to delete your account? You will be logged out immediately. Your data will be permanently purged after 3 years.">
        <input type="hidden" name="action" value="delete">
        <div class="form-group">
          <label class="required">Confirm with your password</label>
          <input type="password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn btn-danger">
          <i class="fa fa-trash-alt"></i> Delete My Account
        </button>
      </form>
    </div>
  </div>

  <div class="text-center mt-3">
    <a href="<?= base_url('modules/auth/logout.php') ?>" class="btn btn-outline">
      <i class="fa fa-sign-out-alt"></i> Logout
    </a>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
