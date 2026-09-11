// TeaEstate Pro — Main JS

// Sidebar toggle (mobile)
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebar-overlay').classList.toggle('open');
}

// Auto-dismiss flash messages after 4s
document.addEventListener('DOMContentLoaded', function() {
  var flashes = document.querySelectorAll('.flash');
  flashes.forEach(function(el) {
    setTimeout(function() {
      el.style.opacity = '0';
      el.style.transition = 'opacity 0.4s';
      setTimeout(function() { el.remove(); }, 400);
    }, 4000);
  });

  // Active nav highlight based on current page
  var path = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-item').forEach(function(item) {
    var href = item.getAttribute('href');
    if (href && href.split('/').pop() === path) {
      item.classList.add('active');
    }
  });
});

// Confirm dialogs
function confirmDelete(msg) {
  return confirm(msg || 'Are you sure you want to delete this?');
}

// Modal open/close (toggles the .open class — see .modal-overlay in app.css)
function openModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.remove('open');
}

// Estate switcher dropdown (topbar) — see .estate-switch in app.css
function toggleEstateDropdown(e) {
  e.stopPropagation();
  var el = document.getElementById('estate-switch');
  if (el) el.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  var el = document.getElementById('estate-switch');
  if (el && el.classList.contains('open') && !el.contains(e.target)) el.classList.remove('open');
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    var el = document.getElementById('estate-switch');
    if (el) el.classList.remove('open');
  }
});

// Smooth switch: fade the page out before the form's POST navigates away,
// so the estate change doesn't feel like an abrupt jump.
function fmSwitchEstate(form) {
  var btn = form.querySelector('button[type=submit]');
  if (btn) { btn.style.opacity = '0.6'; btn.style.pointerEvents = 'none'; btn.innerHTML += ' <i class="ti ti-loader-2" style="animation:spin .6s linear infinite"></i>'; }
  var content = document.querySelector('.content');
  if (content) { content.style.transition = 'opacity .25s ease'; content.style.opacity = '0'; }
  return true;
}
