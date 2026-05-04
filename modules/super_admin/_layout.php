<?php
// Super Admin shared sidebar layout
// Usage: include this file, then echo your page content
$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$navItems = [
    ['icon'=>'📊','label'=>'Dashboard',   'file'=>'dashboard',   'href'=>'dashboard.php'],
    ['icon'=>'🤝','label'=>'Resellers',   'file'=>'resellers',   'href'=>'resellers.php'],
    ['icon'=>'🏢','label'=>'Clients',     'file'=>'clients',     'href'=>'clients.php'],
    ['icon'=>'📦','label'=>'Plans',       'file'=>'plans',       'href'=>'plans.php'],
    ['icon'=>'🧾','label'=>'Billing',     'file'=>'billing',     'href'=>'billing.php'],
    ['icon'=>'📈','label'=>'Reports',     'file'=>'reports',     'href'=>'reports.php'],
    ['icon'=>'⚙️','label'=>'Settings',   'file'=>'settings',    'href'=>'settings.php'],
];

// Get platform stats for sidebar badge
$db = getDB();
$pendingInvoices = $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $pageTitle ?? 'Dashboard' ?> — POSCloud Admin</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    .role-badge {
      font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
      background:linear-gradient(135deg,var(--primary),var(--accent));
      color:#fff; padding:2px 8px; border-radius:10px; margin-left:8px;
    }
  </style>
</head>
<body>
<div class="app-layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon">🏪</div>
      <div>
        <div class="sidebar-logo-text">POSCloud</div>
        <div class="sidebar-logo-sub">Platform Admin</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Main Menu</div>
      <?php foreach ($navItems as $item): ?>
        <a href="<?= APP_URL ?>/modules/super_admin/<?= $item['href'] ?>"
           class="nav-item <?= $currentPage === $item['file'] ? 'active' : '' ?>">
          <span class="nav-icon"><?= $item['icon'] ?></span>
          <span class="nav-label"><?= $item['label'] ?></span>
          <?php if ($item['file'] === 'billing' && $pendingInvoices > 0): ?>
            <span class="nav-badge"><?= $pendingInvoices ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <a href="<?= APP_URL ?>/modules/auth/logout.php" class="sidebar-logout-btn">
      <span>⏻</span>
      <span>Sign Out</span>
    </a>
  </aside>

  <!-- MAIN -->
  <div class="main-content">
    <!-- TOP BAR -->
    <header class="topbar">
      <button onclick="toggleSidebar()" style="background:none;border:none;color:var(--text-secondary);font-size:20px;cursor:pointer;">☰</button>
      <div class="topbar-search">
        <span style="color:var(--text-muted)">🔍</span>
        <input type="text" placeholder="Search resellers, clients, invoices..."/>
      </div>
      <div class="topbar-right">
        <div class="topbar-btn" title="Notifications">
          🔔 <?php if ($pendingInvoices): ?><span class="notif-dot"></span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;padding:6px 14px;background:var(--bg-dark);border:1px solid var(--border);border-radius:var(--radius-sm);">
          <div class="sidebar-avatar" style="width:28px;height:28px;font-size:12px;"><?= strtoupper(substr($user['name'],0,1)) ?></div>
          <span style="font-size:13px;font-weight:500;"><?= htmlspecialchars(explode(' ',$user['name'])[0]) ?></span>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT INJECTED HERE -->
    <div class="content-area">
