<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_INVENTORY);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$currentPage = 'dashboard';

// ── KPIs ───────────────────────────────────────────────────
$todaySales    = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE tenant_id=? AND DATE(created_at)=CURDATE() AND status='completed'"); $todaySales->execute([$tid]); $todaySales=$todaySales->fetchColumn();
$todayTxns     = $db->prepare("SELECT COUNT(*) FROM sales WHERE tenant_id=? AND DATE(created_at)=CURDATE() AND status='completed'"); $todayTxns->execute([$tid]); $todayTxns=$todayTxns->fetchColumn();
$monthSales    = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE tenant_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND status='completed'"); $monthSales->execute([$tid]); $monthSales=$monthSales->fetchColumn();
$totalProducts = $db->prepare("SELECT COUNT(*) FROM products WHERE tenant_id=? AND deleted_at IS NULL"); $totalProducts->execute([$tid]); $totalProducts=$totalProducts->fetchColumn();
$lowStock      = $db->prepare("SELECT COUNT(*) FROM stock_levels sl JOIN products p ON p.id=sl.product_id WHERE sl.tenant_id=? AND sl.quantity<=sl.low_stock_threshold AND p.track_stock=1"); $lowStock->execute([$tid]); $lowStock=$lowStock->fetchColumn();
$totalCustomers= $db->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id=? AND is_active=1"); $totalCustomers->execute([$tid]); $totalCustomers=$totalCustomers->fetchColumn();

// ── Chart: last 7 days sales ───────────────────────────────
$weekSales = $db->prepare("SELECT DATE(created_at) AS day, SUM(total_amount) AS total FROM sales WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND status='completed' GROUP BY DATE(created_at) ORDER BY day ASC"); $weekSales->execute([$tid]); $weekSales=$weekSales->fetchAll();

// Fill all 7 days
$dayLabels = []; $dayData = [];
$saleMap = []; foreach($weekSales as $row) $saleMap[$row['day']]=$row['total'];
for ($i=6;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $dayLabels[]=date('D d',strtotime($d)); $dayData[]=$saleMap[$d]??0; }

// ── Top 5 products today ───────────────────────────────────
$topProducts = $db->prepare("SELECT si.product_name, SUM(si.quantity) AS qty_sold, SUM(si.total_price) AS revenue FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.tenant_id=? AND DATE(s.created_at)=CURDATE() AND s.status='completed' GROUP BY si.product_id, si.product_name ORDER BY revenue DESC LIMIT 5"); $topProducts->execute([$tid]); $topProducts=$topProducts->fetchAll();

// ── Recent sales ───────────────────────────────────────────
$recentSales = $db->prepare("SELECT s.*, COALESCE(c.name,'Walk-in') AS customer_name, u.name AS cashier FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN tenant_users u ON u.id=s.user_id WHERE s.tenant_id=? AND s.status='completed' ORDER BY s.created_at DESC LIMIT 8"); $recentSales->execute([$tid]); $recentSales=$recentSales->fetchAll();

// ── Low stock alerts ───────────────────────────────────────
$lowStockItems = $db->prepare("SELECT p.name, sl.quantity, sl.low_stock_threshold, b.branch_name FROM stock_levels sl JOIN products p ON p.id=sl.product_id JOIN tenant_branches b ON b.id=sl.branch_id WHERE sl.tenant_id=? AND sl.quantity<=sl.low_stock_threshold AND p.track_stock=1 ORDER BY sl.quantity ASC LIMIT 6"); $lowStockItems->execute([$tid]); $lowStockItems=$lowStockItems->fetchAll();

clientLayout('Dashboard', $currentPage);
$tenant = $GLOBALS['_tenant'];
?>

<div class="page-header">
  <div>
    <div class="page-title">Dashboard</div>
    <div class="page-subtitle"><?= date('l, d F Y') ?> · <?= htmlspecialchars($tenant['business_name']) ?></div>
  </div>
  <a href="<?= APP_URL ?>/modules/pos/index.php" class="btn btn-primary btn-lg">🖥️ Open POS Terminal</a>
</div>

<!-- KPIs -->
<div class="grid-4" style="margin-bottom:24px;">
  <div class="stat-card">
    <div class="stat-icon green">💵</div>
    <div>
      <div class="stat-value"><?= $tenant['currency_symbol'] ?> <?= number_format($todaySales,0) ?></div>
      <div class="stat-label">Today's Sales</div>
      <div class="stat-trend up">▲ <?= $todayTxns ?> transactions</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">📅</div>
    <div>
      <div class="stat-value"><?= $tenant['currency_symbol'] ?> <?= number_format($monthSales,0) ?></div>
      <div class="stat-label">This Month</div>
      <div class="stat-trend up">▲ <?= date('F Y') ?></div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">📦</div>
    <div>
      <div class="stat-value"><?= number_format($totalProducts) ?></div>
      <div class="stat-label">Total Products</div>
      <?php if ($lowStock > 0): ?>
        <div class="stat-trend down">▼ <?= $lowStock ?> low stock</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">👥</div>
    <div>
      <div class="stat-value"><?= number_format($totalCustomers) ?></div>
      <div class="stat-label">Customers</div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="grid-2" style="margin-bottom:24px;">
  <div class="chart-card" style="grid-column:span 1;">
    <div class="chart-title">
      <span>Sales — Last 7 Days</span>
      <span style="font-size:12px;color:var(--text-muted);"><?= $tenant['currency_symbol'] ?></span>
    </div>
    <div style="height: 250px; position: relative;">
      <canvas id="weekChart"></canvas>
    </div>
  </div>

  <!-- Top Products -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;">🏆 Top Products Today</div>
    <?php if (empty($topProducts)): ?>
      <div style="text-align:center;padding:32px;color:var(--text-muted);">No sales yet today.</div>
    <?php else: ?>
      <div style="padding:16px;">
        <?php foreach ($topProducts as $i => $p): ?>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:28px;height:28px;border-radius:8px;background:var(--primary-glow);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--primary);font-size:13px;flex-shrink:0;"><?= $i+1 ?></div>
            <div style="flex:1;min-width:0;">
              <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p['product_name']) ?></div>
              <div style="font-size:11px;color:var(--text-muted);"><?= number_format($p['qty_sold'],1) ?> units sold</div>
            </div>
            <div style="font-weight:700;color:var(--success);font-size:13px;white-space:nowrap;"><?= $tenant['currency_symbol'] ?> <?= number_format($p['revenue'],0) ?></div>
          </div>
          <?php if ($i < count($topProducts)-1): ?>
            <div style="height:1px;background:var(--border);margin-bottom:14px;"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Low Stock + Recent Sales -->
<div class="grid-2">

  <!-- Low Stock Alerts -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:600;">⚠️ Low Stock Alerts</span>
      <?php if ($lowStock>0): ?><a href="inventory.php?filter=low_stock" class="btn btn-sm btn-outline">View All (<?=$lowStock?>)</a><?php endif; ?>
    </div>
    <?php if (empty($lowStockItems)): ?>
      <div style="text-align:center;padding:32px;color:var(--text-muted);">✅ All stock levels are healthy.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Product</th><th>Branch</th><th>Qty</th><th>Min</th></tr></thead>
          <tbody>
            <?php foreach($lowStockItems as $item): ?>
              <tr>
                <td style="font-weight:500;color:var(--text-primary);"><?= htmlspecialchars($item['name']) ?></td>
                <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($item['branch_name']) ?></td>
                <td><span class="badge <?= $item['quantity']<=0?'badge-danger':'badge-warning' ?>"><?= number_format($item['quantity'],1) ?></span></td>
                <td style="font-size:12px;color:var(--text-muted);"><?= number_format($item['low_stock_threshold'],1) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent Sales -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
      <span style="font-weight:600;">🧾 Recent Sales</span>
      <a href="reports.php" class="btn btn-sm btn-outline">View Reports</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Receipt</th><th>Customer</th><th>Amount</th><th>Time</th></tr></thead>
        <tbody>
          <?php if (empty($recentSales)): ?>
            <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted);">No sales yet. <a href="<?= APP_URL ?>/modules/pos/index.php">Open POS to start selling</a>.</td></tr>
          <?php else: foreach ($recentSales as $s): ?>
            <tr>
              <td style="font-weight:600;color:var(--text-primary);font-size:12px;"><?= htmlspecialchars($s['sale_number']) ?></td>
              <td style="font-size:12px;"><?= htmlspecialchars($s['customer_name']) ?></td>
              <td style="font-weight:700;"><?= $tenant['currency_symbol'] ?> <?= number_format($s['total_amount'],2) ?></td>
              <td style="font-size:11px;color:var(--text-muted);"><?= date('H:i',strtotime($s['created_at'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const wCtx = document.getElementById('weekChart').getContext('2d');
const wGrad = wCtx.createLinearGradient(0,0,0,200);
wGrad.addColorStop(0,'rgba(16,185,129,.3)');
wGrad.addColorStop(1,'rgba(16,185,129,0)');
new Chart(wCtx,{
  type:'bar',
  data:{
    labels:<?= json_encode($dayLabels) ?>,
    datasets:[{
      label:'Sales',
      data:<?= json_encode($dayData) ?>,
      backgroundColor:'rgba(30, 58, 138,.7)',
      borderRadius:6, borderSkipped:false,
      hoverBackgroundColor:'rgba(30, 58, 138,1)'
    }]
  },
  options:{
    responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false},tooltip:{backgroundColor:'#1a2235',borderColor:'#1e2d45',borderWidth:1}},
    scales:{
      x:{grid:{display:false},ticks:{color:'#94a3b8',font:{size:11}}},
      y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#94a3b8',callback:v=>'KSh '+v.toLocaleString()}}
    }
  }
});
</script>

<?php clientLayoutEnd(); ?>
