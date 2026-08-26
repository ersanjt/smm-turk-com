(function () {
  var themeKey = 'smmturk_theme';
  var dark = localStorage.getItem(themeKey) === 'dark'
    || (!localStorage.getItem(themeKey) && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  if (dark) document.body.classList.add('theme-dark');

  function toggleTheme() {
    document.body.classList.toggle('theme-dark');
    localStorage.setItem(themeKey, document.body.classList.contains('theme-dark') ? 'dark' : 'light');
  }

  ['themeToggle', 'themeToggleMobile'].forEach(function (id) {
    var btn = document.getElementById(id);
    if (btn) btn.addEventListener('click', toggleTheme);
  });

  var langBtn = document.getElementById('langBtn');
  var langDropdown = document.getElementById('langDropdown');
  if (langBtn && langDropdown) {
    langBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = langDropdown.classList.toggle('open');
      langBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('.nav-lang')) {
        langDropdown.classList.remove('open');
        langBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  var navToggle = document.getElementById('navToggle');
  var navPanel = document.getElementById('navMobilePanel');
  var navClose = document.getElementById('navClose');
  var navBackdrop = document.getElementById('navBackdrop');

  function setNavOpen(open) {
    document.body.classList.toggle('nav-open', open);
    if (navToggle) navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (navPanel) {
      navPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
      // Closed drawer must not keep focusable descendants (Lighthouse / a11y tree)
      if (open) {
        navPanel.removeAttribute('inert');
      } else {
        navPanel.setAttribute('inert', '');
      }
    }
    if (navToggle) navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    if (open && navClose) {
      try { navClose.focus(); } catch (e) {}
    }
  }

  function closeNav() { setNavOpen(false); }

  if (navToggle && navPanel) {
    navToggle.addEventListener('click', function () {
      setNavOpen(!document.body.classList.contains('nav-open'));
    });
    if (navClose) navClose.addEventListener('click', closeNav);
    if (navBackdrop) navBackdrop.addEventListener('click', closeNav);
    navPanel.querySelectorAll('.nav-mobile-link, .nav-mobile-btn').forEach(function (link) {
      link.addEventListener('click', closeNav);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeNav();
    });
    window.addEventListener('resize', function () {
      if (window.innerWidth > 768) closeNav();
    });
  }

  document.querySelectorAll('.faq-q').forEach(function (el) {
    if (el.tagName !== 'BUTTON') return;
    el.addEventListener('click', function () {
      var item = this.closest('.faq-item');
      var open = item.classList.contains('open');
      document.querySelectorAll('.faq-item').forEach(function (i) {
        i.classList.remove('open');
        var q = i.querySelector('.faq-q');
        if (q) q.setAttribute('aria-expanded', 'false');
      });
      if (!open) {
        item.classList.add('open');
        this.setAttribute('aria-expanded', 'true');
      }
    });
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        this.click();
      }
    });
  });

  /* Promo height — defer geometry read to avoid forced reflow during parse */
  function syncPromoHeight() {
    var bar = document.querySelector('.growth-promo-bar');
    if (!bar || !document.body.classList.contains('has-promo')) return;
    requestAnimationFrame(function () {
      var h = Math.ceil(bar.getBoundingClientRect().height);
      if (h > 0) document.documentElement.style.setProperty('--promo-bar-h', h + 'px');
    });
  }
  if (document.body.classList.contains('has-promo')) {
    if ('requestIdleCallback' in window) {
      requestIdleCallback(function () { syncPromoHeight(); }, { timeout: 400 });
    } else {
      setTimeout(syncPromoHeight, 0);
    }
  }
  window.addEventListener('resize', syncPromoHeight);
})();
