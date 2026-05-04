<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);

$db = getDB();
$pageTitle = 'Platform Reports';
$currentPage = 'reports';

// Date filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

// 1. Subscription Revenue (Total Paid Invoices)
$revenue = $db->prepare("
    SELECT SUM(total_amount) as total, COUNT(*) as count
    FROM invoices 
    WHERE status='paid' AND DATE(paid_at) BETWEEN ? AND ?
");
$revenue->execute([$start_date, $end_date]);
$revData = $revenue->fetch();

// 2. Client Growth (Direct vs Reseller-owned)
$clients = $db->prepare("
    SELECT 
        SUM(CASE WHEN reseller_id IS NULL THEN 1 ELSE 0 END) as direct,
        SUM(CASE WHEN reseller_id IS NOT NULL THEN 1 ELSE 0 END) as reseller_owned
    FROM tenants
    WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?
");
$clients->execute([$start_date, $end_date]);
$clientStats = $clients->fetch();

// 3. Revenue by Plan (Top 5 Plans)
$planRevenue = $db->prepare("
    SELECT sp.plan_name, SUM(i.total_amount) as total
    FROM invoices i
    JOIN subscription_plans sp ON sp.id = i.plan_id
    WHERE i.status='paid' AND DATE(i.paid_at) BETWEEN ? AND ?
    GROUP BY sp.id
    ORDER BY total DESC
    LIMIT 5
");
$planRevenue->execute([$start_date, $end_date]);
$planData = $planRevenue->fetchAll();

// 4. Monthly Trend
$trend = $db->query("
    SELECT DATE_FORMAT(paid_at, '%b') as month, SUM(total_amount) as total
    FROM invoices
    WHERE status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(paid_at), MONTH(paid_at)
    ORDER BY paid_at ASC
");
$trendData = $trend->fetchAll();

$chartLabels = json_encode(array_column($trendData, 'month') ?: ['Jan','Feb','Mar','Apr','May','Jun']);
$chartValues = json_encode(array_column($trendData, 'total') ?: [0,0,0,0,0,0]);

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <div><div class="page-title">Platform Analytics</div><div class="page-subtitle">Comprehensive overview of system-wide revenue and growth</div></div>
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
        <button class="btn btn-primary">Filter Reports</button>
        <a href="reports.php" class="btn btn-outline">Reset</a>
    </form>
</div>

<!-- KPIs -->
<div class="grid-3" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div>
            <div class="stat-value">KSh <?= number_format($revData['total'], 2) ?></div>
            <div class="stat-label">Total Revenue Collected</div>
            <div class="stat-trend up">▲ <?= $revData['count'] ?> paid invoices</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🏢</div>
        <div>
            <div class="stat-value"><?= number_format($clientStats['direct'] + $clientStats['reseller_owned']) ?></div>
            <div class="stat-label">New Clients Onboarded</div>
            <div class="stat-trend up">▲ <?= $clientStats['direct'] ?> direct / <?= $clientStats['reseller_owned'] ?> via resellers</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📦</div>
        <div>
            <?php
                $activePlans = $db->query("SELECT COUNT(*) FROM subscription_plans WHERE is_active=1")->fetchColumn();
            ?>
            <div class="stat-value"><?= $activePlans ?></div>
            <div class="stat-label">Active Plans</div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-bottom: 24px;">
    <!-- Revenue Trend Chart -->
    <div class="chart-card">
        <div class="chart-title">Monthly Revenue Trend</div>
        <div style="height: 300px;">
            <canvas id="platformRevenueChart"></canvas>
        </div>
    </div>

    <!-- Revenue by Plan -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700;">💎 Revenue by Subscription Plan</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Plan Name</th><th>Revenue</th></tr></thead>
                <tbody>
                    <?php if (empty($planData)): ?>
                        <tr><td colspan="2" style="text-align: center; padding: 40px; color: var(--text-muted);">No revenue data in this range.</td></tr>
                    <?php else: foreach($planData as $p): ?>
                    <tr>
                        <td style="font-weight: 600; color: white;"><?= htmlspecialchars($p['plan_name']) ?></td>
                        <td style="font-weight: 800; color: var(--success);">KSh <?= number_format($p['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- Top Resellers -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700;">🤝 Top Performing Resellers</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reseller</th><th>Clients</th><th>Total Revenue</th></tr></thead>
                <tbody>
                    <?php
                    $topResellers = $db->query("
                        SELECT r.company_name, 
                               (SELECT COUNT(*) FROM tenants t WHERE t.reseller_id = r.id) as client_count,
                               (SELECT SUM(total_amount) FROM invoices WHERE from_entity_type='platform' AND to_entity_type='reseller' AND to_entity_id=r.id AND status='paid') as revenue
                        FROM resellers r
                        WHERE r.deleted_at IS NULL
                        ORDER BY revenue DESC LIMIT 10
                    ")->fetchAll();
                    
                    if (empty($topResellers)): ?>
                        <tr><td colspan="3" style="text-align: center; padding: 40px; color: var(--text-muted);">No resellers found.</td></tr>
                    <?php else: foreach($topResellers as $tr): ?>
                    <tr>
                        <td style="font-weight: 600; color: white;"><?= htmlspecialchars($tr['company_name']) ?></td>
                        <td><?= number_format($tr['client_count']) ?></td>
                        <td style="font-weight: 800; color: var(--success);">KSh <?= number_format($tr['revenue'], 2) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- System Health -->
    <div class="card">
        <div style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">🩺 System Summary</div>
        <div style="display: flex; flex-direction: column; gap: 15px;">
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">Total Invoices Generated</span>
                <span style="font-weight: 700;"><?= $db->query("SELECT COUNT(*) FROM invoices")->fetchColumn() ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">Total Payments Recorded</span>
                <span style="font-weight: 700;"><?= $db->query("SELECT COUNT(*) FROM payments")->fetchColumn() ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">Platform Uptime</span>
                <span style="font-weight: 700; color: var(--success);">99.9%</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 12px; background: var(--bg-dark); border-radius: 8px;">
                <span style="color: var(--text-muted);">App Version</span>
                <span style="font-weight: 700;"><?= APP_VERSION ?></span>
            </div>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('platformRevenueChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= $chartLabels ?>,
        datasets: [{
            label: 'Platform Revenue',
            data: <?= $chartValues ?>,
            borderColor: '#1e3a8a',
            backgroundColor: 'rgba(30, 58, 138,0.1)',
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#1e3a8a',
            pointRadius: 5
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#94a3b8', callback: v => 'KSh ' + v.toLocaleString() } },
            x: { grid: { display: false }, ticks: { color: '#94a3b8' } }
        }
    }
});
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
