<?php
// includes/ajax_filter_units.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$house_id = intval($_GET['house_id'] ?? 0);

$db = getDB();
$user = currentUser();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Determine base module
$baseModule = $user['role'] === 'admin' ? 'owner' : $user['role'];

// Get assigned house ID for admins
$assignedHouseId = null;
if ($user['role'] === 'admin') {
    $admin = $db->prepare("SELECT assigned_house_id FROM users WHERE id = ?")->execute([$user['id']])->fetch();
    $assignedHouseId = $admin ? $admin['assigned_house_id'] : null;
}

// Get houses
$myHouses = [];
if ($baseModule === 'owner') {
    $myHouses = $db->prepare("SELECT id, name FROM transient_houses WHERE owner_id = ? ORDER BY name ASC")
        ->execute([$user['id']])->fetchAll();
} elseif ($baseModule === 'owner' && $assignedHouseId) {
    $house = $db->prepare("SELECT id, name FROM transient_houses WHERE id = ? AND owner_id = ?")
        ->execute([$assignedHouseId, $user['id']])->fetch();
    if ($house) $myHouses = [$house];
}

// Get units based on filter
$q = "SELECT tu.*, th.name as house_name 
      FROM transient_units tu 
      JOIN transient_houses th ON tu.house_id = th.id";

if ($baseModule === 'owner' && !$assignedHouseId) {
    $q .= " WHERE th.owner_id = ?";
    $params = [$user['id']];
} elseif ($assignedHouseId) {
    $q .= " WHERE tu.house_id = ?";
    $params = [$assignedHouseId];
} else {
    $q .= " WHERE 1=0";
    $params = [];
}

if ($house_id) {
    $q .= " AND tu.house_id = ?";
    $params[] = $house_id;
}

$q .= " ORDER BY tu.created_at DESC";
$stmt = $db->prepare($q);
$stmt->execute($params);
$units = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'house_id' => $house_id,
    'units' => $units,
    'count' => count($units),
    'houses' => $myHouses
]);
