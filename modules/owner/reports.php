<?php
// modules/owner/reports.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
requireRole('owner');

$db   = getDB();
$user = currentUser();

$filter = $_GET['filter'] ?? 'monthly';
$year   = intval($_GET['year'] ?? date('Y'));
$month  = intval($_GET['month'] ?? date('n'));

// Build date range
if ($filter === 'weekly') {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd   = date('Y-m-d', strtotime('sunday this week'));
    $dateFrom  = $weekStart;
    $dateTo    = $weekEnd;
    $label     = 'Week of ' . date('F j', strtotime($weekStart));
} elseif ($filter === 'monthly') {
    $dateFrom = date('Y-m-01', mktime(0,0,0,$month,1,$year));
    $dateTo   = date('Y-m-t',  mktime(0,0,0,$month,1,$year));
    $label    = date('F Y', mktime(0,0,0,$month,1,$year));
} else {
    $dateFrom = "{$year}-01-01";
    $dateTo   = "{$year}-12-31";
    $label    = "Year {$year}";
}

// Total revenue (verified payments)
$rev = $db->prepare("
    SELECT COALESCE(SUM(p.amount),0)
    FROM payments p
    JOIN bookings b ON p.booking_id=b.id
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE th.owner_id=? AND p.status='verified'
    AND DATE(p.submitted_at) BETWEEN ? AND ?
");
$rev->execute([$user['id'],$dateFrom,$dateTo]);
$totalRevenue = $rev->fetchColumn();

// Booking counts
$counts = $db->prepare("
    SELECT b.status, COUNT(*) as cnt
    FROM bookings b
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE th.owner_id=? AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY b.status
");
$counts->execute([$user['id'],$dateFrom,$dateTo]);
$countData = [];
foreach ($counts->fetchAll() as $r) $countData[$r['status']] = $r['cnt'];
$totalBookings  = array_sum($countData);
$paidBookings   = $countData['completed'] ?? 0;
$pendingBookings= $countData['pending']   ?? 0;

// Payment method breakdown
$methods = $db->prepare("
    SELECT p.payment_method, COALESCE(SUM(p.amount),0) as total
    FROM payments p
    JOIN bookings b ON p.booking_id=b.id
    JOIN transient_units tu ON b.unit_id=tu.id
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE th.owner_id=? AND p.status='verified'
    AND DATE(p.submitted_at) BETWEEN ? AND ?
    GROUP BY p.payment_method
");
$methods->execute([$user['id'],$dateFrom,$dateTo]);
$methodData = ['cash'=>0,'gcash'=>0,'bank_transfer'=>0];
foreach ($methods->fetchAll() as $r) $methodData[$r['payment_method']] = $r['total'];

// Bar chart data: income by period
if ($filter === 'weekly') {
    $barQ = $db->prepare("
        SELECT DAYNAME(p.submitted_at) as period, COALESCE(SUM(p.amount),0) as total
        FROM payments p
        JOIN bookings b ON p.booking_id=b.id
        JOIN transient_units tu ON b.unit_id=tu.id
        JOIN transient_houses th ON tu.house_id=th.id
        WHERE th.owner_id=? AND p.status='verified'
        AND DATE(p.submitted_at) BETWEEN ? AND ?
        GROUP BY DAYOFWEEK(p.submitted_at), DAYNAME(p.submitted_at)
        ORDER BY DAYOFWEEK(p.submitted_at)
    ");
    $barQ->execute([$user['id'],$dateFrom,$dateTo]);
} elseif ($filter === 'monthly') {
    $barQ = $db->prepare("
        SELECT DAY(p.submitted_at) as period, COALESCE(SUM(p.amount),0) as total
        FROM payments p
        JOIN bookings b ON p.booking_id=b.id
        JOIN transient_units tu ON b.unit_id=tu.id
        JOIN transient_houses th ON tu.house_id=th.id
        WHERE th.owner_id=? AND p.status='verified'
        AND DATE(p.submitted_at) BETWEEN ? AND ?
        GROUP BY DAY(p.submitted_at)
        ORDER BY DAY(p.submitted_at)
    ");
    $barQ->execute([$user['id'],$dateFrom,$dateTo]);
} else {
    $barQ = $db->prepare("
        SELECT MONTHNAME(p.submitted_at) as period, COALESCE(SUM(p.amount),0) as total
        FROM payments p
        JOIN bookings b ON p.booking_id=b.id
        JOIN transient_units tu ON b.unit_id=tu.id
        JOIN transient_houses th ON tu.house_id=th.id
        WHERE th.owner_id=? AND p.status='verified'
        AND DATE(p.submitted_at) BETWEEN ? AND ?
        GROUP BY MONTH(p.submitted_at), MONTHNAME(p.submitted_at)
        ORDER BY MONTH(p.submitted_at)
    ");
    $barQ->execute([$user['id'],$dateFrom,$dateTo]);
}
$barData = $barQ->fetchAll();
$barLabels = json_encode(array_column($barData, 'period'));
$barValues = json_encode(array_column($barData, 'total'));

// Top units
$topUnits = $db->prepare("
    SELECT tu.name, th.name as house_name, COUNT(b.id) as bookings, COALESCE(SUM(p.amount),0) as revenue
    FROM transient_units tu
    JOIN transient_houses th ON tu.house_id=th.id
    LEFT JOIN bookings b ON b.unit_id=tu.id AND DATE(b.created_at) BETWEEN ? AND ?
    LEFT JOIN payments p ON p.booking_id=b.id AND p.status='verified'
    WHERE th.owner_id=?
    GROUP BY tu.id ORDER BY revenue DESC LIMIT 5
");
$topUnits->execute([$dateFrom,$dateTo,$user['id']]);
$topUnits = $topUnits->fetchAll();

// Determine x-axis label based on filter
$xAxisLabel = match($filter) {
    'weekly'   => 'Day of the Week',
    'monthly'  => 'Day of the Month',
    'annually' => 'Month',
    default    => 'Period',
};

$pageTitle  = 'Reports';
$activePage = 'reports';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container" id="report-content">
  <div class="page-header-row page-header mt-3">
    <div><h1>Reports</h1><p><?= $label ?></p></div>
    <div class="flex-center gap-2">
      <a href="?filter=weekly" class="btn <?= $filter==='weekly'?'btn-primary':'btn-outline' ?> btn-sm">Weekly</a>
      <a href="?filter=monthly" class="btn <?= $filter==='monthly'?'btn-primary':'btn-outline' ?> btn-sm">Monthly</a>
      <a href="?filter=annually" class="btn <?= $filter==='annually'?'btn-primary':'btn-outline' ?> btn-sm">Annually</a>
      <!-- Download Report Button -->
      <button id="downloadReportBtn" class="btn btn-success btn-sm" onclick="downloadReport()">
        <i class="fa fa-download"></i> Download Report
      </button>
    </div>
  </div>

  <!-- Date navigator -->
  <?php if ($filter==='monthly'): ?>
  <div class="card mb-3" style="padding:12px 20px">
    <div class="flex-center gap-2">
      <?php
      $pm = $month-1; $py=$year; if($pm<1){$pm=12;$py--;}
      $nm = $month+1; $ny=$year; if($nm>12){$nm=1;$ny++;}
      ?>
      <a href="?filter=monthly&month=<?=$pm?>&year=<?=$py?>" class="btn btn-outline btn-sm"><i class="fa fa-chevron-left"></i></a>
      <strong><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></strong>
      <a href="?filter=monthly&month=<?=$nm?>&year=<?=$ny?>" class="btn btn-outline btn-sm"><i class="fa fa-chevron-right"></i></a>
    </div>
  </div>
  <?php elseif ($filter==='annually'): ?>
  <div class="card mb-3" style="padding:12px 20px">
    <div class="flex-center gap-2">
      <a href="?filter=annually&year=<?=$year-1?>" class="btn btn-outline btn-sm"><i class="fa fa-chevron-left"></i></a>
      <strong><?= $year ?></strong>
      <a href="?filter=annually&year=<?=$year+1?>" class="btn btn-outline btn-sm"><i class="fa fa-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Summary Cards -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa fa-peso-sign"></i></div>
      <div><div class="stat-value"><?= formatMoney($totalRevenue) ?></div><div class="stat-label">Total Revenue</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa fa-calendar-check"></i></div>
      <div><div class="stat-value"><?= $totalBookings ?></div><div class="stat-label">Total Bookings</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fa fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $paidBookings ?></div><div class="stat-label">Completed Bookings</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon red"><i class="fa fa-clock"></i></div>
      <div><div class="stat-value"><?= $pendingBookings ?></div><div class="stat-label">Pending Bookings</div></div>
    </div>
  </div>

  <div class="row">
    <!-- Bar Chart -->
    <div class="col-2">
      <div class="card mb-3">
        <div class="card-header">Total Income — <?= $label ?></div>
        <div class="card-body">
          <canvas id="incomeChart" height="100"></canvas>
        </div>
      </div>
    </div>
    <!-- Payment Breakdown -->
    <div class="col">
      <div class="card mb-3">
        <div class="card-header">Payment Breakdown</div>
        <div class="card-body">
          <canvas id="methodChart" height="200"></canvas>
          <div style="margin-top:16px;font-size:13px">
            <?php foreach (['cash'=>'Cash','gcash'=>'GCash','bank_transfer'=>'Bank Transfer'] as $k=>$label2): ?>
            <div class="flex-between" style="padding:6px 0;border-bottom:1px solid var(--border)">
              <span><?= $label2 ?></span>
              <strong><?= formatMoney($methodData[$k]) ?></strong>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Top Units -->
  <div class="card">
    <div class="card-header">Top Performing Units</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Unit</th><th>House</th><th>Bookings</th><th>Revenue</th></tr></thead>
        <tbody>
          <?php foreach ($topUnits as $u): ?>
          <tr>
            <td><?= sanitize($u['name']) ?></td>
            <td><?= sanitize($u['house_name']) ?></td>
            <td><?= $u['bookings'] ?></td>
            <td><?= formatMoney($u['revenue']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$topUnits): ?>
            <tr><td colspan="4" class="text-center text-muted" style="padding:30px">No data for this period.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<!-- html2canvas + jsPDF for PDF download -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
const barLabels = <?= $barLabels ?>;
const barValues = <?= $barValues ?>;
const xAxisLabel = <?= json_encode($xAxisLabel) ?>;

new Chart(document.getElementById('incomeChart'), {
  type: 'bar',
  data: {
    labels: barLabels,
    datasets: [{
      label: 'Revenue (₱)',
      data: barValues,
      backgroundColor: 'rgba(230,126,34,0.7)',
      borderColor: '#e67e22',
      borderWidth: 1,
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false }
    },
    scales: {
      x: {
        title: {
          display: true,
          text: xAxisLabel,
          font: { size: 13, weight: 'bold' },
          color: '#555'
        }
      },
      y: {
        beginAtZero: true,
        title: {
          display: true,
          text: 'Revenue (₱)',
          font: { size: 13, weight: 'bold' },
          color: '#555'
        },
        ticks: {
          callback: v => '₱' + v.toLocaleString()
        }
      }
    }
  }
});

new Chart(document.getElementById('methodChart'), {
  type: 'doughnut',
  data: {
    labels: ['Cash', 'GCash', 'Bank Transfer'],
    datasets: [{
      data: [<?= $methodData['cash'] ?>, <?= $methodData['gcash'] ?>, <?= $methodData['bank_transfer'] ?>],
      backgroundColor: ['#27ae60','#2980b9','#8e44ad'],
    }]
  },
  options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

// ── Download Report as PDF ──────────────────────────────────────────────────
async function downloadReport() {
  const btn = document.getElementById('downloadReportBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating…';

  try {
    const { jsPDF } = window.jspdf;
    const element   = document.getElementById('report-content');

    const canvas = await html2canvas(element, {
      scale: 2,
      useCORS: true,
      backgroundColor: '#ffffff',
      ignoreElements: el => el.id === 'downloadReportBtn'
    });

    const imgData  = canvas.toDataURL('image/png');
    const pdf      = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const pageW    = pdf.internal.pageSize.getWidth();
    const pageH    = pdf.internal.pageSize.getHeight();
    const imgW     = pageW - 20;            // 10 mm margins each side
    const imgH     = (canvas.height * imgW) / canvas.width;

    let posY = 10;
    let remaining = imgH;

    // Add a title/header line
    pdf.setFontSize(11);
    pdf.setTextColor(100);
    pdf.text('Report generated on ' + new Date().toLocaleString(), 10, posY);
    posY += 6;

    // Slice canvas across pages if content is tall
    const usableH = pageH - posY - 10;

    if (imgH <= usableH) {
      pdf.addImage(imgData, 'PNG', 10, posY, imgW, imgH);
    } else {
      // Multi-page rendering using clipping
      let sourceY = 0;
      const scale = canvas.width / imgW;

      while (remaining > 0) {
        const sliceH   = Math.min(usableH, remaining);
        const slicePx  = sliceH * scale;

        const sliceCanvas  = document.createElement('canvas');
        sliceCanvas.width  = canvas.width;
        sliceCanvas.height = slicePx;
        sliceCanvas.getContext('2d').drawImage(
          canvas, 0, sourceY, canvas.width, slicePx,
          0, 0, canvas.width, slicePx
        );

        pdf.addImage(sliceCanvas.toDataURL('image/png'), 'PNG', 10, posY, imgW, sliceH);

        remaining -= sliceH;
        sourceY   += slicePx;

        if (remaining > 0) {
          pdf.addPage();
          posY = 10;
        }
      }
    }

    const filename = 'report-<?= $filter ?>-<?= str_replace(' ', '-', $label) ?>.pdf';
    pdf.save(filename);

  } catch (err) {
    console.error('PDF generation failed:', err);
    alert('Could not generate the PDF. Please try again.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa fa-download"></i> Download Report';
  }
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>