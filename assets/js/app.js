// assets/js/app.js

// ── NOTIFICATIONS ──
function toggleNotifs() {
  const dd = document.getElementById('notifDropdown');
  if (!dd) return;
  const isOpen = dd.classList.contains('open');
  if (!isOpen) {
    dd.classList.add('open');
    loadNotifications();
  } else {
    dd.classList.remove('open');
  }
}

function loadNotifications() {
  fetch('/mctbs/includes/get_notifications.php')
    .then(r => r.json())
    .then(data => {
      const list = document.getElementById('notifList');
      if (!list) return;
      if (!data.length) {
        list.innerHTML = '<p class="notif-loading">No notifications yet.</p>';
        return;
      }
      list.innerHTML = data.map(n => {
        const timeAgo = formatTimeAgo(n.created_at);
        const unreadClass = n.is_read == 0 ? 'unread' : '';
        const link = n.link || '#';
        return `<a class="notif-item ${unreadClass}" href="${link}">
          <div class="notif-title">${escHtml(n.title)}</div>
          <div class="notif-msg">${escHtml(n.message)}</div>
          <div class="notif-time">${timeAgo}</div>
        </a>`;
      }).join('');
    })
    .catch(() => {
      const list = document.getElementById('notifList');
      if (list) list.innerHTML = '<p class="notif-loading">Could not load notifications.</p>';
    });
}

document.addEventListener('click', function(e) {
  // Close notification dropdown
  const wrapper = document.getElementById('notifWrapper');
  if (wrapper && !wrapper.contains(e.target)) {
    const dd = document.getElementById('notifDropdown');
    if (dd) dd.classList.remove('open');
  }

  // Close nav dropdowns when clicking outside
  if (!e.target.closest('.nav-dropdown')) {
    document.querySelectorAll('.nav-dropdown.open').forEach(d => d.classList.remove('open'));
  }
});

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.nav-dropdown > a, .nav-dropdown > button, #profileBtn').forEach(function(link) {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const parent = this.closest('.nav-dropdown');
      const isOpen = parent.classList.contains('open');
      document.querySelectorAll('.nav-dropdown.open').forEach(d => d.classList.remove('open'));
      if (!isOpen) parent.classList.add('open');
    });
  });

  // Keep dropdown open when hovering inside it
  document.querySelectorAll('.nav-dropdown').forEach(function(dropdown) {
    let closeTimer;

    dropdown.addEventListener('mouseleave', function() {
      closeTimer = setTimeout(function() {
        dropdown.classList.remove('open');
      }, 300);
    });

    dropdown.addEventListener('mouseenter', function() {
      clearTimeout(closeTimer);
    });
  });
});

// ── TABS ──
function initTabs() {
  document.querySelectorAll('.tabs').forEach(tabGroup => {
    tabGroup.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const target = btn.dataset.tab;
        tabGroup.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        const pane = document.getElementById(target);
        if (pane) pane.classList.add('active');
        // Update URL param
        const url = new URL(window.location);
        url.searchParams.set('tab', target);
        history.replaceState({}, '', url);
      });
    });
    // Activate from URL
    const params = new URLSearchParams(window.location.search);
    const activeTab = params.get('tab');
    if (activeTab) {
      const btn = tabGroup.querySelector(`[data-tab="${activeTab}"]`);
      if (btn) btn.click();
    } else {
      const first = tabGroup.querySelector('.tab-btn');
      if (first) first.click();
    }
  });
}

// ── PASSWORD STRENGTH ──
function initPasswordStrength() {
  const passInput = document.getElementById('password');
  if (!passInput) return;
  passInput.addEventListener('input', () => {
    const val = passInput.value;
    const rules = {
      len:   val.length >= 8,
      upper: /[A-Z]/.test(val),
      lower: /[a-z]/.test(val),
      num:   /[0-9]/.test(val),
      spec:  /[^A-Za-z0-9]/.test(val),
    };
    Object.entries(rules).forEach(([key, ok]) => {
      const el = document.getElementById('rule-' + key);
      if (el) el.classList.toggle('ok', ok);
    });
  });
}

// ── CONFIRM DIALOG ──
function confirmAction(msg) {
  return confirm(msg || 'Are you sure?');
}

// ── DELETE CONFIRM FORMS ──
document.addEventListener('submit', function(e) {
  const form = e.target;
  if (form.dataset.confirm) {
    if (!confirm(form.dataset.confirm)) {
      e.preventDefault();
    }
  }
});

// ── IMAGE PREVIEW ──
function initImagePreview() {
  document.querySelectorAll('[data-preview-for]').forEach(input => {
    const targetId = input.dataset.previewFor;
    input.addEventListener('change', function() {
      const target = document.getElementById(targetId);
      if (!target) return;
      const files = Array.from(this.files);
      if (target.tagName === 'IMG') {
        if (files[0]) target.src = URL.createObjectURL(files[0]);
      } else {
        target.innerHTML = '';
        files.forEach(file => {
          const img = document.createElement('img');
          img.className = 'photo-preview';
          img.src = URL.createObjectURL(file);
          target.appendChild(img);
        });
      }
    });
  });
}

// ── HELPERS ──
function formatTimeAgo(dateStr) {
  const date = new Date(dateStr);
  const now = new Date();
  const diff = Math.floor((now - date) / 1000);
  if (diff < 60) return 'Just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
  return Math.floor(diff/86400) + 'd ago';
}

function escHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

// ── SEARCH FILTER TABLE ──
function filterTable(inputId, tableId) {
  const input = document.getElementById(inputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;
  input.addEventListener('keyup', () => {
    const q = input.value.toLowerCase();
    table.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}

// ── INIT ──
document.addEventListener('DOMContentLoaded', () => {
  initTabs();
  initPasswordStrength();
  initImagePreview();
  // Auto-dismiss alerts
  document.querySelectorAll('.alert').forEach(a => {
    setTimeout(() => { a.style.opacity = '0'; a.style.transition = 'opacity .5s'; setTimeout(() => a.remove(), 500); }, 5000);
  });
});
