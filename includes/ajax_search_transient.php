<?php
// includes/ajax_search_transient.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$search = trim($_GET['q'] ?? '');

$db = getDB();

// Build query
$q = "SELECT th.*, u.first_name, u.last_name,
      COUNT(DISTINCT tu.id) as unit_count,
      MIN(tu.price_per_night) as min_price
      FROM transient_houses th
      JOIN users u ON th.owner_id=u.id
      LEFT JOIN transient_units tu ON tu.house_id=th.id AND tu.is_active=1
      WHERE th.is_active=1";
$params = [];

if ($search) {
    $q .= " AND (th.name LIKE ? OR th.city LIKE ? OR th.address LIKE ? OR th.barangay LIKE ? OR th.description LIKE ?)";
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}

$q .= " GROUP BY th.id ORDER BY th.created_at DESC";
$stmt = $db->prepare($q);
$stmt->execute($params);
$houses = $stmt->fetchAll();

// Return JSON response
echo json_encode([
    'success' => true,
    'search' => $search,
    'houses' => $houses,
    'count' => count($houses),
    'hasFilters' => !empty($search)
]);
