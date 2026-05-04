<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$currentPage = 'reports';

// Date filters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Start of month
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$branch_id  = (int)($_GET['branch_id'] ?? 0);

$where = ["s.tenant_id=?", "s.status='completed'", "DATE(s.created_at) BETWEEN ? AND ?"];
$params = [$tid, $start_date, $end_date];
if ($branch_id) { $where[] = "s.branch_id=?"; $params[] = $branch_id; }
$w = 'WHERE ' . implode(' AND ', $where);

// 1. Sales Summary
$summary = $db->prepare("
    SELECT 
        COUNT(*) as total_txns,
        SUM(total_amount) as total_sales,
        SUM(subtotal) as total_subtotal,
        SUM(tax_amount) as total_tax,
        SUM(discount_amount) as total_discount
    FROM sales s
    $w
");
$summary->execute($params);
$summaryData = $summary->fetch();

// 2. Sales by Payment Method
$payments = $db->prepare("
    SELECT sp.payment_method, SUM(sp.amount) as total 
    FROM sale_payments sp
    JOIN sales s ON s.id = sp.sale_id
    $w
    GROUP BY sp.payment_method
");
$payments->execute($params);
$paymentData = $payments->fetchAll();

// 3. Sales by Branch
$branchSales = $db->prepare("
    SELECT b.branch_name, SUM(s.total_amount) as total, COUNT(s.id) as count
    FROM sales s
    JOIN tenant_branches b ON b.id = s.branch_id
    $w
    GROUP BY s.branch_id
");
$branchSales->execute($params);
$branchSalesData = $branchSales->fetchAll();

// 4. Daily Sales Chart Data
$chart = $db->prepare("
    SELECT DATE(s.created_at) as day, SUM(s.total_amount) as total
    FROM sales s
    $w
    GROUP BY day ORDER BY day ASC
");
$chart->execute($params);
$chartDataRaw = $chart->fetchAll();

$chartLabels = []; $chartValues = [];
foreach ($chartDataRaw as $row) {
    $chartLabels[] = date('d M', strtotime($row['day']));
    $chartValues[] = $row['total'];
}

// 5. Profit/Loss (Simple)
// Profit = Selling Price - Buying Price for all items sold
$profit = $db->prepare("
    SELECT SUM(si.total_price - (si.quantity * p.buying_price)) as estimated_profit
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    $w
");
$profit->execute($params);
$profitValue = $profit->fetchColumn();

$branches = $db->prepare("SELECT id, branch_name FROM tenant_branches WHERE tenant_id=? AND is_active=1");
$branches->execute([$tid]);
$branches = $branches->fetchAll();

clientLayout('Business Reports', $currentPage);
$tenant = $GLOBALS['_tenant'];
?>

<div class="page-header">
  <div><div class="page-title">Business Reports</div><div class="page-subtitle">Analyze your sales, profits, and performance</div></div>
  <div class="flex gap-2">
      <button class="btn btn-outline" onclick="window.print()">🖨️ Print Report</button>
  </div>
</div>

<!-- Filters -->
<div class="card" style="padding: 16px; margin-bottom: 24px;">
    <form method="GET" class="flex gap-2" style="flex-wrap: wrap; align-items: flex-end;">
        <div>
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
        </div>
        <div>
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
        </div>
        <div style="min-width: 180px;">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-control">
                <option value="0">All Branches</option>
                <?php foreach($branches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $branch_id == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary">Generate Report</button>
        <a href="reports.php" class="btn btn-outline">Reset</a>
    </form>
</div>

<!-- Summary KPIs -->
<div class="grid-4" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div>
            <div class="stat-value"><?= $tenant['currency_symbol'] ?> <?= number_format($summaryData['total_sales'], 2) ?></div>
            <div class="stat-label">Gross Revenue</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📈</div>
        <div>
            <div class="stat-value"><?= $tenant['currency_symbol'] ?> <?= number_format($profitValue, 2) ?></div>
            <div class="stat-label">Est. Net Profit</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">🧾</div>
        <div>
            <div class="stat-value"><?= number_format($summaryData['total_txns']) ?></div>
            <div class="stat-label">Total Sales</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">🏷️</div>
        <div>
            <div class="stat-value"><?= $tenant['currency_symbol'] ?> <?= number_format($summaryData['total_discount'], 2) ?></div>
            <div class="stat-label">Total Discounts</div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-bottom: 24px;">
    <!-- Sales Trend -->
    <div class="chart-card">
        <div class="chart-title">Revenue Trend</div>
        <div style="height: 300px;">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Payment Methods -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700;">💳 Payment Methods</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Method</th><th>Total Collected</th><th>Percentage</th></tr></thead>
                <tbody>
                    <?php 
                    $totalPay = array_sum(array_column($paymentData, 'total'));
                    foreach($paymentData as $p): 
                        $pct = $totalPay > 0 ? ($p['total'] / $totalPay) * 100 : 0;
                    ?>
                    <tr>
                        <td style="text-transform: capitalize; font-weight: 600;"><?= $p['payment_method'] ?></td>
                        <td><?= $tenant['currency_symbol'] ?> <?= number_format($p['total'], 2) ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="flex: 1; height: 6px; background: var(--bg-dark); border-radius: 3px; overflow: hidden;">
                                    <div style="height: 100%; width: <?= $pct ?>%; background: var(--primary);"></div>
                                </div>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= number_format($pct, 1) ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- Branch Performance -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700;">🏢 Branch Performance</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Branch</th><th>Transactions</th><th>Revenue</th></tr></thead>
                <tbody>
                    <?php foreach($branchSalesData as $bs): ?>
                    <tr>
                        <td style="font-weight: 600;"><?= htmlspecialchars($bs['branch_name']) ?></td>
                        <td><?= number_format($bs['count']) ?></td>
                        <td style="font-weight: 700; color: var(--success);"><?= $tenant['currency_symbol'] ?> <?= number_format($bs['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tax Summary -->
    <div class="card" style="padding: 20px;">
        <div style="font-weight: 700; margin-bottom: 15px;">📊 Tax & Discount Breakdown</div>
        <div style="display: flex; flex-direction: column; gap: 15px;">
            <div style="display: flex; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid var(--border);">
                <span style="color: var(--text-muted);">Total Tax (VAT) Collected</span>
                <span style="font-weight: 700; color: var(--primary);"><?= $tenant['currency_symbol'] ?> <?= number_format($summaryData['total_tax'], 2) ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid var(--border);">
                <span style="color: var(--text-muted);">Discounts Applied</span>
                <span style="font-weight: 700; color: var(--warning);"><?= $tenant['currency_symbol'] ?> <?= number_format($summaryData['total_discount'], 2) ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid var(--border);">
                <span style="color: var(--text-muted);">Average Transaction Value</span>
                <span style="font-weight: 700;">
                    <?= $tenant['currency_symbol'] ?> <?= $summaryData['total_txns'] > 0 ? number_format($summaryData['total_sales'] / $summaryData['total_txns'], 2) : '0.00' ?>
                </span>
            </div>
        </div>
        <div class="alert alert-info" style="margin-top: 20px; font-size: 12px;">
            ℹ️ <strong>Note:</strong> Net Profit is an estimate based on Selling Price - Buying Price at the time of sale. It does not include overheads like rent or salaries.
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('revenueChart').getContext('2d');
const grad = ctx.createLinearGradient(0, 0, 0, 300);
grad.addColorStop(0, 'rgba(99, 102, 241, 0.4)');
grad.addColorStop(1, 'rgba(99, 102, 241, 0)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Daily Revenue',
            data: <?= json_encode($chartValues) ?>,
            borderColor: '#1e3a8a',
            backgroundColor: grad,
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#1e3a8a'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8' } },
            x: { grid: { display: false }, ticks: { color: '#94a3b8' } }
        }
    }
});
</script>

<?php clientLayoutEnd(); ?>
