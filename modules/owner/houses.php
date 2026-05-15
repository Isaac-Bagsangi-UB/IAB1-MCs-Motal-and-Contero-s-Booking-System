<?php
// modules/owner/houses.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/upload.php';
requireRole('owner','admin');

$db   = getDB();
$user = currentUser();
$ownerId = $user['id'];
$assignedHouseId = null; // For admins: their assigned house

if ($user['role'] === 'admin') {
    $stmt = $db->prepare("SELECT owner_id, house_id FROM owner_admins WHERE admin_id=?");
    $stmt->execute([$user['id']]);
    $link = $stmt->fetch();
    if (!$link) {
        flash('error', 'Your admin account is not linked to any owner.');
        redirect('modules/admin/dashboard.php');
    }
    $ownerId = $link['owner_id'];
    $assignedHouseId = $link['house_id'];
    
    if (!$assignedHouseId) {
        flash('error', 'You are not assigned to any specific house. Contact your owner.');
        redirect('modules/admin/dashboard.php');
    }
}

$errors = [];
$action = $_GET['action'] ?? '';
$editId = intval($_GET['id'] ?? 0);
$editHouse = null;

if ($editId) {
    $stmt = $db->prepare("SELECT * FROM transient_houses WHERE id=? AND owner_id=?");
    $stmt->execute([$editId, $ownerId]);
    $editHouse = $stmt->fetch();
    
    // For admins, verify they can edit this house
    if ($editHouse && $assignedHouseId && $editHouse['id'] !== $assignedHouseId) {
        flash('error', 'You can only manage your assigned house.');
        redirect('modules/owner/houses.php');
    }
    
    if ($editHouse) $action = 'edit';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act   = $_POST['form_action'] ?? '';
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $addr  = trim($_POST['address'] ?? '');
    $city  = trim($_POST['city'] ?? '');
    $brgy  = trim($_POST['barangay'] ?? '');
    $phone = trim($_POST['contact_number'] ?? '');
    $amenities = json_encode(array_filter(array_map('trim', explode(',', $_POST['amenities'] ?? ''))));

    // Admins cannot create new houses (only owners can)
    if ($assignedHouseId && $act === 'create') {
        $errors[] = 'You can only manage your assigned house. Contact your owner to add new houses.';
    }

    if ($act !== 'delete' && $act !== 'toggle') {
        if (!$name || !$addr || !$city) { 
            $errors[] = 'Name, address, and city are required.'; 
        }
    }

    if (!$errors) {
        $cover = null;
        if ($act !== 'delete' && !empty($_FILES['cover_photo']['name'])) {
            $up = uploadFile('cover_photo', 'unit_photos');
            if ($up['success']) $cover = $up['path'];
            else $errors[] = $up['error'];
        }

        if (!$errors) {
            $policies = trim($_POST['policies'] ?? '');
            if ($act === 'create') {
                $db->prepare("INSERT INTO transient_houses (owner_id,name,description,address,city,barangay,contact_number,amenities,policies,cover_photo) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$user['id'],$name,$desc,$addr,$city,$brgy,$phone,$amenities,$policies,$cover]);
                flash('success','Transient house added successfully.');
            } elseif ($act === 'update') {
                $hid = intval($_POST['house_id']);
                // Verify the house belongs to the owner and admin has access
                $chk = $db->prepare("SELECT id FROM transient_houses WHERE id=? AND owner_id=?" . ($assignedHouseId ? " AND id=?" : ""));
                $chkParams = $assignedHouseId ? [$hid, $ownerId, $assignedHouseId] : [$hid, $ownerId];
                $chk->execute($chkParams);
                if ($chk->fetch()) {
                    $db->prepare("UPDATE transient_houses SET name=?,description=?,address=?,city=?,barangay=?,contact_number=?,amenities=?,policies=?" . ($cover ? ",cover_photo=?" : "") . " WHERE id=? AND owner_id=?")
                       ->execute($cover ? [$name,$desc,$addr,$city,$brgy,$phone,$amenities,$policies,$cover,$hid,$ownerId] : [$name,$desc,$addr,$city,$brgy,$phone,$amenities,$policies,$hid,$ownerId]);
                    flash('success','House updated.');
                } else {
                    flash('error', 'House not found or you do not have permission to edit it.');
                }
            } elseif ($act === 'toggle') {
                $hid = intval($_POST['house_id']);
                $toggleAction = $_POST['toggle_action'] ?? 'close';
                
                // Verify house exists, belongs to owner, and admin has access
                $chk = $db->prepare("SELECT name, is_active FROM transient_houses WHERE id=? AND owner_id=?" . ($assignedHouseId ? " AND id=?" : ""));
                $chkParams = $assignedHouseId ? [$hid, $ownerId, $assignedHouseId] : [$hid, $ownerId];
                $chk->execute($chkParams);
                $house = $chk->fetch();
                
                if ($house) {
                    $newStatus = $toggleAction === 'open' ? 1 : 0;
                    $db->prepare("UPDATE transient_houses SET is_active=? WHERE id=? AND owner_id=?")->execute([$newStatus, $hid, $ownerId]);

                    if ($newStatus === 0) {
                        $db->prepare("UPDATE transient_units SET is_active=0 WHERE house_id=?")->execute([$hid]);
                        flash('success', "House '{$house['name']}' closed and all units deactivated successfully.");
                    } else {
                        $db->prepare("UPDATE transient_units SET is_active=1 WHERE house_id=?")->execute([$hid]);
                        flash('success', "House '{$house['name']}' opened and all units activated successfully.");
                    }
                } else {
                    flash('error', 'House not found or you do not have permission to manage it.');
                }
            }
            header("Location: " . base_url("modules/owner/houses.php"));
            exit; 
        }
    }
}

$houses = $db->prepare("SELECT th.*, COUNT(tu.id) as unit_count FROM transient_houses th LEFT JOIN transient_units tu ON tu.house_id=th.id AND tu.is_active=1 WHERE th.owner_id=?" . ($assignedHouseId ? " AND th.id=?" : "") . " GROUP BY th.id ORDER BY th.is_active DESC, th.created_at DESC");
if ($assignedHouseId) {
    $houses->execute([$ownerId, $assignedHouseId]);
} else {
    $houses->execute([$ownerId]);
}
$houses = $houses->fetchAll();

$pageTitle  = 'Transient Houses';
$activePage = 'houses';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="page-header-row page-header mt-3">
    <div>
      <h1>Transient Houses</h1>
      <p><?php 
        if ($assignedHouseId) {
          echo 'Managing your assigned property';
        } else {
          echo 'Manage all your transient properties';
        }
      ?></p>
    </div>
    <?php if (!$assignedHouseId): ?>
      <button class="btn btn-primary" onclick="showForm('add')"><i class="fa fa-plus"></i> Add House</button>
    <?php endif; ?>
  </div>

<?php if ($errors || !empty($_SESSION['flash'])): ?>

    <?php if (!empty($_SESSION['flash'])): 
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        $type = $flash['type'] ?? 'danger';
        $message = $flash['message'] ?? 'No message provided.';
    ?>
        <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
          <i class="fa fa-<?= $type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
          <?= sanitize($message) ?>
          <button type="button" class="btn-close" onclick="this.parentElement.style.display='none'"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="fa fa-exclamation-circle"></i>
            <?= sanitize($errors[0]) ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

  <!-- Add/Edit Form -->
  <div id="houseForm" style="display:<?= ($action==='edit')?'block':'none' ?>" class="card mb-3">
    <div class="card-header"><?= $editHouse ? 'Edit House' : 'Add New House' ?></div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="form_action" value="<?= $editHouse ? 'update' : 'create' ?>">
        <?php if ($editHouse): ?><input type="hidden" name="house_id" value="<?= $editHouse['id'] ?>"><?php endif; ?>
        <div class="form-row">
          <div class="form-group">
            <label class="required">House Name</label>
            <input type="text" name="name" value="<?= sanitize($editHouse['name'] ?? ($_POST['name'] ?? '')) ?>" required>
          </div>
          <div class="form-group">
            <label>Contact Number</label>
            <input type="tel" name="contact_number" value="<?= sanitize($editHouse['contact_number'] ?? ($_POST['contact_number'] ?? '')) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description"><?= sanitize($editHouse['description'] ?? ($_POST['description'] ?? '')) ?></textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="required">Street Address</label>
            <input type="text" name="address" value="<?= sanitize($editHouse['address'] ?? ($_POST['address'] ?? '')) ?>" required>
          </div>
          <div class="form-group">
            <label class="required">City</label>
            <input type="text" name="city" value="<?= sanitize($editHouse['city'] ?? ($_POST['city'] ?? '')) ?>" required>
          </div>
          <div class="form-group">
            <label>Barangay</label>
            <input type="text" name="barangay" value="<?= sanitize($editHouse['barangay'] ?? ($_POST['barangay'] ?? '')) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Amenities <span class="text-muted fs-sm">(comma-separated)</span></label>
          <input type="text" name="amenities" placeholder="WiFi, Parking, AC, PS2, Xbox" value="<?= sanitize($editHouse ? implode(', ', json_decode($editHouse['amenities']??'[]',true)) : ($_POST['amenities'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label>House Policies <span class="text-muted fs-sm">(shown to guests when browsing units)</span></label>
          <textarea name="policies" rows="4" placeholder="e.g. No smoking inside the unit. No pets allowed. Quiet hours from 10PM to 6AM. Additional guests charged ₱500/head beyond the max."><?= sanitize($editHouse['policies'] ?? ($_POST['policies'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
          <label>Cover Photo</label>
          <input type="file" name="cover_photo" accept="image/*" data-preview-for="coverPreview">
          <div id="coverPreview" class="photo-preview-grid">
            <?php if ($editHouse && $editHouse['cover_photo'] && $action === 'edit'): ?>
              <img src="<?= base_url('uploads/'.$editHouse['cover_photo']) ?>" class="photo-preview">
            <?php endif; ?>
          </div>
        </div>
        <div class="btn-group">
          <button type="submit" class="btn btn-primary"><?= $editHouse ? 'Update House' : 'Add House' ?></button>
          <button type="button" class="btn btn-outline" onclick="hideForm()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- House List -->
  <div class="unit-grid">
    <?php foreach ($houses as $h): ?>
    <div class="unit-card">
      <?php if ($h['cover_photo']): ?>
        <img src="<?= base_url('uploads/'.$h['cover_photo']) ?>" class="unit-card-img" alt="">
      <?php else: ?>
        <div class="unit-card-img-placeholder"><i class="fa fa-building"></i></div>
      <?php endif; ?>
      <div class="unit-card-body">
        <h3><?= sanitize($h['name']) ?></h3>
        <div class="details">
          <span><i class="fa fa-map-marker-alt"></i> <?= sanitize($h['city']) ?></span>
          <span><i class="fa fa-door-open"></i> <?= $h['unit_count'] ?> units</span>
        </div>
        <div class="mb-2">
          <?php if ($h['is_active']): ?>
            <span class="badge badge-accepted">Active</span>
          <?php else: ?>
            <span class="badge badge-cancelled">Inactive</span>
          <?php endif; ?>
        </div>
        <?php if ($h['description']): ?>
          <p class="fs-sm text-muted mb-2"><?= sanitize(substr($h['description'],0,80)) ?>...</p>
        <?php endif; ?>
        <div class="btn-group">
          <a href="?id=<?= $h['id'] ?>" class="btn btn-outline btn-sm"><i class="fa fa-edit"></i> Edit</a>
          <a href="<?= base_url('modules/owner/units.php?house_id='.$h['id']) ?>" class="btn btn-info btn-sm"><i class="fa fa-door-open"></i> Units</a>
          <form method="POST" style="display:inline" onsubmit="return confirmToggle(<?= $h['id'] ?>, <?= $h['is_active'] ? 'true' : 'false' ?>)">
            <input type="hidden" name="form_action" value="toggle">
            <input type="hidden" name="house_id" value="<?= $h['id'] ?>">
            <input type="hidden" name="toggle_action" value="<?= $h['is_active'] ? 'close' : 'open' ?>">
            <button type="submit" class="btn <?= $h['is_active'] ? 'btn-danger' : 'btn-success' ?> btn-sm" title="<?= $h['is_active'] ? 'Close house' : 'Open house' ?>">
              <i class="fa <?= $h['is_active'] ? 'fa-times-circle' : 'fa-door-open' ?>"></i> <?= $h['is_active'] ? 'Close' : 'Open' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$houses): ?>
      <div class="empty-state" style="grid-column:1/-1">
        <i class="fa fa-building"></i>
        <h3>No houses yet</h3>
        <p>Add your first transient house to get started.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function showForm(mode) { document.getElementById('houseForm').style.display='block'; window.scrollTo(0,0); }
function hideForm() { document.getElementById('houseForm').style.display='none'; }

function confirmToggle(houseId, isActive) {
    if (isActive) {
      return confirm('⚠️ Close this house?\n\nThis will:\n• Deactivate the house\n• Deactivate ALL units in this house\n• House will disappear from listings\n\nContinue?');
    }
    return confirm('✅ Open this house?\n\nThis will activate the house and all its units again.\n\nContinue?');
}


</script>
<style>
.hover-danger:hover { 
  transform: scale(1.05) !important; 
  box-shadow: 0 2px 8px rgba(220,53,69,.3) !important; 
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
