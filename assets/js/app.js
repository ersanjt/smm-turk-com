(function () {
  var themeKey = 'smmturk_theme';

  function applyThemeToggle() {
    document.body.classList.toggle('theme-dark');
    localStorage.setItem(themeKey, document.body.classList.contains('theme-dark') ? 'dark' : 'light');
  }

  if (!document.body.classList.contains('theme-dark')) {
    var prefersDark = localStorage.getItem(themeKey) === 'dark'
      || (!localStorage.getItem(themeKey) && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (prefersDark) document.body.classList.add('theme-dark');
  }

  document.querySelectorAll('.dash-theme-toggle, #dashThemeToggle').forEach(function (themeBtn) {
    themeBtn.addEventListener('click', applyThemeToggle);
  });

  var btn = document.getElementById('menuToggle');
  var mobMenuBtn = document.getElementById('mobNavMenuBtn');
  var mobHeaderMenuBtn = document.getElementById('mobHeaderMenuBtn');
  var overlay = document.getElementById('sidebarOverlay');
  var sidebar = document.getElementById('sidebar');

  function openSidebar() {
    document.body.classList.add('sidebar-open');
    if (overlay) overlay.setAttribute('aria-hidden', 'false');
  }

  function closeSidebar() {
    document.body.classList.remove('sidebar-open');
    if (overlay) overlay.setAttribute('aria-hidden', 'true');
  }

  function toggleSidebar() {
    document.body.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
  }

  var sidebarClose = document.getElementById('sidebarClose');
  if (btn) btn.addEventListener('click', toggleSidebar);
  if (mobMenuBtn) mobMenuBtn.addEventListener('click', openSidebar);
  if (mobHeaderMenuBtn) mobHeaderMenuBtn.addEventListener('click', openSidebar);
  if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);
  if (sidebar) {
    sidebar.querySelectorAll('.nav-item').forEach(function (el) {
      el.addEventListener('click', function () {
        if (window.innerWidth <= 768) closeSidebar();
      });
    });
  }
  window.addEventListener('resize', function () {
    if (window.innerWidth > 768) closeSidebar();
  });

  var reveal = document.querySelectorAll('[data-reveal]');
  if (reveal.length && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) e.target.classList.add('revealed');
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    reveal.forEach(function (el) { io.observe(el); });
  }
})();
