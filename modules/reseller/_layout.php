<?php
// Shared layout helper for Reseller panel
// Include at the top of each reseller page after auth check
function resellerLayout(string $pageTitle, string $currentPage): void {
    $db  = getDB();
    $rid = currentUser()['reseller_id'];
    $stmt = $db->prepare("SELECT * FROM resellers WHERE id=?"); $stmt->execute([$rid]);
    $reseller = $stmt->fetch();
    $primaryColor   = $reseller['brand_color_primary']   ?? '#1e3a8a';
    $secondaryColor = $reseller['brand_color_secondary'] ?? '#8b5cf6';
    $pendingInv = $db->prepare("SELECT COUNT(*) FROM invoices WHERE from_entity_type='reseller' AND from_entity_id=? AND status='sent'");
    $pendingInv->execute([$rid]); $pendingInv = $pendingInv->fetchColumn();

    $navItems = [
        ['icon'=>'📊','label'=>'Dashboard', 'file'=>'dashboard','href'=>'dashboard.php'],
        ['icon'=>'🏢','label'=>'My Clients','file'=>'clients',  'href'=>'clients.php'],
        ['icon'=>'📦','label'=>'My Plans',  'file'=>'plans',    'href'=>'plans.php'],
        ['icon'=>'🧾','label'=>'Billing',   'file'=>'billing',  'href'=>'billing.php'],
        ['icon'=>'🎨','label'=>'Branding',  'file'=>'branding', 'href'=>'branding.php'],
        ['icon'=>'📈','label'=>'Reports',   'file'=>'reports',  'href'=>'reports.php'],
        ['icon'=>'⚙️','label'=>'Settings',  'file'=>'settings', 'href'=>'settings.php'],
    ];
    ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($reseller['company_name']) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>:root{--primary:<?= $primaryColor ?>;--accent:<?= $secondaryColor ?>;--primary-glow:<?= $primaryColor ?>40;}</style>
</head>
<body>
<div class="app-layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <?php if ($reseller['logo_path']): ?>
        <img src="<?= APP_URL ?>/uploads/logos/<?= htmlspecialchars($reseller['logo_path']) ?>" style="height:36px;border-radius:8px;"/>
      <?php else: ?>
        <div class="sidebar-logo-icon" style="background:<?= $primaryColor ?>;">🤝</div>
      <?php endif; ?>
      <div>
        <div class="sidebar-logo-text"><?= htmlspecialchars($reseller['company_name']) ?></div>
        <div class="sidebar-logo-sub">Reseller Panel</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($navItems as $item): ?>
        <a href="<?= APP_URL ?>/modules/reseller/<?= $item['href'] ?>"
           class="nav-item <?= $currentPage===$item['file']?'active':'' ?>">
          <span class="nav-icon"><?= $item['icon'] ?></span>
          <span class="nav-label"><?= $item['label'] ?></span>
          <?php if ($item['file']==='billing' && $pendingInv>0): ?><span class="nav-badge"><?= $pendingInv ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <a href="<?= APP_URL ?>/modules/auth/logout.php" class="sidebar-logout-btn">
      <span>⏻</span>
      <span>Sign Out</span>
    </a>
  </aside>
  <div class="main-content">
    <header class="topbar">
      <button onclick="toggleSidebar()" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;">☰</button>
      <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
        <span class="badge badge-<?= ['active'=>'success','trial'=>'info','suspended'=>'warning','expired'=>'danger'][$reseller['subscription_status']]??'muted' ?>"><?= ucfirst($reseller['subscription_status']) ?></span>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 14px;background:var(--bg-dark);border:1px solid var(--border);border-radius:var(--radius-sm);">
          <div style="width:28px;height:28px;border-radius:50%;background:<?= $primaryColor ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;"><?= strtoupper(substr(currentUser()['name'],0,1)) ?></div>
          <span style="font-size:13px;font-weight:500;"><?= htmlspecialchars(explode(' ',currentUser()['name'])[0]) ?></span>
        </div>
      </div>
    </header>
    <div class="content-area">
<?php
    echo ob_get_clean();
    // Store reseller in global for page use
    $GLOBALS['_reseller'] = $reseller;
    $GLOBALS['_reseller_rid'] = $rid;
    $GLOBALS['_primary'] = $primaryColor;
}

function resellerLayoutEnd(): void { ?>
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
