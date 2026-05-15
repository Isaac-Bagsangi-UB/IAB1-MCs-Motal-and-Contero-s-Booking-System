<?php
// 403.php
require_once __DIR__ . '/config/app.php';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>403 Forbidden | MCTBS</title>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:32px">
  <div>
    <div style="font-size:80px">🚫</div>
    <h1 style="font-size:64px;font-weight:800;color:var(--primary);margin:0">403</h1>
    <h2 style="font-size:22px;margin:12px 0 8px">Access Denied</h2>
    <p style="color:var(--text-muted);margin-bottom:24px">You don't have permission to access this page.</p>
    <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Go Home</a>
  </div>
</div>
</body>
</html>
