/* ===================================================
   Creations by Athena – Admin Dashboard Scripts
   =================================================== */

/* ── Modal helpers ────────────────────────────────── */
var adminUnsavedGuard = {
  hasUnsavedChanges: false,
  isSubmitting: false,
  warningMessage: 'You have unsaved changes. Are you sure you want to leave this page?',
  trackedForms: [],
  trackedModals: [],
  refresh: function () {},
  snapshotForm: function () {},
  snapshotModal: function () {},
  formHasChanges: function () { return false; },
  modalHasChanges: function () { return false; },
  getModalForm: function (modal) {
    if (!modal) return null;
    var modalBox = modal.querySelector('.modal-box');
    if (!modalBox) return null;

    for (var i = 0; i < modalBox.children.length; i++) {
      if (modalBox.children[i].tagName === 'FORM') {
        return modalBox.children[i];
      }
    }

    return modalBox.querySelector('form');
  },
  markModalState: function (modal) {
    if (!modal) return;
    modal.dataset.unsaved = this.modalHasChanges(modal) ? 'true' : (modal.dataset.unsaved || 'false');
  },
  discardModalChanges: function (modal) {
    if (!modal) return;
    var form = this.getModalForm(modal);
    if (form) {
      this.snapshotForm(form);
    }
    this.snapshotModal(modal);
    modal.dataset.unsaved = 'false';
    this.hasUnsavedChanges = false;
    this.isSubmitting = false;
    this.refresh();
  },
  canDismissModal: function (modal) {
    if (!modal) return true;
    if (this.trackedModals.indexOf(modal) === -1) return true;
    this.markModalState(modal);
    if (modal.dataset.unsaved !== 'true') return true;
    if (!confirm(this.warningMessage)) return false;
    this.discardModalChanges(modal);
    return true;
  }
};

function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el && !adminUnsavedGuard.canDismissModal(el)) return;
  if (el) { el.classList.remove('show'); document.body.style.overflow = ''; }
}

// Close modal on outside click
document.addEventListener('click', function (e) {
  var openModal = e.target.closest('.modal-overlay.show');
  if (openModal && !e.target.closest('.modal-box')) {
    if (!adminUnsavedGuard.canDismissModal(openModal)) return;
    openModal.classList.remove('show');
    document.body.style.overflow = '';
  }
});

// Close modal on Escape key
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    var openModals = Array.prototype.slice.call(document.querySelectorAll('.modal-overlay.show'));
    var canCloseAll = openModals.every(function (m) {
      return adminUnsavedGuard.canDismissModal(m);
    });
    if (!canCloseAll) return;
    openModals.forEach(function (m) {
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

document.addEventListener('DOMContentLoaded', function () {
  var hasUnsavedChanges = false;
  var isSubmitting = false;
  var warningMessage = adminUnsavedGuard.warningMessage;

  function isTrackableField(field) {
    if (!field || field.disabled || !field.name) return false;
    if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.type === 'reset') return false;
    return true;
  }

  function isTrackedForm(form) {
    if (!form) return false;
    if ((form.method || '').toUpperCase() === 'GET') return false;
    if (form.matches('[data-ignore-unsaved-warning]')) return false;
    if (form.hasAttribute('data-auto-submit')) return false;
    if (form.querySelector('.status-select-auto')) return false;
    return Array.prototype.some.call(form.elements || [], isTrackableField);
  }

  function getFieldValue(field) {
    if (field.type === 'checkbox' || field.type === 'radio') {
      return field.checked ? '1' : '0';
    }
    if (field.tagName === 'SELECT' && field.multiple) {
      return Array.prototype.map.call(field.options, function (option) {
        return option.selected ? option.value : '';
      }).join('|');
    }
    if (field.type === 'file') {
      return field.value || '';
    }
    return field.value;
  }

  function snapshotForm(form) {
    var snapshot = {};
    Array.prototype.forEach.call(form.elements || [], function (field) {
      if (!isTrackableField(field)) return;
      snapshot[field.name] = getFieldValue(field);
    });
    form.__initialSnapshot = JSON.stringify(snapshot);
  }

  function formHasChanges(form) {
    var current = {};
    Array.prototype.forEach.call(form.elements || [], function (field) {
      if (!isTrackableField(field)) return;
      current[field.name] = getFieldValue(field);
    });
    return JSON.stringify(current) !== form.__initialSnapshot;
  }

  function getModalFields(modal) {
    if (!modal) return [];
    return Array.prototype.filter.call(
      modal.querySelectorAll('input, select, textarea'),
      function (field) {
        if (!isTrackableField(field)) return false;
        if (field.closest('[data-ignore-unsaved-warning]')) return false;
        return true;
      }
    );
  }

  function snapshotModal(modal) {
    var snapshot = {};
    getModalFields(modal).forEach(function (field) {
      snapshot[field.name] = getFieldValue(field);
    });
    modal.__initialSnapshot = JSON.stringify(snapshot);
    modal.dataset.unsaved = 'false';
  }

  function modalHasChanges(modal) {
    var current = {};
    getModalFields(modal).forEach(function (field) {
      current[field.name] = getFieldValue(field);
    });
    return JSON.stringify(current) !== modal.__initialSnapshot;
  }

  var trackedForms = Array.prototype.filter.call(document.forms, isTrackedForm);
  trackedForms.forEach(snapshotForm);
  var trackedModals = Array.prototype.filter.call(document.querySelectorAll('.modal-overlay'), function (modal) {
    return getModalFields(modal).length > 0;
  });
  trackedModals.forEach(snapshotModal);

  adminUnsavedGuard.trackedForms = trackedForms;
  adminUnsavedGuard.trackedModals = trackedModals;
  adminUnsavedGuard.snapshotForm = snapshotForm;
  adminUnsavedGuard.snapshotModal = snapshotModal;
  adminUnsavedGuard.formHasChanges = formHasChanges;
  adminUnsavedGuard.modalHasChanges = modalHasChanges;

  function refreshUnsavedState() {
    trackedModals.forEach(function (modal) {
      adminUnsavedGuard.markModalState(modal);
    });
    hasUnsavedChanges = trackedForms.some(formHasChanges) || trackedModals.some(function (modal) {
      return modal.dataset.unsaved === 'true' || modalHasChanges(modal);
    });
    adminUnsavedGuard.hasUnsavedChanges = hasUnsavedChanges;
  }

  adminUnsavedGuard.refresh = refreshUnsavedState;

  document.addEventListener('input', function (e) {
    var targetModal = e.target ? e.target.closest('.modal-overlay') : null;
    if (targetModal && trackedModals.indexOf(targetModal) !== -1) {
      targetModal.dataset.unsaved = 'true';
      refreshUnsavedState();
      return;
    }
    if (e.target && e.target.form && trackedForms.indexOf(e.target.form) !== -1) {
      refreshUnsavedState();
    }
  });

  document.addEventListener('change', function (e) {
    var targetModal = e.target ? e.target.closest('.modal-overlay') : null;
    if (targetModal && trackedModals.indexOf(targetModal) !== -1) {
      targetModal.dataset.unsaved = 'true';
      refreshUnsavedState();
      return;
    }
    if (e.target && e.target.form && trackedForms.indexOf(e.target.form) !== -1) {
      refreshUnsavedState();
    }
  });

  trackedForms.forEach(function (form) {
    form.addEventListener('submit', function () {
      isSubmitting = true;
      hasUnsavedChanges = false;
      adminUnsavedGuard.isSubmitting = true;
      adminUnsavedGuard.hasUnsavedChanges = false;
      var parentModal = form.closest('.modal-overlay');
      if (parentModal) parentModal.dataset.unsaved = 'false';
    });
  });

  window.addEventListener('beforeunload', function (e) {
    if (!hasUnsavedChanges || isSubmitting) return;
    e.preventDefault();
    e.returnValue = warningMessage;
  });

  document.addEventListener('click', function (e) {
    var link = e.target.closest('a[href]');
    var href = link ? (link.getAttribute('href') || '') : '';

    if (!link || !hasUnsavedChanges || isSubmitting) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    if (!href || href.charAt(0) === '#') return;

    if (!confirm(warningMessage)) {
      e.preventDefault();
    } else {
      isSubmitting = true;
      adminUnsavedGuard.isSubmitting = true;
    }
  });
});
