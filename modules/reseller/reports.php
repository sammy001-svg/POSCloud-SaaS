<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_RESELLER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$rid = currentUser()['reseller_id'];
$currentPage = 'reports';

// Date filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

// 1. Client Growth (Count by month)
$growth = $db->prepare("
    SELECT DATE_FORMAT(created_at, '%b %Y') as month, COUNT(*) as count
    FROM tenants
    WHERE reseller_id = ? AND deleted_at IS NULL
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY created_at ASC
    LIMIT 6
");
$growth->execute([$rid]);
$growthData = $growth->fetchAll();

// 2. Revenue Summary (from invoices to clients)
$revenue = $db->prepare("
    SELECT 
        SUM(CASE WHEN status='paid' THEN total_amount ELSE 0 END) as collected,
        SUM(CASE WHEN status='sent' THEN total_amount ELSE 0 END) as pending,
        SUM(CASE WHEN status='overdue' THEN total_amount ELSE 0 END) as overdue
    FROM invoices
    WHERE from_entity_type='reseller' AND from_entity_id = ? AND DATE(created_at) BETWEEN ? AND ?
");
$revenue->execute([$rid, $start_date, $end_date]);
$revData = $revenue->fetch();

// 3. Top Clients (by revenue)
$topClients = $db->prepare("
    SELECT t.business_name, SUM(i.total_amount) as revenue, COUNT(i.id) as inv_count
    FROM tenants t
    JOIN invoices i ON i.to_entity_id = t.id AND i.to_entity_type='client'
    WHERE t.reseller_id = ? AND i.status='paid'
    GROUP BY t.id ORDER BY revenue DESC LIMIT 10
");
$topClients->execute([$rid]);
$topClientsData = $topClients->fetchAll();

// 4. Monthly Revenue Trend
$trend = $db->prepare("
    SELECT DATE_FORMAT(paid_at, '%b') as month, SUM(total_amount) as total
    FROM invoices
    WHERE from_entity_type='reseller' AND from_entity_id = ? AND status='paid' AND paid_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(paid_at), MONTH(paid_at)
    ORDER BY paid_at ASC
");
$trend->execute([$rid]);
$trendData = $trend->fetchAll();

$chartLabels = json_encode(array_column($trendData, 'month') ?: ['Jan','Feb','Mar','Apr','May','Jun']);
$chartValues = json_encode(array_column($trendData, 'total') ?: [0,0,0,0,0,0]);

resellerLayout('Reseller Reports', $currentPage);
$primaryColor = $GLOBALS['_primary'] ?? '#1e3a8a';
?>

<div class="page-header">
  <div><div class="page-title">Financial Reports</div><div class="page-subtitle">Analyze your commission and revenue from client subscriptions</div></div>
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
        <div class="stat-icon green">✅</div>
        <div>
            <div class="stat-value">KSh <?= number_format($revData['collected'], 2) ?></div>
            <div class="stat-label">Collected Revenue</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">⏳</div>
        <div>
            <div class="stat-value">KSh <?= number_format($revData['pending'], 2) ?></div>
            <div class="stat-label">Pending Invoices</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">⚠️</div>
        <div>
            <div class="stat-value">KSh <?= number_format($revData['overdue'], 2) ?></div>
            <div class="stat-label">Overdue Payments</div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-bottom: 24px;">
    <!-- Revenue Trend -->
    <div class="chart-card">
        <div class="chart-title">Income Trend (Last 6 Months)</div>
        <div style="height: 250px;">
            <canvas id="incomeChart"></canvas>
        </div>
    </div>

    <!-- Top Clients -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700;">🏢 Top Clients by Revenue</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Business</th><th>Paid Invoices</th><th>Total Revenue</th></tr></thead>
                <tbody>
                    <?php if (empty($topClientsData)): ?>
                        <tr><td colspan="3" style="text-align: center; padding: 40px; color: var(--text-muted);">No revenue data yet.</td></tr>
                    <?php else: foreach($topClientsData as $tc): ?>
                    <tr>
                        <td style="font-weight: 600; color: white;"><?= htmlspecialchars($tc['business_name']) ?></td>
                        <td><?= number_format($tc['inv_count']) ?></td>
                        <td style="font-weight: 800; color: var(--success);">KSh <?= number_format($tc['revenue'], 2) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- Client Growth -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700;">📈 New Clients Onboarded</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Month</th><th>New Clients</th></tr></thead>
                <tbody>
                    <?php foreach($growthData as $g): ?>
                    <tr>
                        <td><?= $g['month'] ?></td>
                        <td style="font-weight: 700; color: var(--primary);"><?= $g['count'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Your Plan Info -->
    <div class="card" style="padding: 24px; background: linear-gradient(135deg, #1e1b4b, #111827); border: 1px solid #312e81;">
        <?php
        $stmt = $db->prepare("SELECT r.*, sp.plan_name FROM resellers r LEFT JOIN subscription_plans sp ON sp.id=r.plan_id WHERE r.id=?");
        $stmt->execute([$rid]);
        $reseller = $stmt->fetch();
        ?>
        <div style="font-size: 13px; font-weight: 700; color: #818cf8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 15px;">Your Subscription</div>
        <div style="font-size: 24px; font-weight: 900; color: white; margin-bottom: 5px;"><?= htmlspecialchars($reseller['plan_name'] ?: 'No Plan') ?></div>
        <div style="font-size: 13px; color: #94a3b8; margin-bottom: 20px;">Status: <span class="badge badge-success"><?= ucfirst($reseller['subscription_status']) ?></span></div>
        
        <div style="padding: 15px; background: rgba(255,255,255,0.05); border-radius: 12px; margin-bottom: 20px;">
            <div style="font-size: 11px; color: #94a3b8; margin-bottom: 4px;">Next Payment Due</div>
            <div style="font-size: 16px; font-weight: 700; color: white;"><?= $reseller['subscription_ends_at'] ? date('d F Y', strtotime($reseller['subscription_ends_at'])) : 'N/A' ?></div>
        </div>

        <button class="btn btn-primary w-full" style="background: #1e3a8a;">Manage Platform Subscription</button>
    </div>
</div>

<script>
const ctx = document.getElementById('incomeChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= $chartLabels ?>,
        datasets: [{
            label: 'Monthly Income',
            data: <?= $chartValues ?>,
            backgroundColor: '<?= $primaryColor ?>',
            borderRadius: 8,
            maxBarThickness: 40
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

<?php resellerLayoutEnd(); ?>
