<?php
// Shared layout helper for Client/Business panel
function clientLayout(string $pageTitle, string $currentPage): void {
    $db  = getDB();
    $tid = currentUser()['tenant_id'];
    $stmt = $db->prepare("SELECT * FROM tenants WHERE id=?"); $stmt->execute([$tid]);
    $tenant = $stmt->fetch();

    $navItems = [
        ['icon'=>'📊','label'=>'Dashboard',  'file'=>'dashboard', 'href'=>'dashboard.php'],
        ['icon'=>'🏪','label'=>'Products',   'file'=>'products',  'href'=>'products.php'],
        ['icon'=>'📦','label'=>'Inventory',  'file'=>'inventory', 'href'=>'inventory.php'],
        ['icon'=>'👥','label'=>'Customers',  'file'=>'customers', 'href'=>'customers.php'],
        ['icon'=>'🚚','label'=>'Suppliers',  'file'=>'suppliers', 'href'=>'suppliers.php'],
        ['icon'=>'🏢','label'=>'Branches',   'file'=>'branches',  'href'=>'branches.php'],
        ['icon'=>'👤','label'=>'Users',      'file'=>'users',     'href'=>'users.php'],
        ['icon'=>'📈','label'=>'Reports',    'file'=>'reports',   'href'=>'reports.php'],
        ['icon'=>'⚙️','label'=>'Settings',  'file'=>'settings',  'href'=>'settings.php'],
    ];

    // Low stock count for badge
    $lowStock = $db->prepare("SELECT COUNT(*) FROM stock_levels sl JOIN products p ON p.id=sl.product_id WHERE sl.tenant_id=? AND sl.quantity <= sl.low_stock_threshold AND p.track_stock=1");
    $lowStock->execute([$tid]); $lowStock = $lowStock->fetchColumn();

    $typeIcons=['supermarket'=>'🛒','pharmacy'=>'💊','wine_shop'=>'🍷','retail'=>'🏪','restaurant'=>'🍽️','wholesale'=>'📦','other'=>'🏢'];
    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($tenant['business_name']) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <?php if ($tenant['logo_path']): ?>
  <link rel="icon" type="image/png" href="<?= APP_URL ?>/uploads/logos/<?= htmlspecialchars($tenant['logo_path']) ?>"/>
  <?php endif; ?>
</head>
<body>
<div class="app-layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon"><?= $typeIcons[$tenant['business_type']] ?? '🏪' ?></div>
      <div>
        <div class="sidebar-logo-text"><?= htmlspecialchars($tenant['business_name']) ?></div>
        <div class="sidebar-logo-sub"><?= ucfirst(str_replace('_',' ',$tenant['business_type'])) ?></div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Business</div>
      <?php foreach ($navItems as $item): ?>
        <a href="<?= APP_URL ?>/modules/client/<?= $item['href'] ?>"
           class="nav-item <?= $currentPage===$item['file']?'active':'' ?>">
          <span class="nav-icon"><?= $item['icon'] ?></span>
          <span class="nav-label"><?= $item['label'] ?></span>
          <?php if ($item['file']==='inventory' && $lowStock>0): ?><span class="nav-badge"><?= $lowStock ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <div class="nav-section-label" style="margin-top:16px;">POS Terminal</div>
      <a href="<?= APP_URL ?>/modules/pos/index.php" class="nav-item" style="background:var(--primary-glow);border:1px solid rgba(30,58,138,0.2);">
        <span class="nav-icon">🖥️</span>
        <span class="nav-label" style="color:var(--primary);">Open POS</span>
      </a>
    </nav>
    <a href="<?= APP_URL ?>/modules/auth/logout.php" class="sidebar-logout-btn">
      <span>⏻</span>
      <span>Sign Out</span>
    </a>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <button onclick="toggleSidebar()" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;">☰</button>
      <div class="topbar-search">
        <span style="color:var(--text-muted)">🔍</span>
        <input type="text" placeholder="Search products, customers..."/>
      </div>
      <div class="topbar-right">
        <?php if ($lowStock > 0): ?>
          <a href="<?= APP_URL ?>/modules/client/inventory.php?filter=low_stock" class="topbar-btn" title="<?=$lowStock?> low stock alerts">
            ⚠️<span class="notif-dot"></span>
          </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/modules/pos/index.php" class="btn btn-primary btn-sm">🖥️ POS Terminal</a>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 14px;background:var(--bg-dark);border:1px solid var(--border);border-radius:var(--radius-sm);">
          <div class="sidebar-avatar" style="width:28px;height:28px;font-size:12px;"><?= strtoupper(substr(currentUser()['name'],0,1)) ?></div>
          <span style="font-size:13px;font-weight:500;"><?= htmlspecialchars(explode(' ',currentUser()['name'])[0]) ?></span>
        </div>
      </div>
    </header>
    <div class="content-area">
<?php
    echo ob_get_clean();
    $GLOBALS['_tenant'] = $tenant;
    $GLOBALS['_tenant_id'] = $tid;
}

function clientLayoutEnd(): void { ?>
    </div>
  </div>
</div>
<div id="toast-container"></div>
<script>
function toggleSidebar(){const s=document.getElementById('sidebar');s.style.transform=s.style.transform?'':'translateX(-100%)';}
function openModal(id){document.getElementById(id).style.display='flex';}
function closeModal(id){document.getElementById(id).style.display='none';}
function showToast(msg,type='info'){const icons={success:'✅',error:'❌',info:'ℹ️',warning:'⚠️'};const el=document.createElement('div');el.className=`toast ${type}`;el.innerHTML=`<span>${icons[type]}</span><span>${msg}</span>`;document.getElementById('toast-container').appendChild(el);setTimeout(()=>el.remove(),3500);}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.style.display='none';}));
</script>
</body></html>
<?php }
