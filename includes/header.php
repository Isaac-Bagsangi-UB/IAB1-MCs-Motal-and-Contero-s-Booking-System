<?php
// includes/header.php
// Expects: $pageTitle (string), $activePage (string), optional $role override
if (!isset($pageTitle)) $pageTitle = 'MCTBS';
$user = currentUser();
$unreadCount = 0;
if ($user) {
    require_once __DIR__ . '/notifications.php';
    $unreadCount = getUnreadCount($user['id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= sanitize($pageTitle) ?> | MCTBS</title>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<nav class="topnav">
  <div class="topnav-brand">
    <a href="<?= base_url($user ? ($user['role'] === 'sysadmin' ? 'modules/sysadmin/dashboard.php' : ($user['role'] === 'owner' ? 'modules/owner/dashboard.php' : ($user['role'] === 'admin' ? 'modules/admin/dashboard.php' : 'modules/guest/dashboard.php'))) : 'modules/public/home.php') ?>">
      <span class="brand-icon"></span> MCTBS
    </a>
  </div>

  <div class="topnav-center">
    <?php if ($user): ?>
      <?php if ($user['role'] === 'sysadmin'): ?>
        <a href="<?= base_url('modules/sysadmin/dashboard.php') ?>" class="<?= ($activePage??'')==='dashboard'?'active':'' ?>"><i class="fa fa-gauge"></i> Dashboard</a>
        <a href="<?= base_url('modules/sysadmin/owners.php') ?>" class="<?= ($activePage??'')==='owners'?'active':'' ?>"><i class="fa fa-users"></i> Owner Lists</a>

      <?php elseif ($user['role'] === 'owner'): ?>
        <a href="<?= base_url('modules/owner/dashboard.php') ?>" class="<?= ($activePage??'')==='dashboard'?'active':'' ?>"><i class="fa fa-gauge"></i> Dashboard</a>
        <div class="nav-dropdown">
          <a href="javascript:void(0)" class="<?= in_array($activePage??'',['houses','units'])?'active':'' ?>"><i class="fa fa-building"></i> Properties <i class="fa fa-chevron-down fa-xs"></i></a>
          <div class="dropdown-menu">
            <a href="<?= base_url('modules/owner/houses.php') ?>">Transient Houses</a>
            <a href="<?= base_url('modules/owner/units.php') ?>">Transient Units</a>
          </div>
        </div>
        <a href="<?= base_url('modules/owner/bookings.php') ?>" class="<?= ($activePage??'')==='bookings'?'active':'' ?>"><i class="fa fa-calendar-check"></i> Bookings</a>
        <a href="<?= base_url('modules/owner/calendar.php') ?>" class="<?= ($activePage??'')==='calendar'?'active':'' ?>"><i class="fa fa-calendar"></i> Calendar</a>
        <a href="<?= base_url('modules/owner/reports.php') ?>" class="<?= ($activePage??'')==='reports'?'active':'' ?>"><i class="fa fa-chart-bar"></i> Reports</a>
        <a href="<?= base_url('modules/owner/admins.php') ?>" class="<?= ($activePage??'')==='admins'?'active':'' ?>"><i class="fa fa-user-shield"></i> Staff</a>

      <?php elseif ($user['role'] === 'admin'): ?>
        <a href="<?= base_url('modules/admin/dashboard.php') ?>" class="<?= ($activePage??'')==='dashboard'?'active':'' ?>"><i class="fa fa-gauge"></i> Dashboard</a>
        <a href="<?= base_url('modules/admin/units.php') ?>" class="<?= ($activePage??'')==='units'?'active':'' ?>"><i class="fa fa-door-open"></i> Transient Units</a>
        <a href="<?= base_url('modules/admin/bookings.php') ?>" class="<?= ($activePage??'')==='bookings'?'active':'' ?>"><i class="fa fa-calendar-check"></i> Bookings</a>
        <a href="<?= base_url('modules/admin/calendar.php') ?>"><i class="fa fa-calendar"></i> Calendar</a>

      <?php elseif ($user['role'] === 'guest'): ?>
        <a href="<?= base_url('modules/public/home.php') ?>" class="<?= ($activePage??'')==='home'?'active':'' ?>"><i class="fa fa-home"></i> Home</a>
        <a href="<?= base_url('modules/public/transient.php') ?>" class="<?= ($activePage??'')==='transients'?'active':'' ?>"><i class="fa fa-search"></i> Transients</a>
        <a href="<?= base_url('modules/guest/dashboard.php') ?>" class="<?= ($activePage??'')==='dashboard'?'active':'' ?>"><i class="fa fa-calendar"></i> Personal Booking</a>
      <?php endif; ?>
    <?php else: ?>
      <a href="<?= base_url('modules/public/home.php') ?>" class="<?= ($activePage??'')==='home'?'active':'' ?>"><i class="fa fa-home"></i> Home</a>
      <a href="<?= base_url('modules/public/transient.php') ?>" class="<?= ($activePage??'')==='transients'?'active':'' ?>"><i class="fa fa-search"></i> Transients</a>
    <?php endif; ?>
  </div>

  <div class="topnav-right">
    <?php if ($user): ?>
      <!-- Notifications -->
      <div class="notif-wrapper" id="notifWrapper">
        <button class="notif-btn" id="notifToggle" onclick="toggleNotifs()">
          <i class="fa fa-bell"></i>
          <?php if ($unreadCount > 0): ?>
            <span class="notif-badge"><?= $unreadCount ?></span>
          <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">
            <span>Notifications</span>
            <a href="<?= base_url('includes/mark_read.php') ?>" class="mark-all-read">Mark all read</a>
          </div>
          <div class="notif-list" id="notifList">
            <p class="notif-loading">Loading...</p>
          </div>
        </div>
      </div>
      <!-- Profile -->
      <div class="nav-dropdown profile-dropdown">
        <button class="profile-btn" type="button" id="profileBtn">
          <?php if ($user['profile_photo']): ?>
            <img src="<?= base_url('uploads/' . $user['profile_photo']) ?>" alt="Avatar" class="avatar-sm">
          <?php else: ?>
            <span class="avatar-initials"><?= strtoupper(substr($user['first_name'],0,1) . substr($user['last_name'],0,1)) ?></span>
          <?php endif; ?>
          <span><?= sanitize($user['first_name']) ?></span>
          <i class="fa fa-chevron-down fa-xs"></i>
        </button>
        <div class="dropdown-menu dropdown-menu-right">
          <a href="<?= base_url('modules/auth/profile.php') ?>"><i class="fa fa-user"></i> Account Information</a>
          <a href="<?= base_url('modules/auth/account.php') ?>"><i class="fa fa-shield"></i> Session & Account</a>
          <hr>
          <a href="<?= base_url('modules/auth/logout.php') ?>" class="text-danger"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
      </div>
    <?php else: ?>
    <?php endif; ?>
  </div>
</nav>

<?php
// PROTOTYPE: Show email links instead of sending real email
if (!empty($_SESSION['dev_mail'])) {
    foreach ($_SESSION['dev_mail'] as $devMail) {
        echo '<div class="alert alert-info" style="flex-direction:column;align-items:flex-start;gap:4px">';
        echo '<strong><i class="fa fa-envelope"></i> [Dev Mode] Email would be sent to: ' . sanitize($devMail['to']) . '</strong>';
        echo '<span style="font-size:13px">Subject: ' . sanitize($devMail['subject']) . '</span>';
        echo '<a href="' . htmlspecialchars_decode($devMail['link']) . '" style="font-size:13px;word-break:break-all;color:#1a0dab">' . $devMail['link'] . '</a>';
        echo '</div>';
    }
    $_SESSION['dev_mail'] = [];
}
$flash_success = flash('success');
$flash_error   = flash('error');
$flash_info    = flash('info');
?>
<?php if ($flash_error): ?>
  <div class="alert alert-danger"><?= sanitize($flash_error) ?></div>
<?php endif; ?>
<?php if ($flash_success): ?>
  <div class="alert alert-success"><?= sanitize($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_info): ?>
  <div class="alert alert-info"><?= sanitize($flash_info) ?></div>
<?php endif; ?>

<div class="page-wrapper">
