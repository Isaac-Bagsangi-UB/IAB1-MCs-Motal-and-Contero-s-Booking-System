<?php
// modules/public/transient.php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

$db     = getDB();
$search = trim($_GET['q'] ?? '');

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

$pageTitle  = 'Browse Transients';
$activePage = 'transients';
include __DIR__ . '/../../includes/header.php';
?>

<style>
    :root {
      --amber-50:  #fffbeb;
      --amber-100: #fef3c7;
      --amber-300: #fcd34d;
      --amber-400: #fbbf24;
      --amber-500: #f59e0b;
      --amber-600: #d97706;
      --amber-700: #b45309;
      --amber-800: #92400e;
      --white:     #ffffff;
      --gray-50:   #f9fafb;
      --gray-100:  #f3f4f6;
      --gray-300:  #d1d5db;
      --gray-500:  #6b7280;
      --gray-700:  #374151;
      --gray-900:  #111827;
    }

.transients-hero {
    padding: 64px 20px 40px;
    text-align: center;
    color: #fff;
    border-radius: 20px;
    margin-left: 20px; margin-right: 20px;
}
.transients-hero h1 { font-size: 38px; font-weight: 800; margin-bottom: 14px; line-height: 1.05; color: var(--amber-600); }
.transients-hero p  { font-size: 17px; opacity: .88; margin-bottom: 34px; max-width: 780px; margin-left:auto;margin-right:auto; color: var(--amber-600); }
.transients-search-card { max-width: 800px; margin: 0 auto;}
.google-search-bar { text-align: center; }
.search-input-wrapper { 
    position: relative; 
    max-width: 800px; 
    margin: 0 auto 12px; 
    display: flex; 
    align-items: center;
}
.search-input-wrapper input {
    width: 100%;
    height: 56px;
    padding: 0 60px 0 56px;
    border-radius: 28px;
    font-size: 16px;
    outline: none;
    transition: all 0.2s ease;
    box-shadow: 0 1px 6px rgba(32,33,36,.28);
    background: #ffffff4f;
}
.search-input-wrapper input:focus {
    background: var(--white);
    border-color: var(--amber-400);
    box-shadow: 0 0 0 4px rgba(245,158,11,.1);
}
.search-input-wrapper input:hover {
    box-shadow: 0 2px 8px var(--amber-600);
}
.search-icon {
    position: absolute;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    color: #9aa0a6;
    font-size: 18px;
    pointer-events: none;
}
.search-clear {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #5f6368;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}
.search-clear:hover {
    background: #f1f3f4;
}
.house-card-body { padding: 18px; display:flex; flex-direction:column; gap: 12px; }
.house-card-meta { display: flex; align-items: center; justify-content: space-between; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border); gap: 12px; }
.house-card-price { font-size: 18px; font-weight: 800; color: var(--accent); }
.house-card-price span { font-size: 12px; font-weight: 400; color: var(--text-muted); margin-left: 4px; }
.house-card-units { font-size: 12px; color: var(--text-muted); background: var(--bg); padding: 6px 12px; border-radius: 999px; display: inline-flex; align-items: center; gap: 6px; }
.results-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.results-count { font-size: 14px; color: var(--text-muted); }
.results-count strong { color: var(--text); }
</style>

<!-- Hero + Filter -->
<div class="transients-hero">
    <h1>Browse Transient Houses</h1>
    <p>Locate available transient homes in Baguio with a modern search experience and stylish listings.</p>

    <div class="transients-search-card">
        <div class="google-search-bar">
            <div class="search-input-wrapper">
                <input type="text" id="transientSearch" value="<?= sanitize($search) ?>" 
                       placeholder="Search for transient houses, locations, or amenities..." 
                       data-ajax-search="transient">
                <i class="fa fa-search search-icon"></i>
                <button type="button" class="search-clear" id="searchClear" style="display: none;">
                    <i class="fa fa-times"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container" style="margin-top:32px">

    <!-- Results Bar -->
    <div class="results-bar">
        <div class="results-count">
            Showing <strong><?= count($houses) ?></strong> propert<?= count($houses) === 1 ? 'y' : 'ies' ?>
            <?php if ($search): ?> for "<strong><?= sanitize($search) ?></strong>"<?php endif; ?>
        </div>
        <?php if ($search): ?>
            <a href="<?= base_url('modules/public/transient.php') ?>" class="btn btn-outline btn-sm">
                <i class="fa fa-times"></i> Clear Search
            </a>
        <?php endif; ?>
    </div>

    <!-- House Grid -->
    <?php if ($houses): ?>
        <div class="unit-grid" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px">
            <?php foreach ($houses as $h): ?>
                <div class="unit-card">
                    <a href="<?= base_url('modules/public/house.php?id='.$h['id']) ?>" class="house-card">
                    <?php if ($h['cover_photo']): ?>
                        <img src="<?= base_url('uploads/'.$h['cover_photo']) ?>" class="house-card-img" alt="<?= sanitize($h['name']) ?>">
                    <?php else: ?>
                        <div class="house-card-img-placeholder"><i class="fa fa-building"></i></div>
                    <?php endif; ?>
                    <div class="house-card-body">
                        <h3><?= sanitize($h['name']) ?></h3>
                        <div class="house-card-location">
                            <i class="fa fa-map-marker-alt" style="color:var(--accent)"></i>
                            <?= sanitize($h['city']) ?><?= $h['barangay'] ? ', '.sanitize($h['barangay']) : '' ?>
                        </div>
                        <?php if ($h['description']): ?>
                            <p style="font-size:13px;color:var(--text-muted);line-height:1.6;margin-bottom:0">
                                <?= sanitize(substr($h['description'], 0, 100)) ?>...
                            </p>
                        <?php endif; ?>
                        <div class="house-card-meta">
                            <div class="house-card-units">
                                <i class="fa fa-door-open"></i> <?= $h['unit_count'] ?> unit<?= $h['unit_count'] != 1 ? 's' : '' ?>
                            </div>  
                            <!-- <div class="house-card-price">
                                <?= formatMoney($h['min_price'] ?? 0) ?> <span>/night</span>
                            </div> -->
                        </div>
                    </div>
                </a>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="empty-state" style="padding:80px 20px">
            <i class="fa fa-building"></i>
            <h3>No properties found</h3>
            <p><?= ($search || $city) ? 'Try a different search term or location.' : 'No transient houses are available yet.' ?></p>
            <?php if ($search || $city): ?>
                <a href="<?= base_url('modules/public/transient.php') ?>" class="btn btn-outline mt-2">
                    <i class="fa fa-times"></i> Clear Filters
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('transientSearch');
    const searchClear = document.getElementById('searchClear');
    
    // Show/hide clear button based on input value
    function toggleClearButton() {
        searchClear.style.display = searchInput.value.trim() ? 'flex' : 'none';
    }
    
    // Clear search
    searchClear.addEventListener('click', function() {
        searchInput.value = '';
        toggleClearButton();
        performSearch();
    });
    
    // Update clear button visibility on input
    searchInput.addEventListener('input', toggleClearButton);
    
    // Initialize clear button state
    toggleClearButton();
    
    function performSearch() {
        const q = searchInput.value.trim();
        
        fetch(`<?= base_url('includes/ajax_search_transient.php') ?>?q=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(data => {
                // Update results bar
                const resultsBar = document.querySelector('.results-bar');
                let html = `<div class="results-count">Showing <strong>${data.count}</strong> propert${data.count === 1 ? 'y' : 'ies'}`;
                if (data.search) html += ` for "<strong>${sanitizeHtml(data.search)}</strong>"`;
                html += `</div>`;
                
                if (data.hasFilters) {
                    html += `<a href="<?= base_url('modules/public/transient.php') ?>" class="btn btn-outline btn-sm"><i class="fa fa-times"></i> Clear Filters</a>`;
                }
                
                resultsBar.innerHTML = html;
                // Update results bar
                const resultsBar = document.querySelector('.results-bar');
                let html = `<div class="results-count">Showing <strong>${data.count}</strong> propert${data.count === 1 ? 'y' : 'ies'}`;
                if (data.search) html += ` for "<strong>${sanitizeHtml(data.search)}</strong>"`;
                html += `</div>`;
                
                if (data.hasFilters) {
                    html += `<a href="<?= base_url('modules/public/transient.php') ?>" class="btn btn-outline btn-sm"><i class="fa fa-times"></i> Clear Search</a>`;
                }
                
                resultsBar.innerHTML = html;
                
                // Update houses grid
                const gridContainer = document.querySelector('.unit-grid') || document.querySelector('.empty-state').parentElement;
                let houseHtml = '';
                
                if (data.houses.length > 0) {
                    houseHtml = '<div class="unit-grid" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px">';
                    data.houses.forEach(h => {
                        const imgHtml = h.cover_photo 
                            ? `<img src="<?= base_url('uploads/') ?>${sanitizeHtml(h.cover_photo])}" class="house-card-img" alt="${sanitizeHtml(h.name)}">`
                            : `<div class="house-card-img-placeholder"><i class="fa fa-building"></i></div>`;
                        
                        houseHtml += `
                            <div class="unit-card">
                                <a href="<?= base_url('modules/public/house.php?id=') ?>${h.id}" class="house-card">
                                ${imgHtml}
                                <div class="house-card-body">
                                    <h3>${sanitizeHtml(h.name)}</h3>
                                    <div class="house-card-location">
                                        <i class="fa fa-map-marker-alt" style="color:var(--accent)"></i>
                                        ${sanitizeHtml(h.city)}${h.barangay ? ', ' + sanitizeHtml(h.barangay) : ''}
                                    </div>
                                    ${h.description ? `<p style="font-size:13px;color:var(--text-muted);line-height:1.6;margin-bottom:0">${sanitizeHtml(h.description.substring(0, 100))}...</p>` : ''}
                                    <div class="house-card-meta">
                                        <div class="house-card-units">
                                            <i class="fa fa-door-open"></i> ${h.unit_count} unit${h.unit_count !== 1 ? 's' : ''}
                                        </div>
                                        <div class="house-card-price">
                                            ${formatCurrency(h.min_price || 0)} <span>/night</span>
                                        </div>
                                    </div>
                                </div>
                                </a>
                            </div>
                        `;
                    });
                    houseHtml += '</div>';
                } else {
                    houseHtml = `
                        <div class="empty-state" style="padding:80px 20px">
                            <i class="fa fa-building"></i>
                            <h3>No properties found</h3>
                            <p>${data.hasFilters ? 'Try a different search term or location.' : 'No transient houses are available yet.'}</p>
                            ${data.hasFilters ? `<a href="<?= base_url('modules/public/transient.php') ?>" class="btn btn-outline mt-2"><i class="fa fa-times"></i> Clear Filters</a>` : ''}
                        </div>
                    `;
                }
                
                // Replace content
                const existingGrid = document.querySelector('.unit-grid');
                const existingEmpty = document.querySelector('.empty-state');
                
                if (existingGrid) {
                    existingGrid.replaceWith(document.createRange().createContextualFragment(houseHtml));
                } else if (existingEmpty) {
                    existingEmpty.replaceWith(document.createRange().createContextualFragment(houseHtml));
                }
            })
            .catch(err => console.error('Search error:', err));
    }
    
    searchInput.addEventListener('input', performSearch);
    citySelect.addEventListener('change', performSearch);
    
    // Utility functions
    function sanitizeHtml(str) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return str.replace(/[&<>"']/g, m => map[m]);
    }
    
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>