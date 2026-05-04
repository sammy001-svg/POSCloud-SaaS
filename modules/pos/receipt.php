<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_CASHIER);

$db = getDB();
$tid = currentUser()['tenant_id'];
$sid = (int)($_GET['sale_id'] ?? 0);

if (!$sid) die('Invalid Sale ID');

// Fetch Sale Data
$stmt = $db->prepare("
    SELECT s.*, u.name as cashier_name, c.name as customer_name, b.branch_name, b.address as branch_address, b.phone as branch_phone
    FROM sales s
    JOIN tenant_users u ON u.id = s.user_id
    LEFT JOIN customers c ON c.id = s.customer_id
    JOIN tenant_branches b ON b.id = s.branch_id
    WHERE s.id=? AND s.tenant_id=?
");
$stmt->execute([$sid, $tid]);
$sale = $stmt->fetch();

if (!$sale) die('Sale not found.');

// Fetch Sale Items
$itemsStmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id=?");
$itemsStmt->execute([$sid]);
$items = $itemsStmt->fetchAll();

// Fetch Tenant Info
$tenantStmt = $db->prepare("SELECT * FROM tenants WHERE id=?");
$tenantStmt->execute([$tid]);
$tenant = $tenantStmt->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?= $sale['sale_number'] ?></title>
    <style>
        body { font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.4; color: #000; margin: 0; padding: 20px; width: 80mm; background: #fff; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .header { margin-bottom: 20px; border-bottom: 1px dashed #000; padding-bottom: 10px; }
        .title { font-size: 18px; text-transform: uppercase; margin-bottom: 5px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 2px; font-size: 11px; }
        .table { width: 100%; margin: 15px 0; border-bottom: 1px dashed #000; border-top: 1px dashed #000; padding: 10px 0; }
        .table-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .totals { margin-top: 10px; }
        .total-row { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 3px; }
        .footer { margin-top: 30px; border-top: 1px dashed #000; padding-top: 10px; font-size: 11px; }
        @media print {
            body { padding: 0; width: 80mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="background: #f1f5f9; padding: 10px; margin-bottom: 20px; border-radius: 8px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #1e3a8a; color: white; border: none; border-radius: 4px; cursor: pointer; font-family: sans-serif; font-weight: bold;">Print Receipt</button>
        <button onclick="window.close()" style="padding: 10px 20px; background: #94a3b8; color: white; border: none; border-radius: 4px; cursor: pointer; font-family: sans-serif; margin-left: 10px;">Close</button>
    </div>

    <div class="header center">
        <?php if ($tenant['logo_path']): ?>
            <img src="<?= APP_URL ?>/uploads/logos/<?= $tenant['logo_path'] ?>" style="max-width: 60mm; max-height: 20mm; margin-bottom: 10px;"><br>
        <?php endif; ?>
        <div class="title bold"><?= htmlspecialchars($tenant['business_name']) ?></div>
        <div style="font-size: 12px;"><?= htmlspecialchars($sale['branch_name']) ?></div>
        <div style="font-size: 11px;"><?= htmlspecialchars($sale['branch_address']) ?></div>
        <div style="font-size: 11px;">Tel: <?= htmlspecialchars($sale['branch_phone']) ?></div>
        <div style="margin-top: 10px;"><?= htmlspecialchars_decode($tenant['receipt_header']) ?></div>
    </div>

    <div class="info-row"><span>Date:</span><span><?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?></span></div>
    <div class="info-row"><span>Receipt No:</span><span class="bold"><?= $sale['sale_number'] ?></span></div>
    <div class="info-row"><span>Cashier:</span><span><?= htmlspecialchars($sale['cashier_name']) ?></span></div>
    <?php if ($sale['customer_name']): ?>
        <div class="info-row"><span>Customer:</span><span><?= htmlspecialchars($sale['customer_name']) ?></span></div>
    <?php endif; ?>

    <div class="table">
        <?php foreach ($items as $item): ?>
            <div class="table-row">
                <div style="flex: 2;">
                    <div><?= htmlspecialchars($item['product_name']) ?></div>
                    <div style="font-size: 10px; color: #444;"><?= number_format($item['quantity'], 2) ?> x <?= number_format($item['unit_price'], 2) ?></div>
                </div>
                <div style="flex: 1; text-align: right;" class="bold"><?= number_format($item['total_price'], 2) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="totals">
        <div class="total-row"><span>Subtotal</span><span><?= number_format($sale['subtotal'], 2) ?></span></div>
        <div class="total-row"><span><?= $tenant['tax_name'] ?> (<?= $tenant['tax_rate'] ?>%)</span><span><?= number_format($sale['tax_amount'], 2) ?></span></div>
        <?php if ($sale['discount_amount'] > 0): ?>
            <div class="total-row" style="color: #444;"><span>Discount</span><span>-<?= number_format($sale['discount_amount'], 2) ?></span></div>
        <?php endif; ?>
        <div class="total-row bold" style="font-size: 18px; margin-top: 5px; border-top: 1px solid #000; padding-top: 5px;">
            <span>TOTAL</span><span><?= $tenant['currency_symbol'] ?> <?= number_format($sale['total_amount'], 2) ?></span>
        </div>
        <div class="total-row" style="margin-top: 10px; font-size: 11px;"><span>Amount Paid</span><span><?= number_format($sale['amount_paid'], 2) ?></span></div>
        <div class="total-row" style="font-size: 11px;"><span>Change Given</span><span><?= number_format($sale['change_given'], 2) ?></span></div>
    </div>

    <div class="footer center">
        <div style="white-space: pre-line;"><?= htmlspecialchars_decode($tenant['receipt_footer']) ?></div>
        <div style="margin-top: 15px; font-size: 10px;">Software by POSCloud</div>
    </div>

    <script>
        // Auto-print if wanted
        // window.onload = () => { window.print(); }
    </script>
</body>
</html>
