<?php
// modules/sysadmin/backup.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/backup_handler.php';
requireRole('sysadmin');

$db   = getDB();
$user = currentUser();

// ── CREATE backup_logs TABLE IF NOT EXISTS ──
$db->exec("CREATE TABLE IF NOT EXISTS backup_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    filename   VARCHAR(255) NOT NULL,
    size_mb    DECIMAL(8,2) DEFAULT 0,
    created_by INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── HANDLE DOWNLOAD ──
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    if (!preg_match('/^backup_[\d_-]+\.zip$/', $filename)) {
        die('Invalid file.');
    }
    $filepath = __DIR__ . '/../../backups/' . $filename;
    if (!file_exists($filepath)) {
        die('File not found.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ── HANDLE DELETE ──
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $filename = basename($_GET['delete']);
    if (preg_match('/^backup_[\d_-]+\.zip$/', $filename)) {
        $filepath = __DIR__ . '/../../backups/' . $filename;
        if (file_exists($filepath)) @unlink($filepath);
        $db->prepare("DELETE FROM backup_logs WHERE filename=?")->execute([$filename]);
        flash('success', 'Backup deleted.');
    }
    redirect('modules/sysadmin/backup.php');
}

// ── HANDLE RUN BACKUP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_backup'])) {
    $result = runBackup();
    if ($result['success']) {
        flash('success', "Backup created: <strong>{$result['filename']}</strong> ({$result['size']} MB)");
        // Auto-download
        redirect("modules/sysadmin/backup.php?download=" . urlencode($result['filename']));
    } else {
        flash('error', 'Backup failed: ' . $result['message']);
        redirect('modules/sysadmin/backup.php');
    }
}

// ── LOAD LOGS ──
$logs = $db->query("
    SELECT bl.*, u.first_name, u.last_name
    FROM backup_logs bl
    LEFT JOIN users u ON bl.created_by = u.id
    ORDER BY bl.created_at DESC
")->fetchAll();

$backupDir   = __DIR__ . '/../../backups';
$backupCount = count(glob($backupDir . '/*.zip') ?: []);
$totalSizeMB = 0;
foreach (glob($backupDir . '/*.zip') ?: [] as $f) {
    $totalSizeMB += filesize($f);
}
$totalSizeMB = round($totalSizeMB / 1024 / 1024, 2);

$pageTitle  = 'Backup & Maintenance';
$activePage = 'backup';
include __DIR__ . '/../../includes/header.php';
?>

<div class="container">
  <div class="page-header mt-3">
    <h1>Backup &amp; Maintenance</h1>
    <p>Create and manage system backups. Run a backup at the end of every shift.</p>
  </div>

  <?php
  $f = $_SESSION['flash'] ?? null;
  if ($f) { unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= $f['type'] === 'success' ? 'success' : 'danger' ?>">
      <i class="fa fa-<?= $f['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
      <?= $f['message'] ?>
    </div>
  <?php } ?>

  <!-- Stats row -->
  <div class="stats-grid" style="margin-bottom:24px">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa fa-archive"></i></div>
      <div>
        <div class="stat-value"><?= $backupCount ?></div>
        <div class="stat-label">Total Backups</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa fa-hdd"></i></div>
      <div>
        <div class="stat-value"><?= $totalSizeMB ?> MB</div>
        <div class="stat-label">Storage Used</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fa fa-clock"></i></div>
      <div>
        <div class="stat-value"><?= $logs ? date('M d, Y', strtotime($logs[0]['created_at'])) : 'Never' ?></div>
        <div class="stat-label">Last Backup</div>
      </div>
    </div>
  </div>

  <!-- Backup trigger card -->
  <div class="card mb-3">
    <div class="card-header">
      <span><i class="fa fa-database" style="margin-right:6px"></i> Run Backup</span>
    </div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
      <div>
        <div style="font-weight:600;margin-bottom:4px">Full System Backup</div>
        <div class="text-muted fs-sm">
          Includes: full database dump + all uploaded files.<br>
          File will download automatically and be saved to the server.<br>
          Old backups (30+ days) are removed automatically.
        </div>
      </div>
      <form method="POST" onsubmit="return confirmBackup()">
        <input type="hidden" name="run_backup" value="1">
        <button type="submit" class="btn btn-primary" id="backupBtn" style="min-width:160px">
          <i class="fa fa-download"></i> Run Backup Now
        </button>
      </form>
    </div>
  </div>

  <!-- Backup log -->
  <div class="card">
    <div class="card-header">
      <span><i class="fa fa-history" style="margin-right:6px"></i> Backup History</span>
      <span class="text-muted fs-sm">Last 30 days kept automatically</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Filename</th>
            <th>Size</th>
            <th>Created By</th>
            <th>Date &amp; Time</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($logs): foreach ($logs as $log):
            $fileExists = file_exists($backupDir . '/' . $log['filename']);
          ?>
          <tr>
            <td>
              <i class="fa fa-file-archive" style="color:var(--accent);margin-right:6px"></i>
              <span style="font-family:monospace;font-size:13px"><?= sanitize($log['filename']) ?></span>
              <?php if (!$fileExists): ?>
                <span class="badge badge-cancelled" style="margin-left:6px">File Missing</span>
              <?php endif; ?>
            </td>
            <td><?= $log['size_mb'] ?> MB</td>
            <td>
              <?= $log['first_name'] ? sanitize($log['first_name'] . ' ' . $log['last_name']) : 'System' ?>
            </td>
            <td><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
            <td style="text-align:right">
              <div style="display:flex;gap:8px;justify-content:flex-end">
                <?php if ($fileExists): ?>
                  <a href="?download=<?= urlencode($log['filename']) ?>"
                     class="btn btn-outline btn-sm">
                    <i class="fa fa-download"></i> Download
                  </a>
                <?php endif; ?>
                <form method="POST" action="?delete=<?= urlencode($log['filename']) ?>"
                      onsubmit="return confirm('Delete this backup? This cannot be undone.')">
                  <button type="submit" class="btn btn-sm"
                          style="background:#fdedec;color:var(--danger);border:1px solid #f5c6cb">
                    <i class="fa fa-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr>
            <td colspan="5" class="text-center text-muted" style="padding:40px">
              <i class="fa fa-archive" style="font-size:32px;margin-bottom:12px;display:block;opacity:.3"></i>
              No backups yet. Run your first backup above.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function confirmBackup() {
  const btn = document.getElementById('backupBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running backup...';
  return confirm('Run a full system backup now?\n\nThis will dump the database and zip all uploaded files. The file will download automatically.');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>    