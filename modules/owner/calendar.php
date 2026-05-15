<?php
// modules/owner/calendar.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('owner','admin');

$db   = getDB();
$user = currentUser();
$ownerId = $user['id'];
$assignedHouseId = null;

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
}

// Get all units for this owner (or just the assigned house for admins)
$units = $db->prepare("
    SELECT tu.id, tu.name, th.name as house_name
    FROM transient_units tu
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE th.owner_id=?" . ($assignedHouseId ? " AND th.id=?" : "") . " AND tu.is_active=1
    ORDER BY th.name, tu.name
");

if ($assignedHouseId) {
    $units->execute([$ownerId, $assignedHouseId]);
} else {
    $units->execute([$ownerId]);
}
$units = $units->fetchAll();

$selectedUnit = intval($_GET['unit_id'] ?? ($units[0]['id'] ?? 0));
$month = intval($_GET['month'] ?? date('n'));
$year  = intval($_GET['year']  ?? date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12){ $month = 1;  $year++; }

// Verify admin access to selected unit
if ($selectedUnit && $assignedHouseId) {
    $unitChk = $db->prepare("
        SELECT tu.id FROM transient_units tu
        JOIN transient_houses th ON tu.house_id=th.id
        WHERE tu.id=? AND th.id=? AND th.owner_id=?
    ");
    $unitChk->execute([$selectedUnit, $assignedHouseId, $ownerId]);
    if (!$unitChk->fetch()) {
        // Admin trying to access a unit from a different house
        if ($units) {
            redirect('modules/admin/calendar.php?unit_id='.$units[0]['id']);
        } else {
            redirect('modules/admin/calendar.php');
        }
    }
}

// Get calendar data for selected unit and month
$calData     = [];
$calNotes    = [];
$calBookings = [];

if ($selectedUnit) {
    $stmt = $db->prepare("SELECT date, status, note FROM unit_calendar WHERE unit_id=? AND MONTH(date)=? AND YEAR(date)=?");
    $stmt->execute([$selectedUnit, $month, $year]);
    foreach ($stmt->fetchAll() as $row) {
        $calData[$row['date']]  = $row['status'];
        $calNotes[$row['date']] = $row['note'];
    }

    // Get bookings for this unit and month
    $bStmt = $db->prepare("
        SELECT b.check_in, b.check_out, b.booking_code, b.num_guests,
               u.first_name, u.last_name
        FROM bookings b
        JOIN users u ON b.guest_id=u.id
        WHERE b.unit_id=? AND b.status IN ('accepted','completed')
        AND (
            (b.check_in <= LAST_DAY(?) AND b.check_out >= ?)
        )
    ");
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $bStmt->execute([$selectedUnit, $monthStart, $monthStart]);
    foreach ($bStmt->fetchAll() as $booking) {
        $ci       = new DateTime($booking['check_in']);
        $co       = new DateTime($booking['check_out']);
        // Exclude checkout day so same-day check-in after checkout remains available.
        $interval = new DateInterval('P1D');
        $period   = new DatePeriod($ci, $interval, $co);
        foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $calBookings[$d] = [
                'name'   => $booking['first_name'].' '.$booking['last_name'],
                'code'   => $booking['booking_code'],
                'in'     => $booking['check_in'],
                'out'    => $booking['check_out'],
                'guests' => $booking['num_guests'],
            ];
        }
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitId    = intval($_POST['unit_id'] ?? 0);
    $dates     = $_POST['dates'] ?? [];
    $newStatus = $_POST['new_status'] ?? 'Available';
    $note      = trim($_POST['note'] ?? '');

    // ── Updated to match new ENUM: Available | Unavailable | Booked ──
    $allowed = ['Available', 'Unavailable'];

    // Verify admin has access to this unit
    if ($unitId && $assignedHouseId) {
        $unitChk = $db->prepare("
            SELECT tu.id FROM transient_units tu
            JOIN transient_houses th ON tu.house_id=th.id
            WHERE tu.id=? AND th.id=? AND th.owner_id=?
        ");
        $unitChk->execute([$unitId, $assignedHouseId, $ownerId]);
        if (!$unitChk->fetch()) {
            flash('error', 'You do not have access to this unit.');
            redirect('modules/admin/calendar.php');
        }
    }

    if ($unitId && $dates && in_array($newStatus, $allowed)) {
        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            // Don't override Booked dates
            $chk = $db->prepare("SELECT status FROM unit_calendar WHERE unit_id=? AND date=?");
            $chk->execute([$unitId, $date]);
            $existing = $chk->fetchColumn();
            if ($existing === 'Booked') continue;
            $db->prepare("INSERT INTO unit_calendar (unit_id,date,status,note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=?,note=?")
               ->execute([$unitId, $date, $newStatus, $note, $newStatus, $note]);
        }
        flash('success', 'Calendar updated successfully.');
    }
    redirect("modules/{$user['role']}/calendar.php?unit_id={$unitId}&month={$month}&year={$year}");
}

$baseModule = $user['role']==='admin' ? 'admin' : 'owner';
$pageTitle  = 'Calendar';
$activePage = 'calendar';
include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Legend & Calendar Cell Colors (solid, ENUM-matched) ── */

/* Available — green */
.cal-cell.status-available {
    background: #d4edda;
    color: #155724;
    border: 2px solid #28a745;
}

/* Booked — red */
.cal-cell.status-booked {
    background: #f8d7da;
    color: #721c24;
    border: 2px solid #dc3545;
    cursor: default;
}

/* Unavailable — amber/orange (covers old maintenance + blocked) */
.cal-cell.status-unavailable {
    background: #fff3cd;
    color: #856404;
    border: 2px solid #ffc107;
}

/* Selected — accent */
.cal-cell.selected {
    background: #cce5ff;
    color: #004085;
    border: 2px solid #0069d9;
}

/* Legend swatch */
.legend-swatch {
    width: 20px;
    height: 20px;
    display: inline-block;
    border-radius: 4px;
    margin-right: 8px;
    vertical-align: middle;
    flex-shrink: 0;
}
.legend-swatch.status-available   { background:#d4edda; border:2px solid #28a745; }
.legend-swatch.status-booked      { background:#f8d7da; border:2px solid #dc3545; }
.legend-swatch.status-unavailable { background:#fff3cd; border:2px solid #ffc107; }
.legend-swatch.selected           { background:#cce5ff; border:2px solid #0069d9; }
</style>

<div class="container">
  <div class="page-header mt-3">
    <h1>Unit Calendar</h1>
    <p><?php 
      if ($assignedHouseId) {
        echo 'Manage availability for your assigned property';
      } else {
        echo 'Set and manage unit availability by date';
      }
    ?></p>
  </div>

  <div class="row">
    <!-- Sidebar: unit selector -->
    <div class="col-fixed-300">
      <div class="card mb-3">
        <div class="card-header">Select Unit</div>
        <div class="card-body" style="padding:0">
          <?php foreach ($units as $u): ?>
            <a href="?unit_id=<?= $u['id'] ?>&month=<?= $month ?>&year=<?= $year ?>"
               style="display:block;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);<?= $selectedUnit==$u['id']?'background:var(--bg);font-weight:700;border-left:3px solid var(--accent)':'' ?>">
              <div style="font-size:14px"><?= sanitize($u['name']) ?></div>
              <div class="text-muted fs-sm"><?= sanitize($u['house_name']) ?></div>
            </a>
          <?php endforeach; ?>
          <?php if (!$units): ?>
            <div class="p-1 text-muted fs-sm">No units found.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Legend — matches new ENUM: Available | Booked | Unavailable -->
      <div class="flex-center" style="margin:8px 0">
        <span class="legend-swatch status-available"></span>
        <span style="font-size:13px">Available</span>
      </div>
      <div class="flex-center" style="margin:8px 0">
        <span class="legend-swatch status-booked"></span>
        <span style="font-size:13px">Booked</span>
      </div>
      <div class="flex-center" style="margin:8px 0">
        <span class="legend-swatch status-unavailable"></span>
        <span style="font-size:13px">Unavailable</span>
      </div>
    </div>

    <!-- Calendar -->
    <div class="col">
      <?php if ($selectedUnit): ?>
      <div class="card">
        <div class="card-header">
          <div class="cal-nav" style="width:100%">
            <a href="?unit_id=<?= $selectedUnit ?>&month=<?= $month-1 ?>&year=<?= $year ?>" class="btn btn-outline btn-sm"><i class="fa fa-chevron-left"></i></a>
            <h3><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></h3>
            <a href="?unit_id=<?= $selectedUnit ?>&month=<?= $month+1 ?>&year=<?= $year ?>" class="btn btn-outline btn-sm"><i class="fa fa-chevron-right"></i></a>
          </div>
        </div>
        <div class="card-body">
          <div class="cal-grid" style="margin-bottom:16px">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
              <div class="cal-header"><?= $d ?></div>
            <?php endforeach; ?>
            <?php
            $firstDay    = date('w', mktime(0,0,0,$month,1,$year));
            $daysInMonth = date('t', mktime(0,0,0,$month,1,$year));
            $today       = date('Y-m-d');

            for ($i = 0; $i < $firstDay; $i++) echo '<div></div>';

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);

                // ── Resolve status: unit_calendar wins; fall back to bookings table ──
                // This ensures checkout date is marked Booked even if unit_calendar
                // has no row for it — $calBookings already includes checkout day
                // because of the $co->modify('+1 day') applied during fetch.
                if (isset($calData[$dateStr])) {
                    $status = $calData[$dateStr];
                } elseif (isset($calBookings[$dateStr])) {
                    $status = 'Booked';
                } else {
                    $status = 'Available';
                }

                $cssMap = [
                    'Available'   => 'status-available',
                    'Booked'      => 'status-booked',
                    'Unavailable' => 'status-unavailable',
                ];
                $cls     = $cssMap[$status] ?? 'status-available';
                $isToday = ($dateStr === $today);
                $isPast  = ($dateStr < $today);

                // ── Build data-info for tooltip card ──
                $dataInfo = '';
                if ($status === 'Booked' && isset($calBookings[$dateStr])) {
                    $b = $calBookings[$dateStr];
                    $dataInfo = "data-info='" . htmlspecialchars(json_encode([
                        'type'   => 'Booked',
                        'name'   => $b['name'],
                        'code'   => $b['code'],
                        'in'     => $b['in'],
                        'out'    => $b['out'],
                        'guests' => $b['guests'],
                    ]), ENT_QUOTES) . "'";
                } elseif ($status === 'Unavailable') {
                    $dataInfo = "data-info='" . htmlspecialchars(json_encode([
                        'type' => 'Unavailable',
                        'note' => $calNotes[$dateStr] ?? '',
                    ]), ENT_QUOTES) . "'";
                }

                // ── Interaction rules ──
                // Past dates and Booked dates are not selectable
                $clickHandler = ($isPast || $status === 'Booked') ? '' : "onclick='toggleDate(this)'";
                $pastStyle    = $isPast ? "style=\"opacity:.45;cursor:not-allowed;\"" : "";

                echo "<div class='cal-cell {$cls}" . ($isToday ? ' today' : '') . "' "
                    . "data-date='{$dateStr}' {$clickHandler} {$pastStyle} {$dataInfo}>{$d}</div>";
            }
            ?>
          </div>

          <!-- Apply Changes -->
          <form method="POST" id="calForm">
            <input type="hidden" name="unit_id" value="<?= $selectedUnit ?>">
            <div id="selectedDatesContainer"></div>
            <div class="form-row">
              <div class="form-group">
                <label>Set Status</label>
                <select name="new_status">
                  <!-- Options match new ENUM exactly (owners cannot manually set Booked) -->
                  <option value="Available">Available</option>
                  <option value="Unavailable">Unavailable</option>
                </select>
              </div>
              <div class="form-group">
                <label>Note (optional)</label>
                <input type="text" name="note" placeholder="e.g. Deep cleaning, maintenance visit…">
              </div>
            </div>
            <div class="flex-center gap-2">
              <button type="submit" class="btn btn-primary" id="applyBtn" disabled>
                <i class="fa fa-check"></i> Apply Changes
              </button>
              <button type="button" class="btn btn-outline" onclick="clearSelection()">Clear Selection</button>
              <span id="selectedCount" class="text-muted fs-sm">0 dates selected</span>
            </div>
          </form>
        </div>
      </div>
      <?php else: ?>
        <div class="empty-state">
          <i class="fa fa-calendar"></i>
          <h3>Select a unit</h3>
          <p>Choose a unit from the left to manage its calendar.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// ── TOOLTIP CARD ──────────────────────────────────────────────────────────────
const tooltipCard = document.createElement('div');
tooltipCard.style.cssText = `
    display:none;position:fixed;z-index:99999;pointer-events:none;
    background:#fff;border:1px solid #dde1e7;border-radius:10px;
    box-shadow:0 4px 20px rgba(0,0,0,.15);padding:14px 16px;
    min-width:210px;font-size:13px;line-height:1.9;
`;
document.body.appendChild(tooltipCard);

const calGrid = document.querySelector('.cal-grid');
if (calGrid) {
    calGrid.addEventListener('mouseover', function(e) {
        const cell = e.target.closest('.cal-cell[data-info]');
        if (!cell) { tooltipCard.style.display = 'none'; return; }

        let info;
        try { info = JSON.parse(cell.getAttribute('data-info')); }
        catch(err) { return; }

        let html = '';

        if (info.type === 'Booked') {
            // ── Guest booking info ──
            html = `
                <div style="font-weight:700;color:#721c24;margin-bottom:6px;font-size:14px">
                    <i class="fa fa-calendar-check" style="color:#dc3545"></i>&nbsp;Booking Info
                </div>
                <div style="color:#555"><i class="fa fa-user" style="width:18px;color:#888"></i> ${escHtml(info.name)}</div>
                <div style="color:#555"><i class="fa fa-tag" style="width:18px;color:#888"></i> ${escHtml(info.code)}</div>
                <div style="color:#555"><i class="fa fa-sign-in-alt" style="width:18px;color:#888"></i> Check-in: ${escHtml(info.in)}</div>
                <div style="color:#555"><i class="fa fa-sign-out-alt" style="width:18px;color:#888"></i> Check-out: ${escHtml(info.out)}</div>
                <div style="color:#555"><i class="fa fa-users" style="width:18px;color:#888"></i> ${escHtml(String(info.guests))} guest(s)</div>
            `;
        } else if (info.type === 'Unavailable') {
            // ── Unavailable (former maintenance + blocked) ──
            html = `
                <div style="font-weight:700;color:#856404;margin-bottom:6px;font-size:14px">
                    <i class="fa fa-ban" style="color:#ffc107"></i>&nbsp;Unavailable
                </div>
                ${info.note
                    ? `<div style="color:#555"><i class="fa fa-sticky-note" style="width:18px;color:#888"></i> ${escHtml(info.note)}</div>`
                    : '<div style="color:#999;font-size:12px">No note added.</div>'
                }
            `;
        }

        if (!html) { tooltipCard.style.display = 'none'; return; }
        tooltipCard.innerHTML = html;
        tooltipCard.style.display = 'block';
    });

    calGrid.addEventListener('mousemove', function(e) {
        if (tooltipCard.style.display === 'none') return;
        const x = e.clientX + 16;
        const y = e.clientY - tooltipCard.offsetHeight - 12;
        tooltipCard.style.left = Math.min(x, window.innerWidth - 240) + 'px';
        tooltipCard.style.top  = Math.max(10, y) + 'px';
    });

    calGrid.addEventListener('mouseout', function(e) {
        if (!e.relatedTarget || !e.relatedTarget.closest('.cal-cell[data-info]')) {
            tooltipCard.style.display = 'none';
        }
    });
}

// ── SAFE HTML ESCAPE FOR TOOLTIP CONTENT ─────────────────────────────────────
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── DATE SELECTION ────────────────────────────────────────────────────────────
let selectedDates = new Set();

function toggleDate(el) {
    // Guard: Booked and past dates are not selectable
    if (el.classList.contains('status-booked')) return;
    const today = new Date().toISOString().split('T')[0];
    if (el.dataset.date < today) return;

    const date = el.dataset.date;
    if (selectedDates.has(date)) {
        selectedDates.delete(date);
        el.classList.remove('selected');
    } else {
        selectedDates.add(date);
        el.classList.add('selected');
    }
    updateSelection();
}

function updateSelection() {
    const container = document.getElementById('selectedDatesContainer');
    const countEl   = document.getElementById('selectedCount');
    const applyBtn  = document.getElementById('applyBtn');
    container.innerHTML = '';
    selectedDates.forEach(d => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'dates[]';
        input.value = d;
        container.appendChild(input);
    });
    countEl.textContent = selectedDates.size + ' date(s) selected';
    applyBtn.disabled = selectedDates.size === 0;
}

function clearSelection() {
    selectedDates.clear();
    document.querySelectorAll('.cal-cell.selected').forEach(el => el.classList.remove('selected'));
    updateSelection();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>