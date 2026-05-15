<?php
// modules/public/house.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT th.*, u.first_name, u.last_name, u.email, u.phone
    FROM transient_houses th
    JOIN users u ON th.owner_id=u.id
    WHERE th.id=? AND th.is_active=1");
$stmt->execute([$id]);
$house = $stmt->fetch();
if (!$house) { flash('error','House not found.'); redirect('modules/public/transient.php'); }

$units = $db->prepare("
    SELECT tu.*,
      (SELECT photo_path FROM unit_photos WHERE unit_id=tu.id ORDER BY is_cover DESC, sort_order ASC, id ASC LIMIT 1) as cover
    FROM transient_units tu
    WHERE tu.house_id=? AND tu.is_active=1
    ORDER BY tu.price_per_night ASC
");
$units->execute([$id]);
$units = $units->fetchAll();

$amenities = json_decode($house['amenities'] ?? '[]', true);

// Sample nearby places in Baguio
$nearbyPlaces = [
    ['icon' => 'fa-mountain',      'name' => 'Mines View Park',         'dist' => '1.2 km', 'type' => 'Tourist Spot'],
    ['icon' => 'fa-tree',          'name' => 'Burnham Park',            'dist' => '2.4 km', 'type' => 'Park'],
    ['icon' => 'fa-store',         'name' => 'Baguio Public Market',    'dist' => '1.8 km', 'type' => 'Market'],
    ['icon' => 'fa-church',        'name' => 'Baguio Cathedral',        'dist' => '2.1 km', 'type' => 'Landmark'],
    ['icon' => 'fa-shopping-bag',  'name' => 'SM City Baguio',          'dist' => '3.0 km', 'type' => 'Mall'],
    ['icon' => 'fa-utensils',      'name' => 'Session Road',            'dist' => '2.2 km', 'type' => 'Dining & Shopping'],
];

// Sample guest ratings
$guestRatings = [
    ['name' => 'Maria Santos',   'rating' => 5, 'date' => 'March 2025',   'comment' => 'Amazing place! Very clean, cozy, and the host was super responsive. Will definitely come back!', 'avatar' => 'MS'],
    ['name' => 'Juan Reyes',     'rating' => 4, 'date' => 'February 2025','comment' => 'Great location near tourist spots. The unit was comfortable and had everything we needed for our family trip.', 'avatar' => 'JR'],
    ['name' => 'Ana Villanueva', 'rating' => 5, 'date' => 'January 2025', 'comment' => 'Perfect for a Baguio getaway. Warm, homey atmosphere and very affordable. Highly recommended!', 'avatar' => 'AV'],
];
$avgRating = 4.7;

$pageTitle  = sanitize($house['name']);
$activePage = 'transients';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.house-hero {
    position: relative;
    height: 380px;
    background: var(--primary);
    overflow: hidden;
}
.house-hero img {
    width: 100%; height: 100%; object-fit: cover; display: block;
    filter: brightness(.75);
}
.house-hero-placeholder {
    width: 100%; height: 100%;
    background: linear-gradient(135deg, var(--primary), #1a252f);
    display: flex; align-items: center; justify-content: center;
    font-size: 80px; color: rgba(255,255,255,.2);
}
.house-hero-overlay {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 24px 32px;
    background: linear-gradient(to top, rgba(0,0,0,.75), transparent);
    color: #fff;
}
.house-hero-overlay h1 { font-size: 30px; font-weight: 800; margin-bottom: 6px; }
.house-hero-overlay .loc { font-size: 14px; opacity: .9; display: flex; align-items: center; gap: 6px; }

/* Rating stars */
.stars { color: #f39c12; font-size: 14px; display: inline-flex; gap: 2px; }
.rating-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fff8e1; border: 1px solid #ffe082;
    color: #7d5c00; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 700;
}

/* Section titles */
.section-title {
    font-size: 18px; font-weight: 700; margin-bottom: 16px;
    padding-bottom: 10px; border-bottom: 2px solid var(--border);
    display: flex; align-items: center; gap: 10px;
}
.section-title i { color: var(--accent); }

/* Unit cards */
.unit-row-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    overflow: hidden; display: flex; transition: box-shadow .2s, transform .2s;
    text-decoration: none; color: inherit;
}
.unit-row-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.1); transform: translateY(-2px); text-decoration: none; }
.unit-row-card-img { width: 200px; flex-shrink: 0; object-fit: cover; display: block; }
.unit-row-card-img-placeholder {
    width: 200px; flex-shrink: 0;
    background: linear-gradient(135deg, #2c3e5015, #e67e2215);
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: var(--border);
}
.unit-row-card-body { padding: 18px; flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
.unit-row-card-body h3 { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.unit-amenity-tag {
    display: inline-flex; align-items: center; gap: 4px;
    background: var(--bg); border: 1px solid var(--border);
    padding: 3px 10px; border-radius: 999px; font-size: 11px; color: var(--text-muted);
}

/* Nearby places */
.nearby-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 10px;
    padding: 14px 16px; display: flex; align-items: center; gap: 14px;
}
.nearby-icon {
    width: 42px; height: 42px; border-radius: 10px; background: #ebf4fb;
    color: var(--info); display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}

/* Review cards */
.review-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px;
}
.review-avatar {
    width: 42px; height: 42px; border-radius: 50%; background: var(--accent);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; flex-shrink: 0;
}

/* Contact */
.contact-link {
    display: flex; align-items: center; gap: 12px; padding: 12px 16px;
    border: 1px solid var(--border); border-radius: 10px; background: var(--card);
    text-decoration: none; color: var(--text); transition: all .15s; font-size: 14px;
}
.contact-link:hover { border-color: var(--accent); background: #fef5e7; text-decoration: none; }
.contact-icon {
    width: 38px; height: 38px; border-radius: 8px; display: flex;
    align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
}

/* Inquiry box */
.inquiry-box {
    background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden;
}
.inquiry-box-header {
    background: var(--primary-lt);
    color: #fff; padding: 20px 24px;
}
.inquiry-box-header h3 { font-size: 17px; font-weight: 700; margin-bottom: 4px; }
.inquiry-box-header p  { font-size: 13px; opacity: .8; margin: 0; }
.inquiry-box-body { padding: 24px; }
</style>

<!-- Hero -->
<div class="house-hero">
    <?php if ($house['cover_photo']): ?>
        <img src="<?= base_url('uploads/'.$house['cover_photo']) ?>" alt="<?= sanitize($house['name']) ?>">
    <?php else: ?>
        <div class="house-hero-placeholder"><i class="fa fa-building"></i></div>
    <?php endif; ?>
    <div class="house-hero-overlay">
        <a href="<?= base_url('modules/public/transient.php') ?>" style="color:rgba(255,255,255,.75);font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin-bottom:10px">
            <i class="fa fa-arrow-left"></i> Back to all transients
        </a>
        <h1><?= sanitize($house['name']) ?></h1>
        <div class="loc">
            <i class="fa fa-map-marker-alt"></i>
            <?= sanitize($house['address'].', '.$house['city']) ?><?= $house['barangay'] ? ', '.sanitize($house['barangay']) : '' ?>
        </div>
    </div>
</div>

<div class="container" style="margin-top:32px">
    <div class="row" style="align-items:flex-start">

        <!-- LEFT COLUMN -->
        <div class="col-2" style="display:flex;flex-direction:column;gap:32px">

            <!-- Overview -->
            <div>
                <div class="section-title"><i class="fa fa-info-circle"></i> About This Property</div>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap">
                    <div class="rating-pill">
                        <i class="fa fa-star"></i> <?= $avgRating ?> / 5.0
                    </div>
                    <span class="text-muted fs-sm"><?= count($guestRatings) ?> guest reviews</span>
                    <span style="background:#eafaf1;color:var(--success);padding:4px 12px;border-radius:999px;font-size:13px;font-weight:600">
                        <i class="fa fa-check-circle"></i> Active
                    </span>
                </div>
                <?php if ($house['description']): ?>
                    <p style="color:var(--text-muted);line-height:1.8;margin-bottom:16px"><?= nl2br(sanitize($house['description'])) ?></p>
                <?php endif; ?>
                <?php if ($amenities): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        <?php foreach ($amenities as $a): ?>
                            <span class="unit-amenity-tag"><i class="fa fa-check" style="color:var(--success)"></i> <?= sanitize($a) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Available Units -->
            <div>
                <div class="section-title"><i class="fa fa-door-open"></i> Available Units</div>
                <?php if ($units): ?>
                    <div style="display:flex;flex-direction:column;gap:16px">
                        <?php foreach ($units as $u): ?>
                            <?php $ua = json_decode($u['amenities'] ?? '[]', true); ?>
                            <a href="<?= base_url('modules/public/unit.php?id='.$u['id']) ?>" class="unit-row-card">
                                <?php if ($u['cover']): ?>
                                    <img src="<?= base_url('uploads/'.$u['cover']) ?>" class="unit-row-card-img" alt="">
                                <?php else: ?>
                                    <div class="unit-row-card-img-placeholder"><i class="fa fa-door-open"></i></div>
                                <?php endif; ?>
                                <div class="unit-row-card-body">
                                    <div>
                                        <h3><?= sanitize($u['name']) ?></h3>
                                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap">
                                            <span class="fs-sm text-muted"><i class="fa fa-users"></i> Max <?= $u['max_guests'] ?> guests</span>
                                            <span class="fs-sm text-muted"><i class="fa fa-moon"></i> <?= formatMoney($u['price_per_night']) ?>/night</span>
                                        </div>
                                        <?php if ($u['description']): ?>
                                            <p class="fs-sm text-muted" style="margin-bottom:10px;line-height:1.6"><?= sanitize(substr($u['description'],0,100)) ?>...</p>
                                        <?php endif; ?>
                                        <?php if ($ua): ?>
                                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                                <?php foreach (array_slice($ua,0,5) as $a): ?>
                                                    <span class="unit-amenity-tag"><?= sanitize($a) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;padding-top:12px;border-top:1px solid var(--border)">
                                        <div>
                                            <div style="font-size:20px;font-weight:800;color:var(--accent)"><?= formatMoney($u['price_per_night']) ?><span style="font-size:12px;font-weight:400;color:var(--text-muted)">/night</span></div>
                                        </div>
                                        <span class="btn btn-primary btn-sm">View Unit <i class="fa fa-arrow-right"></i></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:40px 20px">
                        <i class="fa fa-door-open"></i>
                        <h3>No units available</h3>
                        <p>This property has no active units at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Nearby Places -->
            <div>
                <div class="section-title"><i class="fa fa-map-marked-alt"></i> Nearby Places in Baguio</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
                    <?php foreach ($nearbyPlaces as $p): ?>
                        <div class="nearby-card">
                            <div class="nearby-icon"><i class="fa <?= $p['icon'] ?>"></i></div>
                            <div>
                                <div style="font-weight:600;font-size:14px"><?= $p['name'] ?></div>
                                <div style="font-size:12px;color:var(--text-muted)"><?= $p['type'] ?> &bull; <?= $p['dist'] ?> away</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Guest Reviews -->
            <div>
                <div class="section-title"><i class="fa fa-star"></i> Guest Reviews</div>
                <!-- Average -->
                <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;display:flex;align-items:center;gap:24px;margin-bottom:20px;flex-wrap:wrap">
                    <div style="text-align:center">
                        <div style="font-size:52px;font-weight:800;color:var(--accent);line-height:1"><?= $avgRating ?></div>
                        <div class="stars">
                            <?php for ($i=1;$i<=5;$i++): ?>
                                <i class="fa fa-star<?= $i <= floor($avgRating) ? '' : ($i - $avgRating < 1 ? '-half-alt' : '-o') ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?= count($guestRatings) ?> reviews</div>
                    </div>
                    <div style="flex:1;min-width:160px">
                        <?php
                        $ratingLabels = ['Cleanliness','Location','Value','Communication'];
                        $ratingValues = [4.9, 4.8, 4.6, 4.7];
                        foreach ($ratingLabels as $i => $label): ?>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                <span style="font-size:12px;color:var(--text-muted);width:110px"><?= $label ?></span>
                                <div style="flex:1;background:var(--bg);border-radius:999px;height:6px;overflow:hidden">
                                    <div style="width:<?= ($ratingValues[$i]/5)*100 ?>%;height:100%;background:var(--accent);border-radius:999px"></div>
                                </div>
                                <span style="font-size:12px;font-weight:600;width:28px"><?= $ratingValues[$i] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- Individual reviews -->
                <div style="display:flex;flex-direction:column;gap:14px">
                    <?php foreach ($guestRatings as $r): ?>
                        <div class="review-card">
                            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                                <div class="review-avatar"><?= $r['avatar'] ?></div>
                                <div>
                                    <div style="font-weight:600;font-size:14px"><?= $r['name'] ?></div>
                                    <div style="font-size:12px;color:var(--text-muted)"><?= $r['date'] ?></div>
                                </div>
                                <div class="stars" style="margin-left:auto">
                                    <?php for ($i=1;$i<=5;$i++): ?>
                                        <i class="fa fa-star<?= $i <= $r['rating'] ? '' : '-o' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <p style="font-size:14px;color:var(--text-muted);line-height:1.7;margin:0"><?= $r['comment'] ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <!-- RIGHT SIDEBAR -->
        <div style="width:300px;flex-shrink:0;display:flex;flex-direction:column;gap:20px;position:sticky;top:80px">

            <!-- Contact Info -->
            <div class="card">
                <div class="card-header"><i class="fa fa-address-card" style="color:var(--accent)"></i> Contact the Host</div>
                <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
                    <?php if ($house['contact_number']): ?>
                        <a href="tel:<?= sanitize($house['contact_number']) ?>" class="contact-link">
                            <div class="contact-icon" style="background:#d4edda;color:var(--success)"><i class="fa fa-phone"></i></div>
                            <div>
                                <div style="font-size:11px;color:var(--text-muted)">Phone</div>
                                <div style="font-weight:600"><?= sanitize($house['contact_number']) ?></div>
                            </div>
                        </a>
                    <?php endif; ?>
                    <?php if ($house['email']): ?>
                        <a href="mailto:<?= sanitize($house['email']) ?>" class="contact-link">
                            <div class="contact-icon" style="background:#d1ecf1;color:var(--info)"><i class="fa fa-envelope"></i></div>
                            <div>
                                <div style="font-size:11px;color:var(--text-muted)">Email</div>
                                <div style="font-weight:600"><?= sanitize($house['email']) ?></div>
                            </div>
                        </a>
                    <?php endif; ?>
                    <!-- Sample social links (hardcoded for now) -->
                    <a href="#" class="contact-link" onclick="return false">
                        <div class="contact-icon" style="background:#e8f4fc;color:#1877f2"><i class="fab fa-facebook"></i></div>
                        <div>
                            <div style="font-size:11px;color:var(--text-muted)">Facebook</div>
                            <div style="font-weight:600">MCTBS Transient</div>
                        </div>
                    </a>
                    <a href="#" class="contact-link" onclick="return false">
                        <div class="contact-icon" style="background:#e8f4fc;color:#0084ff"><i class="fab fa-facebook-messenger"></i></div>
                        <div>
                            <div style="font-size:11px;color:var(--text-muted)">Messenger</div>
                            <div style="font-weight:600">Send a message</div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Inquiry Box -->
            <div class="inquiry-box">
                <div class="inquiry-box-header">
                    <h3><i class="fa fa-paper-plane"></i> Send an Inquiry</h3>
                    <p>Have questions? We'll get back to you soon.</p>
                </div>
                <div class="inquiry-box-body">
                    <div class="form-group">
                        <label>Your Name</label>
                        <input type="text" id="inqName" placeholder="Juan Dela Cruz"
                            value="<?= $user = currentUser() ? sanitize(($user['first_name']??'').' '.($user['last_name']??'')) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" id="inqEmail" placeholder="you@email.com"
                            value="<?= $user ? sanitize($user['email'] ?? '') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea id="inqMessage" rows="4" placeholder="Hi! I'd like to know more about availability and rates..."></textarea>
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
</div>

<script>
function sendInquiry() {
    const name    = document.getElementById('inqName').value.trim();
    const email   = document.getElementById('inqEmail').value.trim();
    const message = document.getElementById('inqMessage').value.trim();
    if (!name || !email || !message) {
        alert('Please fill in all fields.');
        return;
    }
    // UI only for now — show success message
    document.getElementById('inqSuccess').style.display = 'flex';
    document.getElementById('inqName').value    = '';
    document.getElementById('inqEmail').value   = '';
    document.getElementById('inqMessage').value = '';
    setTimeout(() => { document.getElementById('inqSuccess').style.display = 'none'; }, 4000);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>