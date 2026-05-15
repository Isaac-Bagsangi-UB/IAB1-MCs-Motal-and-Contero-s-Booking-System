<?php
// modules/public/home.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

$db     = getDB();
$search = trim($_GET['q'] ?? '');

// Featured houses
$q = "SELECT th.*, u.first_name, u.last_name,
      COUNT(DISTINCT tu.id) as unit_count,
      MIN(tu.price_per_night) as min_price
      FROM transient_houses th
      JOIN users u ON th.owner_id=u.id
      LEFT JOIN transient_units tu ON tu.house_id=th.id AND tu.is_active=1
      WHERE th.is_active=1";
$params = [];
if ($search) {
    $q .= " AND (th.name LIKE ? OR th.city LIKE ? OR th.address LIKE ? OR th.barangay LIKE ?)";
    $s = "%{$search}%";
    $params = [$s,$s,$s,$s];
}
$q .= " GROUP BY th.id ORDER BY th.created_at DESC";
$stmt = $db->prepare($q);
$stmt->execute($params);
$houses = $stmt->fetchAll();

$pageTitle = 'Welcome';
$activePage = 'home';
$user = currentUser();
include __DIR__ . '/../../includes/header.php';
?>
<?php if (!$search): ?>
<!-- HERO -->

<style>
.btnn {
  display: flex; align-items: left; gap: 6px;
  padding: 9px 18px; border-radius: 7px; cursor: pointer;
  font-size: 14px; font-weight: 500; transition: all .15s; text-decoration: none;
  white-space: nowrap;
  margin-top: 20px;
  justify-content:flex-start;
}
.btnn:hover {
  text-decoration: none; opacity: .88;
}
.btn-explore {
  background: transparent;
  border: 1.5px solid darkgray;
  color: #fff;
}
.btn-explore:hover {
  background: var(--bg); 
  color: #000;
}
.btn-book {
  padding: 5px 14px; font-size: 13px; background: var(--accent); color: #fff; border-radius: 6px; border: none; 
}

</style>

<div class="hero hero-home">
  <h1>Your Home In The <br>City of Pines</h1>
  <p>Escape to the cool breeze of Baguio City with a cozy, comfortable stay made for <br>peaceful and memorable moments.</p>
  <div class="hero-actions" style="display:flex;justify-content:flex-start;gap:14px;flex-wrap:wrap;margin-top:90px ">
    <a href="<?= base_url('modules/auth/login.php') ?>" class="btn btn-book">Book you escape</a>
    <a href="<?= base_url('modules/public/transient.php') ?>" class="btn btn-explore">Explore Transient</a>
  </div>
</div>
<?php endif; ?>

</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
