<?php
require_once __DIR__ . '/../../includes/amenity_icons.php';
// modules/public/unit.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT tu.*, th.name as house_name, th.id as house_id, th.address, th.city, th.barangay
    FROM transient_units tu
    JOIN transient_houses th ON tu.house_id=th.id
    WHERE tu.id=? AND tu.is_active=1 AND th.is_active=1
");
$stmt->execute([$id]);
$unit = $stmt->fetch();
if (!$unit) { flash('error','Unit not found.'); redirect('modules/public/transient.php'); }

$photos = $db->prepare("SELECT * FROM unit_photos WHERE unit_id=? ORDER BY is_cover DESC, sort_order ASC");
$photos->execute([$id]);
$photos = $photos->fetchAll();

$amenities = json_decode($unit['amenities'] ?? '[]', true);

// Fetch bookings for this unit to mark checkout date as Booked too
$guestBookings = $db->prepare("
    SELECT b.check_in, b.check_out
    FROM bookings b
    WHERE b.unit_id=? AND b.check_out >= CURDATE() AND b.status NOT IN ('cancelled','rejected')
");
$guestBookings->execute([$id]);

$blockedDates = $db->prepare("
    SELECT date, UPPER(status) as status, note FROM unit_calendar
    WHERE unit_id=? AND date >= CURDATE() AND UPPER(status) IN ('BOOKED','UNAVAILABLE')
");
$blockedDates->execute([$id]);
$blocked      = [];
$blockedNotes = [];
foreach ($blockedDates->fetchAll() as $r) {
    $blocked[$r['date']]      = $r['status']; // Normalize to uppercase for comparison
    if ($r['note']) $blockedNotes[$r['date']] = $r['note'];
}

// Also check accepted bookings and mark their checkout dates as BOOKED
$guestBookings = $db->prepare("
    SELECT b.check_in, b.check_out
    FROM bookings b
    WHERE b.unit_id=? AND b.check_out >= CURDATE() AND b.status NOT IN ('cancelled','rejected')
");
$guestBookings->execute([$id]);

// Mark checkout date as Booked (same logic as owner calendar)
foreach ($guestBookings->fetchAll() as $bk) {
    $co = new DateTime($bk['check_out']);
    $blocked[$co->format('Y-m-d')] = 'BOOKED';
}

// Dynamic unit details from amenities
$unitDetails = [];

// Always show max guests
$unitDetails[] = ['icon' => 'fa-users', 'label' => 'Max Guests', 'value' => $unit['max_guests'] . ' Person(s) Max'];

// Build from amenities
$amenities = json_decode($unit['amenities'] ?? '[]', true);
foreach ($amenities as $a) {
    $unitDetails[] = [
        'icon'  => getAmenityIcon($a),
        'label' => $a,
        'value' => $a,
    ];
}

// Get house policies
$houseStmt = $db->prepare("SELECT policies FROM transient_houses WHERE id=?");
$houseStmt->execute([$unit['house_id']]);
$housePolicies = $houseStmt->fetchColumn();

$pageTitle  = sanitize($unit['name']);
$activePage = 'transients';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.unit-photo-main {
    width: 100%; height: 360px; object-fit: cover;
    border-radius: 14px; display: block; margin-bottom: 10px;
}
.unit-photo-thumb {
    width: 80px; height: 68px; object-fit: cover;
    border-radius: 8px; cursor: pointer;
    border: 2px solid var(--border); transition: border-color .15s;
}
.unit-photo-thumb:hover, .unit-photo-thumb.active { border-color: var(--accent); }
.unit-photo-placeholder {
    width: 100%; height: 360px; border-radius: 14px;
    background: linear-gradient(135deg, #2c3e5015, #e67e2215);
    display: flex; align-items: center; justify-content: center;
    font-size: 72px; color: var(--border);
}

/* Section title */
.section-title {
    font-size: 17px; font-weight: 700; margin-bottom: 14px;
    padding-bottom: 10px; border-bottom: 2px solid var(--border);
    display: flex; align-items: center; gap: 8px;
}
.section-title i { color: var(--accent); }

/* Unit detail grid */
.detail-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px;
}
.detail-item {
    background: var(--bg); border: 1px solid var(--border); border-radius: 10px;
    padding: 14px; display: flex; align-items: center; gap: 12px;
}
.detail-icon {
    width: 38px; height: 38px; border-radius: 8px; background: #fef5e7;
    color: var(--accent); display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}

/* Policy items */
.policy-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 0; border-bottom: 1px solid var(--border);
}
.policy-item:last-child { border-bottom: none; }
.policy-icon {
    width: 34px; height: 34px; border-radius: 8px; display: flex;
    align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;
}

/* Calendar */
.cal-wrap { background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.cal-top { background: var(--primary-lt); color: #fff; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; }
.cal-top h3 { font-size: 15px; font-weight: 600; margin: 0; }
.cal-top button { background: rgba(255,255,255,.2); border: none; color: #fff; border-radius: 6px; padding: 4px 12px; cursor: pointer; font-size: 13px; }
.cal-top button:hover { background: rgba(255,255,255,.3); }
.cal-body { padding: 16px; }
.cal-days-header { display: grid; grid-template-columns: repeat(7,1fr); margin-bottom: 6px; }
.cal-days-header span { text-align: center; font-size: 11px; font-weight: 700; color: var(--text-muted); padding: 4px 0; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 3px; }
.cal-cell {
    aspect-ratio: 1; border-radius: 8px; display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 500; cursor: default; position: relative;
}
.cal-cell.other    { opacity: .25; }
.cal-cell.today    { font-weight: 800; color: var(--accent); }
.cal-cell.status-available   { background: #d4edda; color: #155724;  border: 1px solid #c3e6cb; }
.cal-cell.status-booked      { background: #f8d7da; color: #721c24;  border: 1px solid #f5c6cb; text-decoration: line-through; }
.cal-cell.status-unavailable { background: #fff3cd; color: #856404;  border: 1px solid #ffc107; }
.cal-cell.past     { opacity: .3; }
.cal-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
.cal-legend-item { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-muted); }
.cal-legend-dot { width: 12px; height: 12px; border-radius: 3px; }

/* Inquiry box */
.inquiry-box { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.inquiry-box-header { background: var(--primary-lt); color: #fff; padding: 18px 22px; }
.inquiry-box-header h3 { font-size: 16px; font-weight: 700; margin-bottom: 3px; }
.inquiry-box-header p  { font-size: 13px; opacity: .8; margin: 0; }
.inquiry-box-body { padding: 20px; }

/* Booking sidebar */
.book-sidebar { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; position: sticky; top: 80px; }
.book-sidebar-header { background: var(--primary-lt); color: #fff; padding: 18px 20px; text-align: center; }
.book-sidebar-header .price { font-size: 34px; font-weight: 800; line-height: 1; }
.book-sidebar-header .per  { font-size: 13px; opacity: .85; }
.book-sidebar-body { padding: 20px; }

</style>

<div class="container" style="margin-top:24px">

    <!-- Breadcrumb -->
    <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);margin-bottom:20px;flex-wrap:wrap">
        <a href="<?= base_url('modules/public/transient.php') ?>" style="color:var(--text-muted)">Transients</a>
        <i class="fa fa-chevron-right" style="font-size:10px"></i>
        <a href="<?= base_url('modules/public/house.php?id='.$unit['house_id']) ?>" style="color:var(--text-muted)"><?= sanitize($unit['house_name']) ?></a>
        <i class="fa fa-chevron-right" style="font-size:10px"></i>
        <span style="color:var(--text)"><?= sanitize($unit['name']) ?></span>
    </div>

    <div class="row" style="align-items:flex-start">

        <!-- LEFT: Main Content -->
        <div class="col-2" style="display:flex;flex-direction:column;gap:28px">

            <!-- Photos — Slideshow -->
            <div>
                <?php if ($photos): ?>
                <div class="slideshow-wrap">
                    <div class="slideshow-track" id="slideshowTrack">
                        <?php foreach ($photos as $p): ?>
                            <div class="slideshow-slide">
                                <img src="<?= base_url('uploads/'.$p['photo_path']) ?>" alt="">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($photos) > 1): ?>
                    <button class="slide-btn slide-prev" onclick="slideMove(-1)"><i class="fa fa-chevron-left"></i></button>
                    <button class="slide-btn slide-next" onclick="slideMove(1)"><i class="fa fa-chevron-right"></i></button>
                    <div class="slide-dots" id="slideDots">
                        <?php foreach ($photos as $i => $p): ?>
                            <span class="slide-dot <?= $i===0?'active':'' ?>" onclick="slideTo(<?= $i ?>)"></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="slide-counter" id="slideCounter">1 / <?= count($photos) ?></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                    <div class="unit-photo-placeholder"><i class="fa fa-door-open"></i></div>
                <?php endif; ?>
            </div>

            <!-- Unit Name & Location -->
            <div>
                <h1 style="font-size:26px;font-weight:800;margin-bottom:6px"><?= sanitize($unit['name']) ?></h1>
                <div style="font-size:14px;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <span><i class="fa fa-building" style="color:var(--accent)"></i> <?= sanitize($unit['house_name']) ?></span>
                    <span>&bull;</span>
                    <span><i class="fa fa-map-marker-alt" style="color:var(--accent)"></i> <?= sanitize($unit['city']) ?><?= $unit['barangay'] ? ', '.sanitize($unit['barangay']) : '' ?></span>
                </div>
                <?php if ($unit['description']): ?>
                    <p style="margin-top:14px;color:var(--text-muted);line-height:1.8"><?= nl2br(sanitize($unit['description'])) ?></p>
                <?php endif; ?>
            </div>

            <!-- Unit Details -->
            <div>
                <div class="section-title"><i class="fa fa-list-check"></i> Unit Details</div>
                <div class="detail-grid">
                    <?php foreach ($unitDetails as $d): ?>
                        <div class="detail-item">
                            <div class="detail-icon"><i class="fa <?= $d['icon'] ?>"></i></div>
                            <div>
                                <div style="font-size:11px;color:var(--text-muted)"><?= $d['label'] ?></div>
                                <div style="font-size:13px;font-weight:600"><?= $d['value'] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- House Policies -->
            <?php if ($housePolicies): ?>
            <div>
                <div class="section-title"><i class="fa fa-clipboard-list"></i> House Policies</div>
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px;font-size:14px;line-height:1.8;color:var(--text)">
                    <?= nl2br(sanitize($housePolicies)) ?>
                </div>
            </div>
            <?php else: ?>
            <div>
                <div class="section-title"><i class="fa fa-clipboard-list"></i> House Policies</div>
                <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:var(--radius);padding:16px;font-size:13px;line-height:1.8;color:#7d5c00">
                    <ul style="margin:0 0 0 16px;line-height:2">
                        <li>Downpayment is <strong>non-refundable</strong> upon cancellation.</li>
                        <li>Payment must be completed within <strong>24 hours</strong> of booking acceptance.</li>
                        <li>Additional guests beyond the max are charged <strong>₱500/head</strong>.</li>
                        <li>Please keep the unit clean and report any damages immediately.</li>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Unit Policies -->
            <div>
                <div class="section-title"><i class="fa fa-shield-alt"></i> Unit Policies</div>
                <div class="card">
                    <div class="card-body" style="padding:8px 20px">

                        <div class="policy-item">
                            <div class="policy-icon" style="background:#fff3cd;color:#856404"><i class="fa fa-money-bill-wave"></i></div>
                            <div>
                                <div style="font-weight:600;font-size:14px;margin-bottom:4px">Down Payment & Pricing</div>
                                <ul style="margin:0 0 0 16px;font-size:13px;color:var(--text-muted);line-height:1.9">
                                    <li>Extra guests: <strong>₱500 per person</strong></li>
                                    <li><strong>50% Downpayment</strong> is required upon booking acceptance</li>
                                    <li>Accepts <strong>GCash</strong>, <strong>BDO Bank Transfer</strong>, and <strong>Cash</strong></li>
                                </ul>
                            </div>
                        </div>

                        <div class="policy-item">
                            <div class="policy-icon" style="background:#d1ecf1;color:var(--info)"><i class="fa fa-clock"></i></div>
                            <div>
                                <div style="font-weight:600;font-size:14px;margin-bottom:4px">Check-in / Check-out</div>
                                <ul style="margin:0 0 0 16px;font-size:13px;color:var(--text-muted);line-height:1.9">
                                    <li>Check-in: <strong>2:00 PM</strong></li>
                                    <li>Check-out: <strong>12:00 NN</strong></li>
                                    <li>Staying beyond <strong>5 hours</strong> past check-out is considered 1 full day</li>
                                </ul>
                            </div>
                        </div>

                        <div class="policy-item">
                            <div class="policy-icon" style="background:#f8d7da;color:var(--danger)"><i class="fa fa-ban"></i></div>
                            <div>
                                <div style="font-weight:600;font-size:14px;margin-bottom:4px">Cancellation Policy</div>
                                <ul style="margin:0 0 0 16px;font-size:13px;color:var(--text-muted);line-height:1.9">
                                    <li>Downpayment is <strong>non-refundable</strong> upon cancellation</li>
                                    <li>Payment must be completed within <strong>24 hours</strong> of acceptance</li>
                                    <li>Failure to pay within deadline will automatically cancel the booking</li>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Availability Calendar -->
            <div>
                <div class="section-title"><i class="fa fa-calendar-alt"></i> Availability Calendar</div>
                <div class="cal-wrap">
                    <div class="cal-top">
                        <button onclick="prevMonth()"><i class="fa fa-chevron-left"></i></button>
                        <h3 id="calTitle"></h3>
                        <button onclick="nextMonth()"><i class="fa fa-chevron-right"></i></button>
                    </div>
                    <div class="cal-body">
                        <div class="cal-days-header">
                            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                                <span><?= $d ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="cal-grid" id="calGrid"></div>
                        <div class="cal-legend">
                            <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#d4edda;border:1px solid #28a745"></div> Available</div>
                            <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#f8d7da;border:1px solid #dc3545"></div> Booked</div>
                            <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#fff3cd;border:1px solid #ffc107"></div> Unavailable</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inquiry Box -->
            <div>
                <div class="section-title"><i class="fa fa-paper-plane"></i> Send an Inquiry</div>
                <div class="inquiry-box">
                    <div class="inquiry-box-header">
                        <h3>Have questions about this unit?</h3>
                        <p>Ask about availability, pricing, or anything else. We'll respond promptly.</p>
                    </div>
                    <div class="inquiry-box-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Your Name</label>
                                <input type="text" id="inqName" placeholder="Juan Dela Cruz"
                                    value="<?php $u = currentUser(); echo $u ? sanitize(($u['first_name']??'').' '.($u['last_name']??'')) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" id="inqEmail" placeholder="you@email.com"
                                    value="<?= $u ? sanitize($u['email'] ?? '') : '' ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Your Message</label>
                            <textarea id="inqMessage" rows="4" placeholder="Hi! I'd like to ask about the availability for this unit on specific dates..."></textarea>
                        </div>
                        <button class="btn btn-primary btn-block" onclick="sendInquiry()">
                            <i class="fa fa-paper-plane"></i> Send Inquiry
                        </button>
                        <div id="inqSuccess" style="display:none;margin-top:12px" class="alert alert-success">
                            <i class="fa fa-check-circle"></i> Inquiry sent! We'll get back to you soon.
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- RIGHT: Booking Sidebar -->
        <div style="width:300px;flex-shrink:0">
            <div class="book-sidebar">
                <div class="book-sidebar-body">
                    <!-- Book Now / Login CTA -->
                    <?php if (isLoggedIn() && currentUser()['role'] === 'guest'): ?>
                        <a href="<?= base_url('modules/guest/book.php?unit_id='.$id) ?>"
                           id="bookBtn"
                           class="btn btn-primary btn-block btn-lg">
                            <i class="fa fa-calendar-check"></i> Book Now
                        </a>
                    <?php elseif (isLoggedIn()): ?>
                        <div class="alert alert-info" style="font-size:13px;margin:0">
                            <i class="fa fa-info-circle"></i> Only guest accounts can make bookings.
                        </div>
                    <?php else: ?>
                        <a href="<?= base_url('modules/auth/login.php') ?>" class="btn btn-primary btn-block btn-lg">
                            <i class="fa fa-sign-in-alt"></i> Login to Book
                        </a>
                        <div class="text-center mt-1 fs-sm text-muted">
                            No account? <a href="<?= base_url('modules/auth/register.php') ?>">Register free</a>
                        </div>
                    <?php endif; ?>

                    <div class="divider"></div>
                    <div style="font-size:12px;color:var(--text-muted);text-align:center;line-height:1.6">
                        <i class="fa fa-shield-alt" style="color:var(--success)"></i>
                        Secure booking &bull; No hidden fees<br>Free cancellation before acceptance
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Photo switcher
function switchPhoto(el) {
    document.getElementById('mainPhoto').src = el.src;
    document.querySelectorAll('.unit-photo-thumb').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
}

// Sidebar price calculator
const pricePerNight  = <?= floatval($unit['price_per_night']) ?>;
const maxGuests      = <?= intval($unit['max_guests']) ?>;
const extraGuestRate = <?= EXTRA_GUEST_RATE ?>;
const unitId         = <?= $id ?>;

function sbCalc() {
    const ci     = document.getElementById('sbCheckin').value;
    const co     = document.getElementById('sbCheckout').value;
    const guests = parseInt(document.getElementById('sbGuests').value) || 1;

    if (ci) {
        const next = new Date(ci); next.setDate(next.getDate()+1);
        document.getElementById('sbCheckout').min = next.toISOString().split('T')[0];
    }
    if (!ci || !co || co <= ci) { document.getElementById('sbSummary').style.display='none'; return; }

    const nights      = Math.round((new Date(co)-new Date(ci))/(86400000));
    const extraHeads  = Math.max(0, guests - maxGuests);
    const extraCharge = extraHeads * extraGuestRate;
    const baseTotal   = nights * pricePerNight;
    const total       = baseTotal + extraCharge;
    const downpayment = total * 0.5;
    const balance     = total * 0.5;

    document.getElementById('sbNights').textContent = nights;
    document.getElementById('sbTotal').textContent  = '₱'+total.toLocaleString('en-PH',{minimumFractionDigits:2});
    document.getElementById('sbSummary').style.display = 'block';

    // Update summary breakdown
    const summary = document.getElementById('sbSummary');
    summary.innerHTML = `
        <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span>${nights} night(s) × ₱${pricePerNight.toLocaleString()}</span>
            <span>₱${baseTotal.toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
        </div>
        ${extraHeads > 0 ? `
        <div class="flex-between" style="padding:5px 0;border-bottom:1px solid var(--border);font-size:13px;color:var(--warning)">
            <span>Extra ${extraHeads} guest(s) × ₱${extraGuestRate}</span>
            <span>₱${extraCharge.toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
        </div>` : ''}
        <div class="flex-between fw-bold" style="padding:8px 0;border-bottom:1px solid var(--border);font-size:15px">
            <span>Total</span>
            <span style="color:var(--accent)">₱${total.toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
        </div>
        <div class="flex-between" style="padding:5px 0;font-size:13px;color:var(--warning)">
            <span>Downpayment (50%)</span>
            <span>₱${downpayment.toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
        </div>
        <div class="flex-between" style="padding:5px 0;font-size:13px;color:var(--text-muted)">
            <span>Balance at checkout</span>
            <span>₱${balance.toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
        </div>
    `;

    const btn = document.getElementById('bookBtn');
    if (btn) {
        btn.href = `/mctbs/modules/guest/book.php?unit_id=${unitId}&checkin=${ci}&checkout=${co}&guests=${guests}`;
    }
}

// Availability Calendar
const blockedDates = <?= json_encode($blocked) ?>;
const blockedNotes = <?= json_encode($blockedNotes) ?>;
let calYear  = new Date().getFullYear();
let calMonth = new Date().getMonth();
const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function renderCal() {
    const today     = new Date();
    const firstDay  = new Date(calYear, calMonth, 1).getDay();
    const daysInMo  = new Date(calYear, calMonth+1, 0).getDate();
    document.getElementById('calTitle').textContent = months[calMonth]+' '+calYear;
    let html = '';
    // Empty cells before first day
    for (let i=0; i<firstDay; i++) html += '<div class="cal-cell other"></div>';
    for (let d=1; d<=daysInMo; d++) {
        const dateStr = calYear+'-'+String(calMonth+1).padStart(2,'0')+'-'+String(d).padStart(2,'0');
        const cellDate = new Date(calYear, calMonth, d);
        const isPast   = cellDate < new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const isToday  = (d === today.getDate() && calMonth === today.getMonth() && calYear === today.getFullYear());

        let cls = 'status-available';
        if (isPast) {
            cls = 'past';
        } else if (blockedDates[dateStr] === 'BOOKED') {
            cls = 'status-booked';
        } else if (blockedDates[dateStr] === 'UNAVAILABLE') {
            cls = 'status-unavailable';
        }
        if (isToday) cls += ' today';
        let dataInfo = '';
        if (!isPast) {
            if (blockedDates[dateStr] === 'BOOKED') {
                dataInfo = `data-info='${JSON.stringify({type:'BOOKED', note: blockedNotes[dateStr] ?? 'This date is already booked'})}'`;
            } else if (blockedDates[dateStr] === 'UNAVAILABLE') {
                dataInfo = `data-info='${JSON.stringify({type:'UNAVAILABLE', note: blockedNotes[dateStr] ?? ''})}'`;
            }
        }
                html += `<div class="cal-cell ${cls}" ${dataInfo}>${d}</div>`;
            }
            document.getElementById('calGrid').innerHTML = html;
}
function prevMonth() { if (calMonth===0){calMonth=11;calYear--;}else{calMonth--;} renderCal(); }
function nextMonth() { if (calMonth===11){calMonth=0;calYear++;}else{calMonth++;} renderCal(); }
renderCal();

// Inquiry
function sendInquiry() {
    const name    = document.getElementById('inqName').value.trim();
    const email   = document.getElementById('inqEmail').value.trim();
    const message = document.getElementById('inqMessage').value.trim();
    if (!name || !email || !message) { alert('Please fill in all fields.'); return; }
    document.getElementById('inqSuccess').style.display = 'flex';
    document.getElementById('inqName').value    = '';
    document.getElementById('inqEmail').value   = '';
    document.getElementById('inqMessage').value = '';
    setTimeout(() => { document.getElementById('inqSuccess').style.display = 'none'; }, 4000);
}
// Guest tooltip card
const guestTooltip = document.createElement('div');
guestTooltip.style.cssText = `
    display:none;position:fixed;z-index:99999;pointer-events:none;
    background:#fff;border:1px solid #dde1e7;border-radius:10px;
    box-shadow:0 4px 20px rgba(0,0,0,.15);padding:14px 16px;
    min-width:180px;font-size:13px;line-height:1.8;
`;
document.body.appendChild(guestTooltip);

document.addEventListener('mouseover', function(e) {
    const cell = e.target.closest('.cal-cell[data-info]');
    if (!cell) { guestTooltip.style.display='none'; return; }
    const info = JSON.parse(cell.getAttribute('data-info'));
    const icon = info.type.includes('maintenance') ? '🔧' : '🚫';
    guestTooltip.innerHTML = `
        <div style="font-weight:700;color:#2c3e50;margin-bottom:4px">${icon} Not Available</div>
        <div style="color:#666;font-size:12px">${info.note || 'This date is not available for booking.'}</div>
    `;
    guestTooltip.style.display = 'block';
});
document.addEventListener('mousemove', function(e) {
    if (guestTooltip.style.display === 'none') return;
    guestTooltip.style.left = Math.min(e.clientX+15, window.innerWidth-200) + 'px';
    guestTooltip.style.top  = Math.max(10, e.clientY - guestTooltip.offsetHeight - 10) + 'px';
});
document.addEventListener('mouseout', function(e) {
    const cell = e.target.closest('.cal-cell[data-info]');
    if (cell) guestTooltip.style.display = 'none';
});

// ── Slideshow ─────────────────────────────────────────────────────────────────
(function () {
    const track  = document.getElementById('slideshowTrack');
    if (!track) return;

    const slides = track.querySelectorAll('.slideshow-slide');
    const dots   = document.querySelectorAll('.slide-dot');
    const counter = document.getElementById('slideCounter');
    let current  = 0;
    let startX   = 0;
    let dragging = false;

    function slideTo(n) {
        current = (n + slides.length) % slides.length;
        track.style.transform = `translateX(-${current * 100}%)`;
        dots.forEach((d, i) => d.classList.toggle('active', i === current));
        if (counter) counter.textContent = `${current + 1} / ${slides.length}`;
    }

    window.slideTo  = slideTo;
    window.slideMove = (dir) => slideTo(current + dir);

    // ── Touch / mouse swipe ──
    track.addEventListener('touchstart',  e => { startX = e.touches[0].clientX; dragging = true; }, { passive: true });
    track.addEventListener('touchend',    e => {
        if (!dragging) return;
        const diff = startX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) slideMove(diff > 0 ? 1 : -1);
        dragging = false;
    });
    track.addEventListener('mousedown',  e => { startX = e.clientX; dragging = true; });
    track.addEventListener('mouseup',    e => {
        if (!dragging) return;
        const diff = startX - e.clientX;
        if (Math.abs(diff) > 40) slideMove(diff > 0 ? 1 : -1);
        dragging = false;
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>