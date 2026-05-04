    </div><!-- /content-area -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<div id="toast-container"></div>

<script>
function toggleSidebar() {
  const s = document.getElementById('sidebar');
  s.style.transform = s.style.transform === 'translateX(-100%)' ? '' : 'translateX(-100%)';
}

function showToast(message, type = 'info', duration = 3500) {
  const icons = { success:'✅', error:'❌', info:'ℹ️', warning:'⚠️' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${icons[type]||'ℹ️'}</span><span>${message}</span>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(() => el.remove(), duration);
}

// Confirm delete helper
function confirmAction(message, url) {
  if (confirm(message)) window.location.href = url;
}
</script>
</body>
</html>
