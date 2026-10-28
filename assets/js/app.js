// assets/js/app.js
// Shared behaviour for every page in the app.

document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('sidebar');
  var closeButton = document.getElementById('sidebarClose');
  var backdrop = document.getElementById('sidebarBackdrop');
  var desktopBreakpoint = 992;

  function isMobileNavigation() {
    return window.innerWidth < desktopBreakpoint;
  }

  function syncSidebarState() {
    if (!toggle || !sidebar) return;

    var isVisible = isMobileNavigation()
      ? document.body.classList.contains('sidebar-open')
      : !document.body.classList.contains('sidebar-collapsed');

    toggle.setAttribute('aria-expanded', isVisible ? 'true' : 'false');
    sidebar.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      if (isMobileNavigation()) {
        document.body.classList.toggle('sidebar-open');
      } else {
        document.body.classList.toggle('sidebar-collapsed');
      }
      syncSidebarState();
    });

    syncSidebarState();
  }

  function closeMobileSidebar() {
    document.body.classList.remove('sidebar-open');
    syncSidebarState();
  }

  if (closeButton) closeButton.addEventListener('click', closeMobileSidebar);
  if (backdrop) backdrop.addEventListener('click', closeMobileSidebar);

  sidebar && sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      if (isMobileNavigation()) closeMobileSidebar();
    });
  });

  document.addEventListener('keydown', function (evt) {
    if (evt.key === 'Escape') closeMobileSidebar();
  });

  window.addEventListener('resize', function () {
    if (!isMobileNavigation()) document.body.classList.remove('sidebar-open');
    syncSidebarState();
  });

  // Any element with data-confirm="message" asks before submitting/navigating.
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (evt) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        evt.preventDefault();
      }
    });
  });
});
