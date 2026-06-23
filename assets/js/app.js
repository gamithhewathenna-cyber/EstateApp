// TeaEstate Pro — Main JS

// Sidebar toggle (mobile)
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('open');
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
