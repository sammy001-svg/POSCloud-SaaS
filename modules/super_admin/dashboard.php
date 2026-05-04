<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);

$db = getDB();
$pageTitle = 'Dashboard';

// ── KPI Stats ──────────────────────────────────────────────
$totalResellers   = $db->query("SELECT COUNT(*) FROM resellers WHERE deleted_at IS NULL")->fetchColumn();
$activeResellers  = $db->query("SELECT COUNT(*) FROM resellers WHERE subscription_status='active' AND deleted_at IS NULL")->fetchColumn();
$totalClients     = $db->query("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL")->fetchColumn();
$activeClients    = $db->query("SELECT COUNT(*) FROM tenants WHERE subscription_status='active' AND deleted_at IS NULL")->fetchColumn();
$totalRevenue     = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
$mrr              = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE status='paid' AND MONTH(paid_at)=MONTH(NOW()) AND YEAR(paid_at)=YEAR(NOW())")->fetchColumn();
$overdueInvoices  = $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
$pendingInvoices  = $db->query("SELECT COUNT(*) FROM invoices WHERE status='sent'")->fetchColumn();

// ── Recent Resellers ───────────────────────────────────────
$recentResellers = $db->query("
    SELECT r.*, sp.plan_name,
    (SELECT COUNT(*) FROM tenants t WHERE t.reseller_id = r.id AND t.deleted_at IS NULL) AS client_count
    FROM resellers r
    LEFT JOIN subscription_plans sp ON sp.id = r.plan_id
    WHERE r.deleted_at IS NULL
    ORDER BY r.created_at DESC LIMIT 5
")->fetchAll();

// ── Recent Invoices ────────────────────────────────────────
$recentInvoices = $db->query("
    SELECT * FROM invoices ORDER BY created_at DESC LIMIT 8
")->fetchAll();

// ── Monthly Revenue (last 6 months) ───────────────────────
$monthlyRevenue = $db->query("
    SELECT DATE_FORMAT(created_at,'%b') AS month, SUM(amount) AS total
    FROM payments
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY created_at ASC
")->fetchAll();

$chartLabels = json_encode(array_column($monthlyRevenue, 'month') ?: ['Jan','Feb','Mar','Apr','May','Jun']);
$chartData   = json_encode(array_column($monthlyRevenue, 'total') ?: [0,0,0,0,0,0]);

// ── Subscription breakdown ────────────────────────────────
$subBreakdown = $db->query("
    SELECT subscription_status, COUNT(*) AS cnt
    FROM tenants WHERE deleted_at IS NULL
    GROUP BY subscription_status
")->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/_layout.php';
?>

<!-- ── Page Header ── -->
<div class="page-header">
  <div>
    <div class="page-title">Platform Dashboard</div>
    <div class="page-subtitle">Welcome back, <?= htmlspecialchars(explode(' ', currentUser()['name'])[0]) ?>! Here's what's happening today.</div>
  </div>
  <div class="flex gap-2">
    <a href="resellers.php?action=create" class="btn btn-primary">+ Add Reseller</a>
    <a href="clients.php?action=create" class="btn btn-outline">+ Add Client</a>
  </div>
</div>

<!-- ── KPI Grid ── -->
<div class="grid-4" style="margin-bottom:28px;">
  <div class="stat-card">
    <div class="stat-icon purple">🤝</div>
    <div>
      <div class="stat-value"><?= number_format($totalResellers) ?></div>
      <div class="stat-label">Total Resellers</div>
      <div class="stat-trend up">▲ <?= $activeResellers ?> active</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">🏢</div>
    <div>
      <div class="stat-value"><?= number_format($totalClients) ?></div>
      <div class="stat-label">Total Clients</div>
      <div class="stat-trend up">▲ <?= $activeClients ?> active</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">💰</div>
    <div>
      <div class="stat-value">KSh <?= number_format($mrr, 0) ?></div>
      <div class="stat-label">Monthly Revenue</div>
      <div class="stat-trend up">▲ This month</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $overdueInvoices > 0 ? 'red' : 'orange' ?>">🧾</div>
    <div>
      <div class="stat-value"><?= $overdueInvoices ?></div>
      <div class="stat-label">Overdue Invoices</div>
      <div class="stat-trend <?= $overdueInvoices > 0 ? 'down' : 'up' ?>"><?= $pendingInvoices ?> pending</div>
    </div>
  </div>
</div>

<!-- ── Charts Row ── -->
<div class="grid-2" style="margin-bottom:28px;">

  <!-- Revenue Chart -->
  <div class="chart-card">
    <div class="chart-title">
      <span>Revenue (Last 6 Months)</span>
      <span style="font-size:12px;color:var(--text-muted);">KSh <?= number_format($totalRevenue, 0) ?> total</span>
    </div>
    <div style="height: 250px; position: relative;">
      <canvas id="revenueChart"></canvas>
    </div>
  </div>

  <!-- Subscription Breakdown -->
  <div class="chart-card">
    <div class="chart-title">
      <span>Client Subscriptions</span>
      <span style="font-size:12px;color:var(--text-muted);"><?= $totalClients ?> total</span>
    </div>
    <div style="height: 250px; position: relative;">
      <canvas id="subChart"></canvas>
    </div>
    <div class="flex gap-3" style="margin-top:16px;flex-wrap:wrap;">
      <?php
        $subColors = ['active'=>'#10b981','trial'=>'#1e3a8a','expired'=>'#ef4444','suspended'=>'#f59e0b'];
        foreach ($subBreakdown as $status => $count):
          $color = $subColors[$status] ?? '#94a3b8';
      ?>
        <div class="flex items-center gap-1">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>;display:inline-block;"></span>
          <span style="font-size:12px;color:var(--text-secondary);"><?= ucfirst($status) ?>: <?= $count ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── Tables Row ── -->
<div class="grid-2">

  <!-- Recent Resellers -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);">
      <span style="font-weight:600;font-size:15px;">Recent Resellers</span>
      <a href="resellers.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Company</th><th>Plan</th><th>Clients</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentResellers)): ?>
            <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted);">No resellers yet. <a href="resellers.php?action=create">Add one</a></td></tr>
          <?php else: foreach ($recentResellers as $r): ?>
            <tr>
              <td>
                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($r['company_name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($r['email']) ?></div>
              </td>
              <td><?= htmlspecialchars($r['plan_name'] ?? '—') ?></td>
              <td><span class="badge badge-info"><?= $r['client_count'] ?></span></td>
              <td>
                <?php
                  $sc = ['active'=>'badge-success','trial'=>'badge-info','suspended'=>'badge-warning','expired'=>'badge-danger'];
                  echo '<span class="badge '.($sc[$r['subscription_status']]??'badge-muted').'">'.ucfirst($r['subscription_status']).'</span>';
                ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Invoices -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);">
      <span style="font-weight:600;font-size:15px;">Recent Invoices</span>
      <a href="billing.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Invoice</th><th>Amount</th><th>Due</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($recentInvoices)): ?>
            <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted);">No invoices yet.</td></tr>
          <?php else: foreach ($recentInvoices as $inv): ?>
            <tr>
              <td>
                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($inv['invoice_number']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($inv['invoice_type']) ?></div>
              </td>
              <td style="font-weight:600;">KSh <?= number_format($inv['total_amount'], 2) ?></td>
              <td style="font-size:12px;"><?= date('d M Y', strtotime($inv['due_date'])) ?></td>
              <td>
                <?php
                  $ic = ['paid'=>'badge-success','sent'=>'badge-info','overdue'=>'badge-danger','draft'=>'badge-muted','cancelled'=>'badge-muted'];
                  echo '<span class="badge '.($ic[$inv['status']]??'badge-muted').'">'.ucfirst($inv['status']).'</span>';
                ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Revenue Line Chart
const rCtx = document.getElementById('revenueChart').getContext('2d');
const gradient = rCtx.createLinearGradient(0,0,0,200);
gradient.addColorStop(0,'rgba(30, 58, 138,0.3)');
gradient.addColorStop(1,'rgba(30, 58, 138,0)');
new Chart(rCtx, {
  type:'line',
  data:{
    labels: <?= $chartLabels ?>,
    datasets:[{
      label:'Revenue (KSh)',
      data: <?= $chartData ?>,
      borderColor:'#1e3a8a', backgroundColor:gradient,
      borderWidth:2, tension:.4, fill:true,
      pointBackgroundColor:'#1e3a8a', pointRadius:4, pointHoverRadius:6
    }]
  },
  options:{
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false}, tooltip:{ backgroundColor:'#1a2235', borderColor:'#1e2d45', borderWidth:1 }},
    scales:{
      x:{ grid:{color:'rgba(255,255,255,.04)'}, ticks:{color:'#94a3b8'} },
      y:{ grid:{color:'rgba(255,255,255,.04)'}, ticks:{color:'#94a3b8', callback:v=>'KSh '+v.toLocaleString()} }
    }
  }
});

// Subscription Doughnut
const sCtx = document.getElementById('subChart').getContext('2d');
const subData = <?= json_encode(array_values($subBreakdown) ?: [0]) ?>;
const subLabels = <?= json_encode(array_map('ucfirst', array_keys($subBreakdown)) ?: ['None']) ?>;
const subColors = ['#10b981','#1e3a8a','#ef4444','#f59e0b','#94a3b8'];
new Chart(sCtx, {
  type:'doughnut',
  data:{
    labels: subLabels,
    datasets:[{ data:subData, backgroundColor:subColors.slice(0,subData.length), borderWidth:0 }]
  },
  options:{
    responsive:true, maintainAspectRatio:false, cutout:'70%',
    plugins:{ legend:{display:false}, tooltip:{ backgroundColor:'#1a2235', borderColor:'#1e2d45', borderWidth:1 }}
  }
});
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
