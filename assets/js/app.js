// assets/js/app.js
// Shared behaviour for every page in the app.

document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('sidebar');
  var mainContent = document.getElementById('mainContent');

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('show');
      if (mainContent && window.innerWidth > 768) {
        mainContent.style.marginLeft = sidebar.classList.contains('show') ? '0' : '250px';
      }
    });
  }

  // Any element with data-confirm="message" asks before submitting/navigating.
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (evt) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        evt.preventDefault();
      }
    });
  });
});
