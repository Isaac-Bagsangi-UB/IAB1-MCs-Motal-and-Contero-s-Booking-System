<?php
// modules/sysadmin/owners.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('sysadmin');

$db = getDB();

// Handle delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_owner' && isset($_POST['owner_id'])) {
        $ownerId = intval($_POST['owner_id']);
        // Soft delete by setting is_active = 0
        $db->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role = 'owner'")->execute([$ownerId]);
        flash('success', 'Owner account has been deleted.');
        redirect('modules/sysadmin/owners.php');
    }
    
    if ($action === 'bulk_delete' && isset($_POST['selected_owners'])) {
        $selectedIds = array_map('intval', $_POST['selected_owners']);
        if (!empty($selectedIds)) {
            $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
            $db->prepare("UPDATE users SET is_active = 0 WHERE id IN ($placeholders) AND role = 'owner'")->execute($selectedIds);
            flash('success', count($selectedIds) . ' owner accounts have been deleted.');
            redirect('modules/sysadmin/owners.php');
        }
    }
}

$owners = $db->query("
    SELECT u.*, COUNT(DISTINCT th.id) as house_count, COUNT(DISTINCT tu.id) as unit_count
    FROM users u
    LEFT JOIN transient_houses th ON th.owner_id=u.id
    LEFT JOIN transient_units tu ON tu.house_id=th.id
    WHERE u.role='owner' AND u.is_active = 1
    GROUP BY u.id ORDER BY u.created_at DESC
")->fetchAll();

$sysadmins = $db->query("
    SELECT * FROM users WHERE role='sysadmin' ORDER BY created_at ASC
")->fetchAll();

$guests = $db->query("
    SELECT *
    FROM users
    WHERE role='guest'
    ORDER BY created_at DESC
")->fetchAll();

$pageTitle  = 'Owner List';
$activePage = 'owners';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="page-header-row page-header mt-3">
    <div><h1>Authorized Peronel Lists</h1><p>All registered owners and system admin in the system</p></div>
  </div>

  <div class="tabs">
  <button class="tab-btn" data-tab="tab-owners">Owners <span class="badge badge-accepted" style="margin-left:4px"><?= count($owners) ?></span></button>
  <button class="tab-btn" data-tab="tab-sysadmins">System Admins <span class="badge badge-pending" style="margin-left:4px"><?= count($sysadmins) ?></span></button>
  <button class="tab-btn" data-tab="tab-guests">Guests <span class="badge badge-warning" style="margin-left:4px"><?= count($guests) ?></span>
</button>
</div>

<div class="tab-pane" id="tab-owners">
<div class="card">
    <div class="card-header">
      <span><?= count($owners) ?> Owner(s)</span>
      <div style="display:flex;gap:10px;align-items:center">
        <input type="text" id="searchInput" placeholder="Search owners..." style="padding:6px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:220px">
        <button type="button" id="selectAllBtn" class="btn btn-outline btn-sm">Select All</button>
        <button type="submit" form="ownersForm" name="action" value="bulk_delete" id="bulkDeleteBtn" class="btn btn-danger btn-sm" disabled onclick="return confirm('Are you sure you want to delete the selected owners?')">
          <i class="fa fa-trash"></i> Delete Selected
        </button>
      </div>
    </div>
    <div class="table-wrap">
      <form id="ownersForm" method="POST">
        <table id="ownersTable">
        <thead>
          <tr>
            <th style="width:40px"><input type="checkbox" id="masterCheckbox"></th>
            <th>Name</th><th>Email</th><th>Phone</th><th>Houses</th><th>Units</th><th>Joined</th><th>Status</th><th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($owners as $o): ?>
          <tr>
            <td><input type="checkbox" name="selected_owners[]" value="<?= $o['id'] ?>" class="owner-checkbox"></td>
            <td>
              <div class="flex-center">
                <?php if ($o['profile_photo']): ?>
                  <img src="<?= base_url('uploads/'.$o['profile_photo']) ?>" class="avatar-sm">
                <?php else: ?>
                  <div class="avatar-initials" style="font-size:12px;width:32px;height:32px">
                    <?= strtoupper(substr($o['first_name'],0,1).substr($o['last_name'],0,1)) ?>
                  </div>
                <?php endif; ?>
                <?= sanitize($o['first_name'].' '.$o['last_name']) ?>
              </div>
            </td>
            <td><?= sanitize($o['email']) ?></td>
            <td><?= sanitize($o['phone'] ?? '—') ?></td>
            <td><?= $o['house_count'] ?></td>
            <td><?= $o['unit_count'] ?></td>
            <td><?= formatDate($o['created_at']) ?></td>
            <td>
              <?php if (!$o['is_active']): ?>
                <span class="badge badge-cancelled">Deleted</span>
              <?php elseif ($o['is_deactivated']): ?>
                <span class="badge badge-pending">Deactivated</span>
              <?php elseif (!$o['email_verified_at']): ?>
                <span class="badge badge-warning">Unverified</span>
              <?php else: ?>
                <span class="badge badge-accepted">Active</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this owner?')">
                <input type="hidden" name="action" value="delete_owner">
                <input type="hidden" name="owner_id" value="<?= $o['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="Delete owner">
                  <i class="fa fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$owners): ?>
            <tr><td colspan="9" class="text-center text-muted" style="padding:40px">No owners registered yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      </form>
    </div>
  </div>
</div>

<!-- System Admins Tab -->
<div class="tab-pane" id="tab-sysadmins">
  <div class="card">
    <div class="card-header">
      <span><?= count($sysadmins) ?> System Admin(s)</span>
    </div>
    <div class="table-wrap">
      <table id="sysadminTable">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Phone</th><th>Joined</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($sysadmins as $s): ?>
          <tr>
            <td>
              <div class="flex-center">
                <?php if ($s['profile_photo']): ?>
                  <img src="<?= base_url('uploads/'.$s['profile_photo']) ?>" class="avatar-sm">
                <?php else: ?>
                  <div class="avatar-initials" style="font-size:12px;width:32px;height:32px">
                    <?= strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1)) ?>
                  </div>
                <?php endif; ?>
                <?= sanitize($s['first_name'].' '.$s['last_name']) ?>
                <?php if ($s['id'] === currentUser()['id']): ?>
                  <span class="badge badge-accepted" style="margin-left:6px">You</span>
                <?php endif; ?>
              </div>
            </td>
            <td><?= sanitize($s['email']) ?></td>
            <td><?= sanitize($s['phone'] ?? '—') ?></td>
            <td><?= formatDate($s['created_at']) ?></td>
            <td>
              <?php if (!$s['is_active']): ?>
                <span class="badge badge-cancelled">Deleted</span>
              <?php elseif ($s['is_deactivated']): ?>
                <span class="badge badge-pending">Deactivated</span>
              <?php elseif (!$s['email_verified_at']): ?>
                <span class="badge badge-warning">Unverified</span>
              <?php else: ?>
                <span class="badge badge-accepted">Active</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$sysadmins): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:40px">No other system admins.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>


<div class="tab-pane" id="tab-guests">
  <div class="card">
    <div class="card-header">
      <span><?= count($guests) ?> Guest(s)</span>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Joined</th>
            <th>Status</th>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($guests as $g): ?>
          <tr>
            <td>
              <div class="flex-center">
                <?php if ($g['profile_photo']): ?>
                  <img src="<?= base_url('uploads/'.$g['profile_photo']) ?>" class="avatar-sm">
                <?php else: ?>
                  <div class="avatar-initials" style="font-size:12px;width:32px;height:32px">
                    <?= strtoupper(substr($g['first_name'],0,1).substr($g['last_name'],0,1)) ?>
                  </div>
                <?php endif; ?>
                <?= sanitize($g['first_name'].' '.$g['last_name']) ?>
              </div>
            </td>

            <td><?= sanitize($g['email']) ?></td>
            <td><?= sanitize($g['phone'] ?? '—') ?></td>
            <td><?= formatDate($g['created_at']) ?></td>

            <td>
              <?php if (!$g['is_active']): ?>
                <span class="badge badge-cancelled">Deleted</span>
              <?php elseif ($g['is_deactivated']): ?>
                <span class="badge badge-pending">Deactivated</span>
              <?php elseif (!$g['email_verified_at']): ?>
                <span class="badge badge-warning">Unverified</span>
              <?php else: ?>
                <span class="badge badge-accepted">Active</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>

          <?php if (!$guests): ?>
            <tr>
              <td colspan="5" class="text-center text-muted" style="padding:40px">
                No guests found.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>


</div>
<script>
document.addEventListener('DOMContentLoaded', () => filterTable('searchInput', 'ownersTable'));
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {

    // remove active class from all buttons
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));

    // hide all tab panes
    document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');

    // activate clicked button
    btn.classList.add('active');

    // show target tab pane
    const target = document.getElementById(btn.dataset.tab);
    if (target) {
      target.style.display = 'block';
    }
  });
});

// Checkbox functionality
document.addEventListener('DOMContentLoaded', () => {
  const masterCheckbox = document.getElementById('masterCheckbox');
  const ownerCheckboxes = document.querySelectorAll('.owner-checkbox');
  const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
  const selectAllBtn = document.getElementById('selectAllBtn');

  // Master checkbox functionality
  masterCheckbox.addEventListener('change', () => {
    ownerCheckboxes.forEach(cb => cb.checked = masterCheckbox.checked);
    updateBulkDeleteButton();
  });

  // Individual checkboxes
  ownerCheckboxes.forEach(cb => {
    cb.addEventListener('change', updateBulkDeleteButton);
  });

  // Select All button
  selectAllBtn.addEventListener('click', () => {
    const allChecked = Array.from(ownerCheckboxes).every(cb => cb.checked);
    ownerCheckboxes.forEach(cb => cb.checked = !allChecked);
    masterCheckbox.checked = !allChecked;
    updateBulkDeleteButton();
  });

  function updateBulkDeleteButton() {
    const checkedBoxes = document.querySelectorAll('.owner-checkbox:checked');
    bulkDeleteBtn.disabled = checkedBoxes.length === 0;
    bulkDeleteBtn.textContent = checkedBoxes.length > 0 ? `Delete Selected (${checkedBoxes.length})` : 'Delete Selected';
  }
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
