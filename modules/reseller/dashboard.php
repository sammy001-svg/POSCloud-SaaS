<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_RESELLER);

$db  = getDB();
$rid = currentUser()['reseller_id'];
$pageTitle = 'Dashboard';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$reseller = $db->prepare("SELECT * FROM resellers WHERE id=?")->execute([$rid]) ? $db->prepare("SELECT * FROM resellers WHERE id=?"): null;
$stmt = $db->prepare("SELECT * FROM resellers WHERE id=?"); $stmt->execute([$rid]);
$reseller = $stmt->fetch();

$navItems = [
    ['icon'=>'📊','label'=>'Dashboard',   'file'=>'dashboard',   'href'=>'dashboard.php'],
    ['icon'=>'🏢','label'=>'My Clients',  'file'=>'clients',     'href'=>'clients.php'],
    ['icon'=>'📦','label'=>'My Plans',    'file'=>'plans',       'href'=>'plans.php'],
    ['icon'=>'🧾','label'=>'Billing',     'file'=>'billing',     'href'=>'billing.php'],
    ['icon'=>'🎨','label'=>'Branding',    'file'=>'branding',    'href'=>'branding.php'],
    ['icon'=>'📈','label'=>'Reports',     'file'=>'reports',     'href'=>'reports.php'],
    ['icon'=>'⚙️','label'=>'Settings',   'file'=>'settings',    'href'=>'settings.php'],
];

$totalClients  = $db->prepare("SELECT COUNT(*) FROM tenants WHERE reseller_id=? AND deleted_at IS NULL"); $totalClients->execute([$rid]); $totalClients=$totalClients->fetchColumn();
$activeClients = $db->prepare("SELECT COUNT(*) FROM tenants WHERE reseller_id=? AND subscription_status='active' AND deleted_at IS NULL"); $activeClients->execute([$rid]); $activeClients=$activeClients->fetchColumn();
$mrr           = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE from_entity_type='reseller' AND from_entity_id=? AND status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())"); $mrr->execute([$rid]); $mrr=$mrr->fetchColumn();
$pendingInv    = $db->prepare("SELECT COUNT(*) FROM invoices WHERE from_entity_type='reseller' AND from_entity_id=? AND status='sent'"); $pendingInv->execute([$rid]); $pendingInv=$pendingInv->fetchColumn();

$recentClients = $db->prepare("SELECT t.*, rp.plan_name FROM tenants t LEFT JOIN reseller_plans rp ON rp.id=t.reseller_plan_id WHERE t.reseller_id=? AND t.deleted_at IS NULL ORDER BY t.created_at DESC LIMIT 5"); $recentClients->execute([$rid]); $recentClients=$recentClients->fetchAll();

// Apply reseller branding
$primaryColor   = $reseller['brand_color_primary']   ?? '#1e3a8a';
$secondaryColor = $reseller['brand_color_secondary'] ?? '#10b981';

// KPI chart — monthly revenue last 6 months
$monthlyRev = $db->prepare("SELECT DATE_FORMAT(p.created_at,'%b') AS month, SUM(p.amount) AS total FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.from_entity_type='reseller' AND i.from_entity_id=? AND p.created_at >= DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY YEAR(p.created_at),MONTH(p.created_at) ORDER BY p.created_at ASC");
$monthlyRev->execute([$rid]);
$monthlyRev = $monthlyRev->fetchAll();
$chartLabels = json_encode(array_column($monthlyRev,'month') ?: ['Jan','Feb','Mar','Apr','May','Jun']);
$chartData   = json_encode(array_column($monthlyRev,'total') ?: [0,0,0,0,0,0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $pageTitle ?> — <?= htmlspecialchars($reseller['company_name']) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --primary: <?= $primaryColor ?>;
      --accent:  <?= $secondaryColor ?>;
      --primary-hover: <?= $primaryColor ?>;
      --primary-glow: <?= $primaryColor ?>40;
    }
  </style>
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
        <div class="sidebar-logo-sub">Reseller Dashboard</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Management</div>
      <?php foreach ($navItems as $item): ?>
        <a href="<?= APP_URL ?>/modules/reseller/<?= $item['href'] ?>"
           class="nav-item <?= $currentPage===$item['file']?'active':'' ?>">
          <span class="nav-icon"><?= $item['icon'] ?></span>
          <span class="nav-label"><?= $item['label'] ?></span>
          <?php if ($item['file']==='billing' && $pendingInv>0): ?><span class="nav-badge"><?= $pendingInv ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-avatar" style="background:<?= $primaryColor ?>;"><?= strtoupper(substr(currentUser()['name'],0,1)) ?></div>
      <div>
        <div class="sidebar-user-name"><?= htmlspecialchars(currentUser()['name']) ?></div>
        <div class="sidebar-user-role" style="color:var(--text-muted);">Reseller</div>
      </div>
      <a href="<?= APP_URL ?>/modules/auth/logout.php" class="sidebar-logout" title="Logout">⏻</a>
    </div>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <button onclick="document.getElementById('sidebar').style.transform=document.getElementById('sidebar').style.transform?'':'translateX(-100%)'" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;">☰</button>
      <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
        <span class="badge badge-<?= ['active'=>'success','trial'=>'info','suspended'=>'warning','expired'=>'danger'][$reseller['subscription_status']]??'muted' ?>"><?= ucfirst($reseller['subscription_status']) ?> Plan</span>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 14px;background:var(--bg-dark);border:1px solid var(--border);border-radius:var(--radius-sm);">
          <div class="sidebar-avatar" style="width:28px;height:28px;font-size:12px;background:<?= $primaryColor ?>;"><?= strtoupper(substr(currentUser()['name'],0,1)) ?></div>
          <span style="font-size:13px;font-weight:500;"><?= htmlspecialchars(explode(' ',currentUser()['name'])[0]) ?></span>
        </div>
      </div>
    </header>
    <div class="content-area">

<!-- Page content starts -->
<div class="page-header">
  <div>
    <div class="page-title">Welcome back, <?= htmlspecialchars(explode(' ',currentUser()['name'])[0]) ?>!</div>
    <div class="page-subtitle"><?= htmlspecialchars($reseller['company_name']) ?> · Reseller Dashboard</div>
  </div>
  <a href="clients.php?action=create" class="btn btn-primary">+ Onboard Client</a>
</div>

<!-- KPIs -->
<div class="grid-4" style="margin-bottom:24px;">
  <div class="stat-card">
    <div class="stat-icon purple">🏢</div>
    <div><div class="stat-value"><?= $totalClients ?></div><div class="stat-label">Total Clients</div><div class="stat-trend up">▲ <?= $activeClients ?> active</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">💰</div>
    <div><div class="stat-value">KSh <?= number_format($mrr,0) ?></div><div class="stat-label">This Month Revenue</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">🧾</div>
    <div><div class="stat-value"><?= $pendingInv ?></div><div class="stat-label">Pending Invoices</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">📦</div>
    <div>
      <div class="stat-value"><?= $reseller['subscription_ends_at'] ? date('d M',strtotime($reseller['subscription_ends_at'])) : 'Trial' ?></div>
      <div class="stat-label">Your Plan Expires</div>
    </div>
  </div>
</div>

<!-- Chart + Clients -->
<div class="grid-2" style="margin-bottom:24px;">
  <div class="chart-card">
    <div class="chart-title">Revenue (Last 6 Months)</div>
    <div style="height: 250px; position: relative;">
      <canvas id="revenueChart"></canvas>
    </div>
  </div>
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;display:flex;justify-content:space-between;align-items:center;">
      <span>Recent Clients</span>
      <a href="clients.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Business</th><th>Plan</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($recentClients)): ?>
            <tr><td colspan="3" style="text-align:center;padding:28px;color:var(--text-muted);">No clients yet. <a href="clients.php?action=create">Add one</a>.</td></tr>
          <?php else: foreach ($recentClients as $c):
            $sc=['active'=>'badge-success','trial'=>'badge-info','suspended'=>'badge-warning','expired'=>'badge-danger'];
          ?>
            <tr>
              <td><div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($c['business_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div></td>
              <td style="font-size:12px;"><?= htmlspecialchars($c['plan_name'] ?? '—') ?></td>
              <td><span class="badge <?= $sc[$c['subscription_status']]??'badge-muted' ?>"><?= ucfirst($c['subscription_status']) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const rCtx = document.getElementById('revenueChart').getContext('2d');
const grad = rCtx.createLinearGradient(0,0,0,200);
grad.addColorStop(0,'<?= $primaryColor ?>60');
grad.addColorStop(1,'<?= $primaryColor ?>00');
new Chart(rCtx,{type:'line',data:{labels:<?= $chartLabels ?>,datasets:[{label:'Revenue',data:<?= $chartData ?>,borderColor:'<?= $primaryColor ?>',backgroundColor:grad,borderWidth:2,tension:.4,fill:true,pointBackgroundColor:'<?= $primaryColor ?>',pointRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#94a3b8'}},y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#94a3b8'}}}}});
</script>

    </div><!-- /content-area -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->
<div id="toast-container"></div>
<script>
function showToast(message, type='info') {
  const icons={success:'✅',error:'❌',info:'ℹ️',warning:'⚠️'};
  const el=document.createElement('div'); el.className=`toast ${type}`;
  el.innerHTML=`<span>${icons[type]}</span><span>${message}</span>`;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(()=>el.remove(),3500);
}
</script>
</body></html>
