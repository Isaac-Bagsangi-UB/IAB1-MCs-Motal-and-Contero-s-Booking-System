<?php
// modules/auth/profile.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/upload.php';
requireRole('sysadmin','owner','admin','guest');

$db   = getDB();
$user = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');
        if (!$firstName || !$lastName) { $errors[] = 'Name is required.'; }
        else {
            $photo = $user['profile_photo'];
            if (!empty($_FILES['profile_photo']['name'])) {
                $up = uploadFile('profile_photo', 'profile_photos');
                if ($up['success']) $photo = $up['path'];
                else $errors[] = $up['error'];
            }
            if (!$errors) {
                $db->prepare("UPDATE users SET first_name=?,last_name=?,phone=?,profile_photo=? WHERE id=?")
                   ->execute([$firstName,$lastName,$phone,$photo,$user['id']]);
                $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
                $stmt->execute([$user['id']]);
                $_SESSION['user'] = $stmt->fetch();
                flash('success','Profile updated successfully.');
                redirect('modules/auth/profile.php');
            }
        }
    } elseif ($action === 'change_password') {
        $cur  = $_POST['current_password']  ?? '';
        $new  = $_POST['new_password']      ?? '';
        $conf = $_POST['confirm_password']  ?? '';
        if (!password_verify($cur, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new)<8||!preg_match('/[A-Z]/',$new)||!preg_match('/[0-9]/',$new)||!preg_match('/[^A-Za-z0-9]/',$new)) {
            $errors[] = 'New password does not meet requirements.';
        } elseif ($new !== $conf) {
            $errors[] = 'New passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash,$user['id']]);
            flash('success','Password changed successfully.');
            redirect('modules/auth/profile.php');
        }
    }
}

$pageTitle  = 'Profile';
$activePage = 'profile';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container container-sm">
  <div class="page-header mt-3">
    <h1>My Account</h1>
    <p>Manage your personal information and password</p>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= sanitize($errors[0]) ?></div>
  <?php endif; ?>

  <div class="row">
    <div class="col">
      <div class="card mb-3">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
          Personal Information
          <button type="button" id="editProfileBtn" class="btn btn-outline btn-sm" onclick="toggleEdit()">
            <i class="fa fa-edit"></i> Edit Account
          </button>
        </div>
        <div class="card-body">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">
            <div class="text-center mb-3">
              <?php if ($user['profile_photo']): ?>
                <img src="<?= base_url('uploads/'.$user['profile_photo']) ?>" id="photoPreview" class="avatar-sm" style="width:80px;height:80px;border-radius:50%;object-fit:cover">
              <?php else: ?>
                <div id="photoPreview" style="width:80px;height:80px;border-radius:50%;background:var(--accent);color:#fff;font-size:28px;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0 auto">
                  <?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?>
                </div>
              <?php endif; ?>
              <div class="mt-2">
                <label for="photo_input" class="btn btn-outline btn-sm" style="cursor:pointer">Change Photo</label>
                <input type="file" id="photo_input" name="profile_photo" accept="image/*" data-preview-for="photoPreview" style="display:none">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="required">First Name</label>
                <input type="text" name="first_name" id="firstNameInput" value="<?= sanitize($user['first_name']) ?>" required disabled>
              </div>
              <div class="form-group">
                <label class="required">Last Name</label>
                <input type="text" name="last_name" id="lastNameInput" value="<?= sanitize($user['last_name']) ?>" required disabled>
              </div>
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" value="<?= sanitize($user['email']) ?>" disabled>
            </div>
            <div class="form-group">
              <label>Phone Number (optional)</label>
              <input type="tel" name="phone" id="phoneInput" value="<?= sanitize($user['phone'] ?? '') ?>" disabled>
            </div>
            <button type="submit" id="saveBtn" class="btn btn-primary" style="display:none">Save Changes</button>
            <button type="button" id="cancelEditBtn" class="btn btn-outline" style="display:none" onclick="cancelEdit()">Cancel</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Change Password</div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
              <label class="required">Current Password</label>
              <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
              <label class="required">New Password</label>
              <input type="password" name="new_password" id="password" required>
              <div class="password-rule">
                <span id="rule-len">8+ chars</span>
                <span id="rule-upper">Uppercase</span>
                <span id="rule-lower">Lowercase</span>
                <span id="rule-num">Number</span>
                <span id="rule-spec">Special</span>
              </div>
            </div>
            <div class="form-group">
              <label class="required">Confirm New Password</label>
              <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn btn-secondary">Update Password</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const editableInputs = ['firstNameInput','lastNameInput','phoneInput'];
const originalValues = {};

function toggleEdit() {
    editableInputs.forEach(id => {
        const el = document.getElementById(id);
        originalValues[id] = el.value;
        el.disabled = false;
        el.style.borderColor = 'var(--accent)';
        el.style.background = '#fffbf5';
        el.style.boxShadow = '0 0 0 3px rgba(230,126,34,.12)';
    });
    document.getElementById('editProfileBtn').style.display = 'none';
    document.getElementById('saveBtn').style.display = 'inline-flex';
    document.getElementById('cancelEditBtn').style.display = 'inline-flex';
    document.getElementById('firstNameInput').focus();
}

function cancelEdit() {
    editableInputs.forEach(id => {
        const el = document.getElementById(id);
        el.value = originalValues[id];
        el.disabled = true;
        el.style.borderColor = '';
        el.style.background = '';
        el.style.boxShadow = '';
    });
    document.getElementById('editProfileBtn').style.display = 'inline-flex';
    document.getElementById('saveBtn').style.display = 'none';
    document.getElementById('cancelEditBtn').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
