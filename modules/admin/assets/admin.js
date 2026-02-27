/* ===================================================
   Creations by Athena – Admin Dashboard Scripts
   =================================================== */

/* ── Modal helpers ────────────────────────────────── */
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.remove('show'); document.body.style.overflow = ''; }
}

// Close modal on overlay click
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('show');
    document.body.style.overflow = '';
  }
});

// Close modal on Escape key
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.show').forEach(function (m) {
      m.classList.remove('show');
    });
    document.body.style.overflow = '';
  }
});

/* ── Tab switching ────────────────────────────────── */
function switchTab(btn, group) {
  document.querySelectorAll('[data-tab-group="' + group + '"] .tab-btn').forEach(function (b) {
    b.classList.remove('active');
  });
  document.querySelectorAll('[data-tab-target="' + group + '"]').forEach(function (c) {
    c.classList.remove('active');
  });
  btn.classList.add('active');
  var target = btn.getAttribute('data-tab');
  var content = document.getElementById(target);
  if (content) content.classList.add('active');
}

/* ── Auto-dismiss flash messages ─────────────────── */
document.addEventListener('DOMContentLoaded', function () {
  var flashes = document.querySelectorAll('.flash');
  flashes.forEach(function (f) {
    setTimeout(function () {
      f.style.transition = 'opacity .4s';
      f.style.opacity = '0';
      setTimeout(function () { f.remove(); }, 400);
    }, 3500);
  });
});

/* ── Toggle colour availability (inline AJAX) ─────── */
function toggleColour(form) {
  var fd = new FormData(form);
  fetch(form.action, { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) alert('Could not update colour.');
    })
    .catch(function () { alert('Network error.'); });
}

/* ── Confirm delete ───────────────────────────────── */
function confirmDelete(msg) {
  return confirm(msg || 'Are you sure you want to delete this item?');
}

/* ── Copy to clipboard ────────────────────────────── */
function copyCode(text) {
  navigator.clipboard.writeText(text).then(function () {
    var toast = document.createElement('div');
    toast.textContent = 'Copied!';
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#111827;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;z-index:9999;';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 1800);
  });
}

/* ── Status select auto-submit ────────────────────── */
document.addEventListener('change', function (e) {
  if (e.target.classList.contains('status-select-auto')) {
    e.target.closest('form').submit();
  }
});

/* ── Populate edit modal ──────────────────────────── */
function populateEditModal(modalId, data) {
  var modal = document.getElementById(modalId);
  if (!modal) return;
  Object.keys(data).forEach(function (key) {
    var el = modal.querySelector('[name="' + key + '"]');
    if (el) el.value = data[key];
  });
  openModal(modalId);
}