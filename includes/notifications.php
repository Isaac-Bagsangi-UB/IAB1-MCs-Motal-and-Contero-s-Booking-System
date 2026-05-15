<?php
// includes/notifications.php
require_once __DIR__ . '/../config/db.php';

function createNotification($userId, $type, $title, $message, $link = null) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $type, $title, $message, $link]);
}

function getUnreadCount($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

function getRecentNotifications($userId, $limit = 10) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function markAllRead($userId) {
    $db = getDB();
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$userId]);
}

function markOneRead($notifId, $userId) {
    $db = getDB();
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$notifId, $userId]);
}

// Notify all admins + owner of a house about a new booking
function notifyOwnerAndAdmins($ownerId, $houseId, $type, $title, $message, $link = null) {
    $db = getDB();
    // Notify owner
    createNotification($ownerId, $type, $title, $message, $link);
    // Notify admins under this owner
    $stmt = $db->prepare("SELECT admin_id FROM owner_admins WHERE owner_id = ?");
    $stmt->execute([$ownerId]);
    foreach ($stmt->fetchAll() as $row) {
        createNotification($row['admin_id'], $type, $title, $message, $link);
    }
}
