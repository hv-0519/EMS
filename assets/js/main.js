// ============================================================
// EmpAxis – Main JavaScript
// ============================================================

'use strict';

let themeSwitchInProgress = false;

// Apply persisted theme as soon as possible
setTheme(getPreferredTheme(), false);

// ── DOM Ready ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initThemeToggle();
  initSidebar();
  initDropdowns();
  initModals();
  initActionUX();
  initLiveClock();
  initToasts();
  initTableSearch();
  initFileUpload();
  initFormValidation();
});

// ── Theme Toggle ───────────────────────────────────────────
function initThemeToggle() {
  const buttons = Array.from(document.querySelectorAll('[data-theme-toggle]'));
  if (!buttons.length) return;

  buttons.forEach(updateThemeButton);
  buttons.forEach(btn => btn.addEventListener('click', () => {
    if (themeSwitchInProgress) return;
    themeSwitchInProgress = true;
    buttons.forEach(b => b.disabled = true);

    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    showThemeSwitchOverlay(next);

    const switchTheme = () => {
      setTheme(next, true);
      buttons.forEach(updateThemeButton);
    };
    const finish = () => {
      setTimeout(() => {
        hideThemeSwitchOverlay();
        buttons.forEach(b => b.disabled = false);
        themeSwitchInProgress = false;
      }, 420);
    };

    if (document.startViewTransition) {
      document.documentElement.classList.add('theme-animating');
      document.startViewTransition(() => {
        switchTheme();
      }).finished.finally(() => {
        document.documentElement.classList.remove('theme-animating');
        finish();
      });
      return;
    }

    switchTheme();
    finish();
  }));
}

function getPreferredTheme() {
  const saved = localStorage.getItem('theme');
  if (saved === 'light' || saved === 'dark') return saved;
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function setTheme(theme, persist = true) {
  document.documentElement.setAttribute('data-theme', theme);
  document.body.classList.toggle('dark-mode', theme === 'dark');
  if (persist) localStorage.setItem('theme', theme);
}

function updateThemeButton(btn) {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const title = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
  btn.setAttribute('aria-label', title);
  btn.setAttribute('title', title);

  const sidebarLabel = btn.querySelector('.sidebar-theme-text');
  if (sidebarLabel) {
    sidebarLabel.textContent = isDark ? 'Light Mode' : 'Dark Mode';
  }
}

function getThemeSwitchOverlay() {
  let overlay = document.getElementById('theme-switch-overlay');
  if (overlay) return overlay;

  const html = `
    <div class="theme-switch-overlay" id="theme-switch-overlay" aria-hidden="true">
      <div class="theme-switch-card">
        <div class="theme-switch-icon" id="theme-switch-icon"></div>
        <div class="theme-switch-title" id="theme-switch-title">Switching appearance</div>
        <div class="theme-switch-subtitle">Applying visual theme...</div>
        <div class="theme-switch-bar"><span></span></div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', html);
  overlay = document.getElementById('theme-switch-overlay');
  return overlay;
}

function showThemeSwitchOverlay(nextTheme) {
  const overlay = getThemeSwitchOverlay();
  const title = document.getElementById('theme-switch-title');
  const icon = document.getElementById('theme-switch-icon');
  const label = nextTheme === 'dark' ? 'Switching to Dark Mode' : 'Switching to Light Mode';
  const iconClass = nextTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';

  if (title) title.textContent = label;
  if (icon) icon.innerHTML = `<i class="${iconClass}"></i>`;

  overlay.classList.add('active');
  overlay.setAttribute('aria-hidden', 'false');
}

function hideThemeSwitchOverlay() {
  const overlay = document.getElementById('theme-switch-overlay');
  if (!overlay) return;
  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
}

// ── Global Action UX (Premium loader + nav/form/fetch hooks) ─────────────────
const ACTION_LOADER_MIN_MS = 2500;
const actionLoaderState = {
  visible: false,
  shownAt: 0,
  pending: 0
};

function ensureActionLoader() {
  let overlay = document.getElementById('action-loader-overlay');
  if (overlay) return overlay;

  const html = `
    <div id="action-loader-overlay" class="action-loader-overlay" aria-hidden="true">
      <div class="action-loader-card">
        <div class="action-loader-ring"><i class="fas fa-bolt"></i></div>
        <div class="action-loader-title">Processing request</div>
        <div class="action-loader-subtitle">Please wait while we complete your action...</div>
        <div class="action-loader-bar"><span></span></div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', html);
  return document.getElementById('action-loader-overlay');
}

function showActionLoader() {
  const overlay = ensureActionLoader();
  actionLoaderState.pending += 1;
  if (!actionLoaderState.visible) {
    actionLoaderState.visible = true;
    actionLoaderState.shownAt = Date.now();
    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
  }
}

async function hideActionLoader(force = false) {
  const overlay = document.getElementById('action-loader-overlay');
  if (!overlay) return;
  actionLoaderState.pending = force ? 0 : Math.max(0, actionLoaderState.pending - 1);
  if (actionLoaderState.pending > 0 && !force) return;

  const elapsed = Date.now() - actionLoaderState.shownAt;
  const waitMs = Math.max(0, ACTION_LOADER_MIN_MS - elapsed);
  if (waitMs > 0) await new Promise(resolve => setTimeout(resolve, waitMs));

  overlay.classList.remove('active');
  overlay.setAttribute('aria-hidden', 'true');
  actionLoaderState.visible = false;
}

function shouldInterceptLink(anchor, event) {
  if (!anchor || !(anchor instanceof HTMLAnchorElement)) return false;
  if (anchor.dataset.noLoader === 'true') return false;
  if (event.defaultPrevented) return false;
  if (event.button !== 0) return false;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
  if (anchor.target && anchor.target !== '_self') return false;
  if (anchor.hasAttribute('download')) return false;
  const href = anchor.getAttribute('href') || '';
  if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;

  try {
    const url = new URL(anchor.href, window.location.origin);
    if (url.origin !== window.location.origin) return false;
  } catch {
    return false;
  }
  return true;
}

function initActionUX() {
  // Keep logout confirmation, but do not show any loading overlays.
  document.addEventListener('click', e => {
    const anchor = e.target instanceof Element ? e.target.closest('a[href]') : null;
    if (!shouldInterceptLink(anchor, e)) return;
    const href = anchor.getAttribute('href') || '';
    if (!/\/auth\/logout\.php(?:$|\?)/.test(href)) return;

    e.preventDefault();
    confirmDialog(
      'You are about to sign out from EmpAxis. Any unsaved changes may be lost.',
      () => { window.location.href = anchor.href; },
      'Logout Confirmation',
      { tone: 'warning', confirmText: 'Logout', icon: 'fa-right-from-bracket' }
    );
  });
}

// ── Loading Screen ─────────────────────────────────────────
function initLoadingScreen() {
  // Loading screen removed globally.
}

// ── Sidebar ─────────────────────────────────────────────────
function initSidebar() {
  const sidebar   = document.getElementById('sidebar');
  const toggleBtn = document.getElementById('toggle-sidebar');
  const hamburger = document.getElementById('hamburger-btn');
  const overlay   = document.getElementById('sidebar-overlay');
  if (!sidebar) return;
  const isMobile = () => window.matchMedia('(max-width: 768px)').matches;
  const openMobileSidebar = () => {
    sidebar.classList.add('mobile-open');
    if (overlay) overlay.style.display = 'block';
  };
  const closeMobileSidebar = () => {
    sidebar.classList.remove('mobile-open');
    if (overlay) overlay.style.display = '';
  };

  // Collapse (desktop)
  toggleBtn?.addEventListener('click', () => {
    if (isMobile()) {
      closeMobileSidebar();
      return;
    }
    sidebar.classList.toggle('collapsed');
    const icon = toggleBtn.querySelector('i');
    if (icon) {
      icon.className = sidebar.classList.contains('collapsed')
        ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
    }
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
  });

  // Restore state
  if (localStorage.getItem('sidebarCollapsed') === 'true') {
    sidebar.classList.add('collapsed');
  }

  // Mobile
  hamburger?.addEventListener('click', () => {
    if (sidebar.classList.contains('mobile-open')) closeMobileSidebar();
    else openMobileSidebar();
  });
  overlay?.addEventListener('click', () => {
    closeMobileSidebar();
  });

  // Close sidebar on mobile when tapping outside menu.
  document.addEventListener('click', e => {
    if (!isMobile() || !sidebar.classList.contains('mobile-open')) return;
    const target = e.target;
    if (!(target instanceof Element)) return;
    if (sidebar.contains(target) || hamburger?.contains(target)) return;
    closeMobileSidebar();
  });

  // Close sidebar on mobile when any nav link is tapped.
  sidebar.querySelectorAll('.nav-item').forEach(link => {
    link.addEventListener('click', () => {
      if (isMobile()) closeMobileSidebar();
    });
  });

  window.addEventListener('resize', () => {
    if (!isMobile()) {
      closeMobileSidebar();
    }
  });
}

// ── Dropdown ────────────────────────────────────────────────
function initDropdowns() {
  document.querySelectorAll('.dropdown').forEach(dd => {
    const trigger = dd.querySelector('.dropdown-trigger');
    trigger?.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = dd.classList.contains('open');
      document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
      if (!isOpen) dd.classList.add('open');
    });
  });
  document.addEventListener('click', () => {
    document.querySelectorAll('.dropdown.open').forEach(d => d.classList.remove('open'));
  });
}

// ── Modals ──────────────────────────────────────────────────
function initModals() {
  // Close via overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
      if (e.target === overlay) closeModal(overlay.id);
    });
  });
  // Close via button
  document.querySelectorAll('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.closest('.modal-overlay')?.id;
      if (id) closeModal(id);
    });
  });
  // ESC
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.open, .modal-overlay.active').forEach(m => {
        closeModal(m.id);
      });
    }
  });
}

function openModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.add('open');
    el.classList.add('active'); // Support premium modal system (.active)
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('open');
    el.classList.remove('active'); // Support premium modal system (.active)
    document.body.style.overflow = '';
  }
}

// ── Live Clock ───────────────────────────────────────────────
function initLiveClock() {
  const el = document.getElementById('live-clock');
  if (!el) return;
  function tick() {
    const now = new Date();
    el.textContent = now.toLocaleTimeString('en-IN', {
      hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
  }
  tick();
  setInterval(tick, 1000);
}

// ── Toasts ───────────────────────────────────────────────────
function initToasts() {
  // Container created lazily
}

function showToast(message, type = 'success', duration = 3500) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  const icons = { success: 'fa-check-circle', error: 'fa-times-circle',
                  warning: 'fa-exclamation-circle', info: 'fa-info-circle' };
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i> <span>${message}</span>`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.classList.add('removing');
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

// ── Confirm Dialog ───────────────────────────────────────────
function confirmDialog(message, onConfirm, title = 'Are you sure?', options = {}) {
  const tone = options.tone || 'danger';
  const confirmText = options.confirmText || 'Delete';
  const icon = options.icon || 'fa-trash';
  const subtitle = options.subtitle || 'Please confirm this action';
  const overlay = document.getElementById('confirm-modal');
  if (!overlay) {
    // Create on-the-fly
    const html = `
      <div id="confirm-modal" class="modal-overlay">
        <div class="modal-dialog modal-dialog-sm modal-danger" id="confirm-dialog">
          <div class="modal-header">
            <div class="modal-icon danger" id="confirm-icon"><i class="fas fa-trash"></i></div>
            <div class="modal-title-wrap">
              <h3 class="modal-title" id="confirm-title"></h3>
              <div class="modal-subtitle" id="confirm-subtitle">Please confirm this action</div>
            </div>
            <button class="modal-close-btn" id="confirm-close-btn" type="button"><i class="fas fa-times"></i></button>
          </div>
          <div class="modal-body">
            <div class="delete-confirm-msg" id="confirm-msg"></div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" id="confirm-cancel" type="button">Cancel</button>
            <button class="btn btn-danger" id="confirm-ok" type="button">Delete</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('confirm-cancel').addEventListener('click', () => closeModal('confirm-modal'));
    document.getElementById('confirm-close-btn').addEventListener('click', () => closeModal('confirm-modal'));
  }
  const dialog = document.getElementById('confirm-dialog');
  const iconWrap = document.getElementById('confirm-icon');
  const iconNode = iconWrap?.querySelector('i');
  if (dialog) {
    dialog.classList.remove('modal-danger', 'modal-warning', 'modal-success');
    dialog.classList.add(tone === 'warning' ? 'modal-warning' : tone === 'success' ? 'modal-success' : 'modal-danger');
  }
  if (iconWrap) {
    iconWrap.classList.remove('danger', 'warning', 'success', 'primary');
    iconWrap.classList.add(tone === 'warning' ? 'warning' : tone === 'success' ? 'success' : 'danger');
  }
  if (iconNode) iconNode.className = `fas ${icon}`;
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-subtitle').textContent = subtitle;
  document.getElementById('confirm-msg').textContent   = message;
  const okBtn = document.getElementById('confirm-ok');
  okBtn.textContent = confirmText;
  okBtn.className = `btn ${tone === 'warning' ? 'btn-warning' : tone === 'success' ? 'btn-success' : 'btn-danger'}`;
  const fresh = okBtn.cloneNode(true);
  okBtn.replaceWith(fresh);
  fresh.addEventListener('click', () => {
    closeModal('confirm-modal');
    if (typeof onConfirm === 'function') onConfirm();
  });
  openModal('confirm-modal');
}

// ── Table Search + Pagination ────────────────────────────────
function initTableSearch() {
  document.querySelectorAll('[data-table-search]').forEach(input => {
    const targetId = input.dataset.tableSearch;
    const table    = document.getElementById(targetId);
    if (!table) return;
    input.addEventListener('input', () => {
      const q = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
      });
    });
  });
}

// ── File Upload ──────────────────────────────────────────────
function initFileUpload() {
  document.querySelectorAll('.file-upload-area').forEach(area => {
    const input = area.querySelector('input[type=file]');
    area.addEventListener('click', () => input?.click());
    area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('drag-over'); });
    area.addEventListener('dragleave', () => area.classList.remove('drag-over'));
    area.addEventListener('drop', e => {
      e.preventDefault(); area.classList.remove('drag-over');
      if (input && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        updateFileLabel(area, e.dataTransfer.files[0].name);
      }
    });
    input?.addEventListener('change', () => {
      if (input.files[0]) updateFileLabel(area, input.files[0].name);
    });
  });
}
function updateFileLabel(area, name) {
  const p = area.querySelector('p');
  if (p) p.textContent = name;
}

// ── Form Validation ─────────────────────────────────────────
function initFormValidation() {
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', e => {
      let valid = true;
      form.querySelectorAll('[required]').forEach(field => {
        if (!field.value.trim()) {
          showFieldError(field, 'This field is required');
          valid = false;
        } else {
          clearFieldError(field);
        }
      });
      if (!valid) e.preventDefault();
    });
  });
}
function showFieldError(field, msg) {
  field.classList.add('error');
  let err = field.parentElement.querySelector('.form-error');
  if (!err) {
    err = document.createElement('div');
    err.className = 'form-error';
    field.parentElement.appendChild(err);
  }
  err.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${msg}`;
}
function clearFieldError(field) {
  field.classList.remove('error');
  field.parentElement.querySelector('.form-error')?.remove();
}

// ── AJAX helper ─────────────────────────────────────────────
async function apiPost(url, data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  const res = await fetch(url, { method: 'POST', body: fd });
  return res.json();
}

// ── Expose globals ──────────────────────────────────────────
window.openModal     = openModal;
window.closeModal    = closeModal;
window.showToast     = showToast;
window.confirmDialog = confirmDialog;
window.apiPost       = apiPost;
