/* SMM Turk — Accessibility widget.
   Builds a floating "ease of use" control: text size, high contrast,
   reduce motion, dark mode. Preferences persist in localStorage.
   Also injects a skip-to-content link. Fully self-contained. */
(function () {
  'use strict';

  var T = window.A11Y_I18N || {};
  function t(key, fallback) { return T[key] || fallback; }

  var ZOOM_STEPS = [1, 1.15, 1.3, 1.5];
  var KEY_ZOOM = 'a11y_zoom';
  var KEY_CONTRAST = 'a11y_contrast';
  var KEY_MOTION = 'a11y_motion';
  var THEME_KEY = 'smmturk_theme';

  var root = document.documentElement;

  function getZoomIndex() {
    var v = parseInt(localStorage.getItem(KEY_ZOOM) || '0', 10);
    if (isNaN(v) || v < 0) v = 0;
    if (v >= ZOOM_STEPS.length) v = ZOOM_STEPS.length - 1;
    return v;
  }

  function applyZoom(idx) {
    var z = ZOOM_STEPS[idx] || 1;
    // `zoom` scales the whole UI (text + controls) and is well supported.
    root.style.zoom = z === 1 ? '' : String(z);
  }

  function applyContrast(on) { root.classList.toggle('a11y-contrast', !!on); }
  function applyMotion(on) { root.classList.toggle('a11y-reduce-motion', !!on); }
  function isDark() { return document.body.classList.contains('theme-dark'); }
  function applyDark(on) {
    document.body.classList.toggle('theme-dark', !!on);
    try { localStorage.setItem(THEME_KEY, on ? 'dark' : 'light'); } catch (e) {}
  }

  // Apply saved prefs as early as possible.
  applyZoom(getZoomIndex());
  applyContrast(localStorage.getItem(KEY_CONTRAST) === '1');
  applyMotion(localStorage.getItem(KEY_MOTION) === '1');

  function build() {
    if (document.querySelector('.a11y-fab')) return;

    // Skip-to-content link.
    var main = document.querySelector('main');
    if (main && !main.id) main.id = 'main-content';
    if (main) {
      var skip = document.createElement('a');
      skip.className = 'a11y-skip';
      skip.href = '#' + main.id;
      skip.textContent = t('skip', 'Skip to content');
      document.body.insertBefore(skip, document.body.firstChild);
    }

    var fab = document.createElement('button');
    fab.type = 'button';
    fab.className = 'a11y-fab';
    fab.setAttribute('aria-label', t('open', 'Accessibility options'));
    fab.setAttribute('aria-haspopup', 'dialog');
    fab.setAttribute('aria-expanded', 'false');
    fab.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="4" r="1.6" fill="currentColor" stroke="none"/><path d="M4 7h16"/><path d="M9 7l1 6"/><path d="M15 7l-1 6"/><path d="M10 13l-1.5 6"/><path d="M14 13l1.5 6"/></svg>';

    var panel = document.createElement('div');
    panel.className = 'a11y-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', t('title', 'Accessibility'));
    panel.innerHTML =
      '<button type="button" class="a11y-panel-close" aria-label="' + esc(t('close', 'Close')) + '">&times;</button>' +
      '<p class="a11y-panel-title">\u267F ' + esc(t('title', 'Ease of use')) + '</p>' +
      '<div class="a11y-row">' +
        '<div class="a11y-row-label">' + esc(t('text_size', 'Text size')) + '</div>' +
        '<div class="a11y-size-btns">' +
          '<button type="button" data-a11y="smaller" aria-label="' + esc(t('smaller', 'Smaller text')) + '"><span class="small">A\u2212</span></button>' +
          '<button type="button" data-a11y="reset-size" aria-label="' + esc(t('reset_size', 'Normal text')) + '">A</button>' +
          '<button type="button" data-a11y="bigger" aria-label="' + esc(t('bigger', 'Bigger text')) + '"><span class="big">A+</span></button>' +
        '</div>' +
      '</div>' +
      '<button type="button" class="a11y-toggle" data-a11y="contrast" aria-pressed="false"><span>' + esc(t('contrast', 'High contrast')) + '</span><span class="a11y-switch" aria-hidden="true"></span></button>' +
      '<button type="button" class="a11y-toggle" data-a11y="dark" aria-pressed="false"><span>' + esc(t('dark', 'Dark mode')) + '</span><span class="a11y-switch" aria-hidden="true"></span></button>' +
      '<button type="button" class="a11y-toggle" data-a11y="motion" aria-pressed="false"><span>' + esc(t('motion', 'Reduce motion')) + '</span><span class="a11y-switch" aria-hidden="true"></span></button>' +
      '<button type="button" class="a11y-reset" data-a11y="reset-all">' + esc(t('reset', 'Reset everything')) + '</button>';

    document.body.appendChild(fab);
    document.body.appendChild(panel);

    function syncToggles() {
      panel.querySelector('[data-a11y="contrast"]').setAttribute('aria-pressed', root.classList.contains('a11y-contrast') ? 'true' : 'false');
      panel.querySelector('[data-a11y="motion"]').setAttribute('aria-pressed', root.classList.contains('a11y-reduce-motion') ? 'true' : 'false');
      panel.querySelector('[data-a11y="dark"]').setAttribute('aria-pressed', isDark() ? 'true' : 'false');
    }
    syncToggles();

    function openPanel() { panel.classList.add('open'); fab.setAttribute('aria-expanded', 'true'); }
    function closePanel() { panel.classList.remove('open'); fab.setAttribute('aria-expanded', 'false'); }
    fab.addEventListener('click', function () {
      panel.classList.contains('open') ? closePanel() : openPanel();
    });

    panel.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-a11y]');
      if (!btn) return;
      var action = btn.getAttribute('data-a11y');
      var idx = getZoomIndex();
      if (action === 'bigger') { idx = Math.min(idx + 1, ZOOM_STEPS.length - 1); localStorage.setItem(KEY_ZOOM, idx); applyZoom(idx); }
      else if (action === 'smaller') { idx = Math.max(idx - 1, 0); localStorage.setItem(KEY_ZOOM, idx); applyZoom(idx); }
      else if (action === 'reset-size') { localStorage.setItem(KEY_ZOOM, 0); applyZoom(0); }
      else if (action === 'contrast') { var c = !root.classList.contains('a11y-contrast'); applyContrast(c); localStorage.setItem(KEY_CONTRAST, c ? '1' : '0'); }
      else if (action === 'motion') { var m = !root.classList.contains('a11y-reduce-motion'); applyMotion(m); localStorage.setItem(KEY_MOTION, m ? '1' : '0'); }
      else if (action === 'dark') { applyDark(!isDark()); }
      else if (action === 'reset-all') {
        localStorage.removeItem(KEY_ZOOM); localStorage.removeItem(KEY_CONTRAST); localStorage.removeItem(KEY_MOTION);
        applyZoom(0); applyContrast(false); applyMotion(false);
      }
      syncToggles();
    });

    panel.querySelector('.a11y-panel-close').addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePanel(); });
    document.addEventListener('click', function (e) {
      if (panel.classList.contains('open') && !panel.contains(e.target) && !fab.contains(e.target)) closePanel();
    });
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
