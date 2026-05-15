<?php
// includes/ajax_search_owners.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
requireRole('sysadmin');

$search = strtolower(trim($_GET['q'] ?? ''));
$tab = trim($_GET['tab'] ?? 'owners');

$db = getDB();

// Fetch all owners
$owners = $db->query("
    SELECT u.*, COUNT(DISTINCT th.id) as house_count, COUNT(DISTINCT tu.id) as unit_count
    FROM users u
    LEFT JOIN transient_houses th ON th.owner_id=u.id
    LEFT JOIN transient_units tu ON tu.house_id=th.id
    WHERE u.role='owner'
    GROUP BY u.id ORDER BY u.created_at DESC
")->fetchAll();

// Fetch all sysadmins
$sysadmins = $db->query("
    SELECT * FROM users WHERE role='sysadmin' ORDER BY created_at ASC
")->fetchAll();

// Fetch all guests
$guests = $db->query("
    SELECT *
    FROM users
    WHERE role='guest'
    ORDER BY created_at DESC
")->fetchAll();

// Filter based on search term
$filtered = [];
if ($tab === 'owners') {
    foreach ($owners as $o) {
        if ($search === '' || 
            stripos($o['first_name'] . ' ' . $o['last_name'], $search) !== false ||
            stripos($o['email'], $search) !== false ||
            stripos($o['phone'] ?? '', $search) !== false) {
            $filtered[] = $o;
        }
    }
} elseif ($tab === 'sysadmins') {
    foreach ($sysadmins as $s) {
        if ($search === '' || 
            stripos($s['first_name'] . ' ' . $s['last_name'], $search) !== false ||
            stripos($s['email'], $search) !== false) {
            $filtered[] = $s;
        }
    }
} elseif ($tab === 'guests') {
    foreach ($guests as $g) {
        if ($search === '' || 
            stripos($g['first_name'] . ' ' . $g['last_name'], $search) !== false ||
            stripos($g['email'], $search) !== false) {
            $filtered[] = $g;
        }
    }
}

echo json_encode([
    'success' => true,
    'search' => $_GET['q'] ?? '',
    'tab' => $tab,
    'results' => $filtered,
    'count' => count($filtered),
    'total' => [
        'owners' => count($owners),
        'sysadmins' => count($sysadmins),
        'guests' => count($guests)
    ]
]);
