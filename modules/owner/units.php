<?php
// modules/owner/units.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/upload.php';
requireRole('owner','admin');

$db   = getDB();
$user = currentUser();

$baseModule = $user['role']==='admin' ? 'admin' : 'owner';
$assignedHouseId = null;

// Get owner id and assigned house (for admins)
if ($user['role']==='admin') {
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
} else {
    $ownerId = $user['id'];
}

$errors  = [];
$action  = $_GET['action'] ?? 'list';
$editId  = intval($_GET['id'] ?? 0);
$houseFilter = intval($_GET['house_id'] ?? 0);
$editUnit = null;

// For admins, force the house filter to their assigned house
if ($assignedHouseId) {
    $houseFilter = $assignedHouseId;
}

if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $db->prepare("SELECT tu.* FROM transient_units tu JOIN transient_houses th ON tu.house_id=th.id WHERE tu.id=? AND th.owner_id=?" . ($assignedHouseId ? " AND th.id=?" : ""));
    if ($assignedHouseId) {
        $stmt->execute([$editId, $ownerId, $assignedHouseId]);
    } else {
        $stmt->execute([$editId, $ownerId]);
    }
    $editUnit = $stmt->fetch();
    if ($editUnit) $action = 'edit';
}

// Houses for dropdown
$myHouses = $db->prepare("SELECT id,name FROM transient_houses WHERE owner_id=?" . ($assignedHouseId ? " AND id=?" : ""));
if ($assignedHouseId) {
    $myHouses->execute([$ownerId, $assignedHouseId]);
} else {
    $myHouses->execute([$ownerId]);
}
$myHouses = $myHouses->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act   = $_POST['form_action'] ?? '';
    $houseId = intval($_POST['house_id_select'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $maxG  = intval($_POST['max_guests'] ?? 1);
    $price = floatval($_POST['price_per_night'] ?? 0);
    $amenities = json_encode(array_filter(array_map('trim', explode(',', $_POST['amenities'] ?? ''))));

    // If houseId is 0 during edit, get it from the unit's existing house
    if (!$houseId && isset($_POST['unit_id'])) {
        $uid = intval($_POST['unit_id']);
        $hStmt = $db->prepare("SELECT house_id FROM transient_units WHERE id=?");
        $hStmt->execute([$uid]);
        $houseId = intval($hStmt->fetchColumn());
    }
    
    // For admins, enforce house assignment
    if ($assignedHouseId && $houseId !== $assignedHouseId) {
        $errors[] = 'You can only work with your assigned house.';
    }
    
    // Verify house exists and belongs to owner
    if ($houseId && !$errors) {
        $houseChk = $db->prepare("SELECT id FROM transient_houses WHERE id=? AND owner_id=?");
        $houseChk->execute([$houseId, $ownerId]);
        if (!$houseChk->fetch()) {
            $errors[] = 'Invalid house selected.';
        }
    }

    if ($act !== 'toggle' && $act !== 'delete_photo') {
        if (!$name || !$houseId || $price <= 0) { 
            $errors[] = 'Name, house, and price are required.'; 
        }
    }

    if ($act === 'create' || $act === 'update') {
    $dupCheck = $db->prepare("SELECT id FROM transient_units WHERE house_id=? AND name=? AND is_active=1" . ($act==='update' ? " AND id!=?" : ""));
    $params_dup = $act==='update' ? [$houseId, $name, intval($_POST['unit_id'])] : [$houseId, $name];
    $dupCheck->execute($params_dup);
    if ($dupCheck->fetch()) $errors[] = "This house already has a unit named \"{$name}\". Please use a different name.";
}
    if (!$errors) {
    if ($act === 'create') {
        $db->prepare("INSERT INTO transient_units (house_id,name,description,max_guests,price_per_night,amenities) VALUES (?,?,?,?,?,?)")
           ->execute([$houseId,$name,$desc,$maxG,$price,$amenities]);
        $unitId = $db->lastInsertId();
        // Handle photos
        $photos = uploadMultiple('unit_photos', 'unit_photos');
        foreach ($photos as $i => $path) {
            $db->prepare("INSERT INTO unit_photos (unit_id,photo_path,is_cover,sort_order) VALUES (?,?,?,?)")
               ->execute([$unitId,$path,($i===0?1:0),$i]);
        }
        flash('success','Unit added.');
        redirect("modules/{$baseModule}/units.php");
        exit;
    } elseif ($act === 'update') {
        $uid = intval($_POST['unit_id']);
        $db->prepare("UPDATE transient_units SET house_id=?,name=?,description=?,max_guests=?,price_per_night=?,amenities=? WHERE id=?")
           ->execute([$houseId,$name,$desc,$maxG,$price,$amenities,$uid]);

        $photos = uploadMultiple('unit_photos', 'unit_photos');
        if ($photos) {
            $orderStmt = $db->prepare("SELECT MAX(sort_order) AS max_order, MAX(is_cover) AS has_cover FROM unit_photos WHERE unit_id=?");
            $orderStmt->execute([$uid]);
            $orderInfo = $orderStmt->fetch();
            $nextOrder  = intval($orderInfo['max_order']) + 1;
            $hasCover   = intval($orderInfo['has_cover']) > 0;

            foreach ($photos as $i => $path) {
                $isCover = (!$hasCover && $i === 0) ? 1 : 0;
                $db->prepare("INSERT INTO unit_photos (unit_id,photo_path,is_cover,sort_order) VALUES (?,?,?,?)")
                   ->execute([$uid,$path,$isCover,$nextOrder + $i]);
            }
        }

        flash('success','Unit updated.');
        redirect("modules/{$baseModule}/units.php" . ($houseFilter ? "?house_id={$houseFilter}" : ""));
        exit;
    } elseif ($act === 'delete_photo') {
        $photoId = intval($_POST['photo_id']);
        $uid     = intval($_POST['unit_id']);

        $chk = $db->prepare("
            SELECT up.id, up.photo_path, up.is_cover, up.unit_id
            FROM unit_photos up
            JOIN transient_units tu ON up.unit_id = tu.id
            JOIN transient_houses th ON tu.house_id = th.id
            WHERE up.id = ? AND th.owner_id = ?
        ");
        $chk->execute([$photoId, $ownerId]);
        $photo = $chk->fetch();

        if ($photo) {
            $filePath = UPLOAD_DIR . $photo['photo_path'];
            if (file_exists($filePath)) unlink($filePath);

            $db->prepare("DELETE FROM unit_photos WHERE id = ?")->execute([$photoId]);

            if ($photo['is_cover']) {
                $next = $db->prepare("SELECT id FROM unit_photos WHERE unit_id = ? ORDER BY sort_order, id LIMIT 1");
                $next->execute([$uid]);
                $nextPhoto = $next->fetch();
                if ($nextPhoto) {
                    $db->prepare("UPDATE unit_photos SET is_cover = 1 WHERE id = ?")->execute([$nextPhoto['id']]);
                }
            }

            flash('success', 'Photo deleted.');
        } else {
            flash('error', 'Photo not found or access denied.');
        }

        redirect("modules/{$baseModule}/units.php?action=edit&id={$uid}");
        exit;
    } elseif ($act === 'toggle') {
        $uid = intval($_POST['unit_id']);
        $toggleAction = $_POST['toggle_action'] ?? 'close';
        $chk = $db->prepare("
            SELECT tu.id, tu.name FROM transient_units tu
            JOIN transient_houses th ON tu.house_id = th.id
            WHERE tu.id = ? AND th.owner_id = ?
        ");
        $chk->execute([$uid, $ownerId]);
        $unit = $chk->fetch();
        if ($unit) {
            $newStatus = $toggleAction === 'open' ? 1 : 0;
            $db->prepare("UPDATE transient_units SET is_active = ? WHERE id = ?")->execute([$newStatus, $uid]);
            flash('success', $newStatus ? "Unit '{$unit['name']}' opened." : "Unit '{$unit['name']}' closed.");
        } else {
            flash('error', 'Unit not found.');
        }
        redirect("modules/{$baseModule}/units.php");
        exit;
    }
    }
}

// Get units
$q = "SELECT tu.*, th.name as house_name,
      (SELECT photo_path FROM unit_photos WHERE unit_id=tu.id ORDER BY is_cover DESC, sort_order ASC, id ASC LIMIT 1) as cover
      FROM transient_units tu
      JOIN transient_houses th ON tu.house_id=th.id
      WHERE th.owner_id=?";
$params = [$ownerId];

// For admins, always filter to their assigned house
if ($assignedHouseId) {
    $q .= " AND tu.house_id=?";
    $params[] = $assignedHouseId;
} elseif ($houseFilter) {
    $q .= " AND tu.house_id=?";
    $params[] = $houseFilter;
}

$q .= " ORDER BY tu.is_active DESC, th.name, tu.name";
$stmt = $db->prepare($q); $stmt->execute($params);
$units = $stmt->fetchAll();

$pageTitle  = 'Transient Units';
$activePage = 'units';

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="page-header-row page-header mt-3">
    <div>
      <h1>Transient Units</h1>
      <p><?php 
        if ($assignedHouseId) {
          echo 'Manage units in your assigned property';
        } else {
          echo 'Manage rooms and units across all your houses';
        }
      ?></p>
    </div>
    <button class="btn btn-primary" onclick="showAddForm()"><i class="fa fa-plus"></i> Add Unit</button>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= sanitize($errors[0]) ?></div>
  <?php elseif (!empty($_SESSION['flash'])): 
      $flash = $_SESSION['flash']; 
      unset($_SESSION['flash']);

      $type = $flash['type'] ?? 'danger';
      $message = $flash['message'] ?? 'Something went wrong.';
  ?>
      <div class="alert alert-<?= $type === 'success' ? 'success' : 'danger' ?> alert-dismissible">
        <i class="fa fa-<?= $type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
        <?= sanitize($message) ?>
      <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    </div>
  <?php endif; ?>

  <!-- Filter by house (only for owners, not admins) -->
  <?php if ($myHouses && !$assignedHouseId): ?>
  <div class="card mb-3" style="padding:12px 20px; border-radius:8px;">
    <div class="flex-center gap-2">
      <label style="font-size:13px; font-weight:600;">Filter by House:</label>
      <select id="houseFilter" data-ajax-filter="units" style="width:auto;">
        <option value="">All Houses</option>
        <?php foreach ($myHouses as $h): ?>
          <option value="<?= $h['id'] ?>" <?= $houseFilter==$h['id']?'selected':'' ?>><?= sanitize($h['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <?php endif; ?>

  <!-- Add/Edit Form -->
<div id="unitForm" style="display:<?= ($action==='edit'||$errors)?'block':'none' ?>" class="card mb-3">
  <div class="card-header"><?= $editUnit ? 'Edit Unit' : 'Add New Unit' ?></div>
  <div class="card-body">
    <form method="POST" action="<?= base_url('modules/'.$baseModule.'/units.php'.($houseFilter?'?house_id='.$houseFilter:'')) ?>" enctype="multipart/form-data">
      <input type="hidden" name="form_action" value="<?= $editUnit ? 'update' : 'create' ?>">
      <?php if ($editUnit): ?><input type="hidden" name="unit_id" value="<?= $editUnit['id'] ?>"><?php endif; ?>
      
      <div class="form-row">
        <div class="form-group">
          <label class="required">House</label>
          <?php if ($assignedHouseId): ?>
            <!-- Admin: Show assigned house as read-only -->
            <input type="hidden" name="house_id_select" value="<?= $assignedHouseId ?>">
            <input type="text" readonly value="<?= $myHouses[0]['name'] ?? 'Your Assigned House' ?>">
          <?php else: ?>
            <!-- Owner: Show dropdown -->
            <select name="house_id_select" required>
              <option value="">Select house</option>
              <?php foreach ($myHouses as $h): ?>
                <option value="<?= $h['id'] ?>"
                  <?= ($editUnit && $editUnit['house_id']==$h['id']) ? 'selected' : ($houseFilter==$h['id'] ? 'selected' : '') ?>>
                  <?= sanitize($h['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="required">Unit Name</label>
          <input type="text" name="name" value="<?= sanitize($editUnit['name'] ?? '') ?>" placeholder="e.g. Room A, Suite 1" required>
        </div>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description"><?= sanitize($editUnit['description'] ?? '') ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="required">Max Guests</label>
          <input type="number" name="max_guests" min="1" max="50" value="<?= $editUnit['max_guests'] ?? 1 ?>" required>
        </div>
        <div class="form-group">
          <label class="required">Price per Night (₱)</label>
          <input type="number" name="price_per_night" min="0" step="0.01" value="<?= $editUnit['price_per_night'] ?? '' ?>" required>
        </div>
      </div>
      <div class="form-group">
        <label>Amenities <span class="text-muted fs-sm">(comma-separated)</span></label>
        <input type="text" name="amenities" placeholder="AC, TV, Kitchen" value="<?= sanitize($editUnit ? implode(', ', json_decode($editUnit['amenities']??'[]',true)) : '') ?>">
      </div>

      <div class="form-group">
        <label>Unit Photos</label>
        <div class="photos-section">
          <div class="new-photos-upload mb-3">
            <input type="file" name="unit_photos[]" multiple accept="image/*" id="photoUploadInput">
            <small class="text-muted">Upload new photos (optional)</small>
            <div id="photoGrid" class="photo-preview-grid mt-2"></div>
          </div>

          <?php if ($editUnit): ?>
            <?php
              $photos = $db->prepare("SELECT * FROM unit_photos WHERE unit_id=? ORDER BY sort_order, id");
              $photos->execute([$editUnit['id']]);
              $photos = $photos->fetchAll();
            ?>
            <?php if ($photos): ?>
              <div class="existing-photos-section">
                <h6 class="mb-2"><i class="fa fa-images"></i> Existing Photos <span class="badge bg-secondary"><?= count($photos) ?> photo<?= count($photos)!==1?'s':'' ?></span></h6>
                <p class="text-muted fs-sm mb-2">Click trash icon to remove individual photos</p>
                <div class="existing-photos-grid row g-2">
                  <?php foreach ($photos as $p): ?>
                    <div class="col-auto existing-photo-item" id="photo-item-<?= $p['id'] ?>">
                      <div class="position-relative">
                        <?php if ($p['is_cover']): ?>
                          <span class="cover-badge position-absolute top-0 start-0 badge badge-success m-1">⭐ Cover</span>
                        <?php endif; ?>
                        <img src="<?= base_url('uploads/'.$p['photo_path']) ?>" alt="" class="img-thumbnail" style="width:120px;height:90px;object-fit:cover;">
                        <button type="button" onclick="confirmDeletePhoto(<?= $p['id'] ?>, <?= $editUnit['id'] ?>)" class="btn btn-danger position-absolute bottom-0 end-0 mb-1 me-1" title="Delete photo" style="font-size:12px;padding:2px 6px;">
                          <i class="fa fa-trash"></i>
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="btn-group">
        <button type="submit" class="btn btn-primary" id="submitBtn"><?= $editUnit ? 'Update Unit' : 'Add Unit' ?></button>
        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('unitForm').style.display='none'">Cancel</button>
      </div>
    </form>
  </div>
</div>

  <!-- Units Grid -->
  <div class="unit-grid">
    <?php foreach ($units as $u): ?>
    <div class="unit-card">
      <?php if ($u['cover']): ?>
        <img src="<?= base_url('uploads/'.$u['cover']) ?>" class="unit-card-img" alt="">
      <?php else: ?>
        <div class="unit-card-img-placeholder"><i class="fa fa-door-open"></i></div>
      <?php endif; ?>
      <div class="unit-card-body">
        <h3><?= sanitize($u['name']) ?></h3>
        <div class="text-muted fs-sm mb-1"><?= sanitize($u['house_name']) ?></div>
        <div class="details">
          <span><i class="fa fa-users"></i> Max <?= $u['max_guests'] ?></span>
          <span class="price"><?= formatMoney($u['price_per_night']) ?>/night</span>
        </div>
        <div class="mb-2">
          <?php if ($u['is_active']): ?>
            <span class="badge badge-accepted">Active</span>
          <?php else: ?>
            <span class="badge badge-cancelled">Inactive</span>
          <?php endif; ?>
        </div>
        <div class="btn-group">
          <a href="?action=edit&id=<?= $u['id'] ?><?= $houseFilter ? '&house_id='.$houseFilter : '' ?>" class="btn btn-outline btn-sm"><i class="fa fa-edit"></i> Edit</a>
          <a href="<?= base_url("modules/{$baseModule}/calendar.php?unit_id={$u['id']}") ?>" class="btn btn-info btn-sm"><i class="fa fa-calendar"></i></a>
          <form method="POST" action="<?= base_url('modules/'.$baseModule.'/units.php') ?>" style="display:inline" onsubmit="return confirmUnitToggle(<?= $u['id'] ?>, <?= $u['is_active'] ? 'true' : 'false' ?>)">
            <input type="hidden" name="form_action" value="toggle">
            <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
            <input type="hidden" name="toggle_action" value="<?= $u['is_active'] ? 'close' : 'open' ?>">
            <input type="hidden" name="house_id_select" value="<?= $u['house_id'] ?>">
            <button type="submit" class="btn <?= $u['is_active'] ? 'btn-danger' : 'btn-success' ?> btn-sm" title="<?= $u['is_active'] ? 'Close unit' : 'Open unit' ?>">
              <i class="fa <?= $u['is_active'] ? 'fa-times-circle' : 'fa-door-open' ?>"></i> <?= $u['is_active'] ? 'Close' : 'Open' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$units): ?>
      <div class="empty-state" style="grid-column:1/-1">
        <i class="fa fa-door-open"></i>
        <h3>No units yet</h3>
        <p>Add units to your transient houses.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
function showAddForm() {
    const form = document.querySelector('#unitForm form');
    form.querySelectorAll('input[type=text], input[type=number], textarea').forEach(el => el.value = '');
    form.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
    form.querySelector('[name=form_action]').value = 'create';
    const uid = form.querySelector('[name=unit_id]');
    if (uid) uid.remove();
    document.querySelector('#unitForm .card-header').textContent = 'Add New Unit';
    document.getElementById('submitBtn').textContent = 'Add Unit';
    document.getElementById('unitForm').style.display = 'block';
    window.scrollTo(0, 0);
}

function loadEditForm(id, name, desc, maxGuests, price, houseId, amenities) {
    const form = document.querySelector('#unitForm form');
    form.querySelector('[name=form_action]').value = 'update';
    form.querySelector('[name=name]').value = name;
    form.querySelector('[name=description]').value = desc;
    form.querySelector('[name=max_guests]').value = maxGuests;
    form.querySelector('[name=price_per_night]').value = price;
    form.querySelector('[name=amenities]').value = amenities;

    // Set house dropdown
    const houseSelect = form.querySelector('[name=house_id_select]');
    houseSelect.value = houseId;

    // Add or update unit_id hidden input
    let uid = form.querySelector('[name=unit_id]');
    if (!uid) {
        uid = document.createElement('input');
        uid.type = 'hidden';
        uid.name = 'unit_id';
        form.appendChild(uid);
    }
    uid.value = id;

    document.querySelector('#unitForm .card-header').textContent = 'Edit Unit';
    document.getElementById('submitBtn').textContent = 'Update Unit';
    document.getElementById('unitForm').style.display = 'block';
    window.scrollTo(0, 0);
}
function confirmUnitDelete(unitId, unitName) {
    return confirm(`⚠️ Delete "${unitName}"?\n\nThis will:\n• Deactivate this unit\n• Remove from all listings\n• Cannot be undone.\n\nContinue?`);
}

function confirmDeletePhoto(photoId, unitId) {
    if (!confirm('Remove this photo? This cannot be undone.')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.pathname + window.location.search;

    const addField = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };
    addField('form_action', 'delete_photo');
    addField('photo_id', photoId);
    addField('unit_id', unitId);

    document.body.appendChild(form);
    form.submit();
}

// AJAX House Filter
function initUnitsFilter() {
    const houseFilter = document.getElementById('houseFilter');
    if (houseFilter) {
        houseFilter.addEventListener('change', () => {
            const houseId = houseFilter.value;
            fetch(`<?= base_url('includes/ajax_filter_units.php') ?>?house_id=${encodeURIComponent(houseId)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = houseId ? `?house_id=${houseId}` : '';
                    }
                })
                .catch(err => console.error('Filter error:', err));
        });
    }
}

window.addEventListener('DOMContentLoaded', () => {
    const url = new URL(window.location.href);

    // If NOT editing, force hide form
    if (!url.searchParams.get('id')) {
        document.getElementById('unitForm').style.display = 'none';
    }
    
    // Initialize units filter
    initUnitsFilter();
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
