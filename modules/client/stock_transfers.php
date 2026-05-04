<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_INVENTORY);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$msg = $err = '';
$currentPage = 'inventory';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'transfer') {
        $from_bid = (int)$_POST['from_branch_id'];
        $to_bid   = (int)$_POST['to_branch_id'];
        $pid      = (int)$_POST['product_id'];
        $qty      = (float)$_POST['quantity'];
        $notes    = trim($_POST['notes'] ?? '');

        if ($from_bid === $to_bid) { $err = 'Source and destination branches must be different.'; }
        elseif ($qty <= 0) { $err = 'Quantity must be greater than 0.'; }
        else {
            $db->beginTransaction();
            try {
                // Check source stock
                $stmt = $db->prepare("SELECT quantity FROM stock_levels WHERE branch_id=? AND product_id=? AND tenant_id=?");
                $stmt->execute([$from_bid, $pid, $tid]);
                $sourceQty = (float)$stmt->fetchColumn();

                if ($sourceQty < $qty) {
                    throw new Exception("Insufficient stock in source branch. Available: " . $sourceQty);
                }

                // Deduct from source
                $db->prepare("UPDATE stock_levels SET quantity = quantity - ? WHERE branch_id=? AND product_id=? AND tenant_id=?")
                   ->execute([$qty, $from_bid, $pid, $tid]);

                // Add to destination
                $checkDest = $db->prepare("SELECT id FROM stock_levels WHERE branch_id=? AND product_id=? AND tenant_id=?");
                $checkDest->execute([$to_bid, $pid, $tid]);
                if (!$checkDest->fetch()) {
                    $db->prepare("INSERT INTO stock_levels (tenant_id, branch_id, product_id, quantity) VALUES (?,?,?,?)")
                       ->execute([$tid, $to_bid, $pid, $qty]);
                } else {
                    $db->prepare("UPDATE stock_levels SET quantity = quantity + ? WHERE branch_id=? AND product_id=? AND tenant_id=?")
                       ->execute([$qty, $to_bid, $pid, $tid]);
                }

                // Log movements
                $db->prepare("INSERT INTO stock_movements (tenant_id, branch_id, product_id, movement_type, quantity, notes, user_id) VALUES (?,?,?,'transfer_out',?,?,?)")
                   ->execute([$tid, $from_bid, $pid, -$qty, "Transfer to branch #$to_bid: $notes", currentUser()['id']]);
                
                $db->prepare("INSERT INTO stock_movements (tenant_id, branch_id, product_id, movement_type, quantity, notes, user_id) VALUES (?,?,?,'transfer_in',?,?,?)")
                   ->execute([$tid, $to_bid, $pid, $qty, "Transfer from branch #$from_bid: $notes", currentUser()['id']]);

                $db->commit();
                $msg = "Stock transferred successfully.";
            } catch (Exception $e) {
                $db->rollBack();
                $err = $e->getMessage();
            }
        }
    }
}

$branches = $db->prepare("SELECT id, branch_name FROM tenant_branches WHERE tenant_id=? AND is_active=1");
$branches->execute([$tid]);
$branches = $branches->fetchAll();

$products = $db->prepare("SELECT id, name FROM products WHERE tenant_id=? AND deleted_at IS NULL AND track_stock=1");
$products->execute([$tid]);
$products = $products->fetchAll();

// Get recent transfers
$movements = $db->prepare("
    SELECT sm.*, p.name as product_name, b.branch_name 
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    JOIN tenant_branches b ON b.id = sm.branch_id
    WHERE sm.tenant_id = ? AND sm.movement_type IN ('transfer_in', 'transfer_out')
    ORDER BY sm.created_at DESC LIMIT 50
");
$movements->execute([$tid]);
$recentTransfers = $movements->fetchAll();

clientLayout('Stock Transfers', 'inventory');
?>

<div class="page-header">
  <div><div class="page-title">Stock Transfers</div><div class="page-subtitle">Move inventory between your business locations</div></div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-2">
    <!-- Transfer Form -->
    <div class="card">
        <div style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">📦 Initiate Transfer</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
            <input type="hidden" name="action" value="transfer"/>
            
            <div class="form-group">
                <label class="form-label">Product to Move</label>
                <select class="form-control" name="product_id" required>
                    <option value="">— Select Product —</option>
                    <?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=$p['name']?></option><?php endforeach; ?>
                </select>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">From Branch (Source)</label>
                    <select class="form-control" name="from_branch_id" required>
                        <option value="">— Select —</option>
                        <?php foreach($branches as $b): ?><option value="<?=$b['id']?>"><?=$b['branch_name']?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">To Branch (Destination)</label>
                    <select class="form-control" name="to_branch_id" required>
                        <option value="">— Select —</option>
                        <?php foreach($branches as $b): ?><option value="<?=$b['id']?>"><?=$b['branch_name']?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Quantity to Transfer</label>
                <input type="number" name="quantity" step="0.001" class="form-control" placeholder="0.000" required/>
            </div>

            <div class="form-group">
                <label class="form-label">Transfer Notes</label>
                <textarea class="form-control" name="notes" rows="2" placeholder="Reason for transfer..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-full">Execute Transfer</button>
        </form>
    </div>

    <!-- Recent History -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 700;">📜 Recent Transfer Logs</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Product</th><th>Branch</th><th>Qty</th><th>Time</th></tr></thead>
                <tbody>
                    <?php if (empty($recentTransfers)): ?>
                        <tr><td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">No transfers logged.</td></tr>
                    <?php else: foreach ($recentTransfers as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['product_name']) ?></td>
                            <td><?= htmlspecialchars($m['branch_name']) ?></td>
                            <td style="font-weight: 700; color: <?= $m['movement_type'] == 'transfer_in' ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= $m['movement_type'] == 'transfer_in' ? '+' : '-' ?><?= number_format(abs($m['quantity']), 2) ?>
                            </td>
                            <td style="font-size: 11px; color: var(--text-muted);"><?= date('d/m H:i', strtotime($m['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php clientLayoutEnd(); ?>
