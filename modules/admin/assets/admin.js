

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

document.addEventListener('click', function (e) {
  var openModal = e.target.closest('.modal-overlay.show');
  if (openModal && !e.target.closest('.modal-box')) {
    if (!adminUnsavedGuard.canDismissModal(openModal)) return;
    openModal.classList.remove('show');
    document.body.style.overflow = '';
  }
});

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

  var activeInput = document.querySelector('[data-active-tab-input="' + group + '"]');
  if (activeInput) {
    activeInput.value = btn.getAttribute('data-tab-key') || target || '';
  }
}

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

function confirmDelete(msg) {
  return window.confirm(msg || 'Are you sure you want to delete or remove this item?');
}

window.athinaConfirmDelete = confirmDelete;

(function () {
  var destructivePattern = /(^|[\s_\-])(delete|remove|clear|dismiss|disconnect)([\s_\-]|$)/i;

  function getSubmitter(event, form) {
    if (event.submitter && form.contains(event.submitter)) return event.submitter;
    var active = document.activeElement;
    if (active && form.contains(active) && /^(BUTTON|INPUT)$/i.test(active.tagName || '')) return active;
    return null;
  }

  function isSubmitControl(trigger) {
    if (!trigger || !trigger.closest('form') || !/^(BUTTON|INPUT)$/i.test(trigger.tagName || '')) return false;
    var type = String(trigger.getAttribute('type') || (trigger.tagName === 'BUTTON' ? 'submit' : '')).toLowerCase();
    return type === 'submit' || type === 'image';
  }

  function hasInlineConfirm(form, submitter) {
    var formHandler = form.getAttribute('onsubmit') || '';
    var submitterHandler = submitter ? (submitter.getAttribute('onclick') || '') : '';
    return /confirm\s*\(|confirmDelete\s*\(/i.test(formHandler + ' ' + submitterHandler);
  }

  function isSkippedForm(form) {
    return form.hasAttribute('data-skip-delete-confirmation') ||
      form.classList.contains('colour-delete-form');
  }

  function addFormSignals(signals, form, submitter) {
    var fields = form.querySelectorAll('input, button, select, textarea');
    Array.prototype.forEach.call(fields, function (field) {
      if (field.disabled) return;
      var type = String(field.type || '').toLowerCase();
      if (type !== 'hidden' && type !== 'submit' && type !== 'button') return;
      if ((type === 'submit' || type === 'button') && (!submitter || field !== submitter)) return;
      if (field.name) signals.push(field.name);
      if (field.value) signals.push(field.value);
      if (field === submitter && field.textContent) signals.push(field.textContent);
    });
  }

  function signalText(form, submitter) {
    var signals = [
      form.getAttribute('action') || '',
      form.getAttribute('data-confirm-delete') || '',
      form.getAttribute('data-confirm-message') || ''
    ];
    if (submitter) {
      signals.push(submitter.getAttribute('data-confirm-delete') || '');
      signals.push(submitter.getAttribute('data-confirm-message') || '');
      signals.push(submitter.getAttribute('aria-label') || '');
      signals.push(submitter.getAttribute('title') || '');
      signals.push(submitter.textContent || '');
    }
    addFormSignals(signals, form, submitter);
    return signals.join(' ').replace(/\s+/g, ' ').trim();
  }

  function isExplicit(form, submitter) {
    return form.hasAttribute('data-confirm-delete') ||
      !!(submitter && submitter.hasAttribute('data-confirm-delete'));
  }

  function shouldConfirmForm(form, submitter) {
    if ((form.method || 'get').toLowerCase() === 'get') return false;
    if (isExplicit(form, submitter)) return true;
    return destructivePattern.test(signalText(form, submitter));
  }

  function messageFor(form, submitter) {
    var explicit = (submitter && submitter.getAttribute('data-confirm-message')) ||
      form.getAttribute('data-confirm-message');
    if (explicit) return explicit;

    var text = signalText(form, submitter).toLowerCase();
    if (text.indexOf('notification') !== -1 || text.indexOf('dismiss') !== -1) {
      return 'Dismiss this notification?';
    }
    if (text.indexOf('cost') !== -1) {
      return 'Delete this cost entry?';
    }
    if (text.indexOf('customer') !== -1 || text.indexOf('user') !== -1) {
      return 'Delete this customer account?';
    }
    if (text.indexOf('promotion') !== -1 || text.indexOf('discount') !== -1) {
      return 'Delete this promotion?';
    }
    if (text.indexOf('colour') !== -1 || text.indexOf('color') !== -1) {
      return 'Delete this colour?';
    }
    if (text.indexOf('photo') !== -1) {
      return 'Delete this photo?';
    }
    if (text.indexOf('disconnect') !== -1) {
      return 'Disconnect this integration?';
    }
    return 'Are you sure you want to delete or remove this item?';
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.tagName !== 'FORM') return;
    var submitter = getSubmitter(event, form);
    if (isSkippedForm(form) || hasInlineConfirm(form, submitter)) return;
    if (!shouldConfirmForm(form, submitter)) return;
    if (!confirmDelete(messageFor(form, submitter))) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest('[data-confirm-delete]');
    if (!trigger || isSubmitControl(trigger)) return;
    if (/confirm\s*\(|confirmDelete\s*\(/i.test(trigger.getAttribute('onclick') || '')) return;
    if (!confirmDelete(trigger.getAttribute('data-confirm-message') || 'Are you sure you want to delete or remove this item?')) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);
})();

function copyCode(text) {
  navigator.clipboard.writeText(text).then(function () {
    var toast = document.createElement('div');
    toast.textContent = 'Copied!';
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#111827;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;z-index:9999;';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 1800);
  });
}

document.addEventListener('change', function (e) {
  if (e.target.classList.contains('status-select-auto')) {
    e.target.closest('form').submit();
  }
});

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
