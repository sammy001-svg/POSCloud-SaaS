<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_INVENTORY);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$bid = currentUser()['branch_id'];
$msg = $err = '';
$currentPage = 'inventory';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'adjustment') {
        $pid   = (int)$_POST['product_id'];
        $brid  = (int)$_POST['branch_id'];
        $qty   = (float)$_POST['quantity'];
        $type  = $_POST['adj_type']; // 'add' or 'set'
        $notes = trim($_POST['notes'] ?? '');

        $db->beginTransaction();
        try {
            // Get current stock
            $stmt = $db->prepare("SELECT quantity FROM stock_levels WHERE tenant_id=? AND branch_id=? AND product_id=?");
            $stmt->execute([$tid, $brid, $pid]);
            $current = $stmt->fetchColumn();
            
            if ($current === false) {
                // Initialize if not exists
                $db->prepare("INSERT INTO stock_levels (tenant_id, branch_id, product_id, quantity) VALUES (?,?,?,0)")
                   ->execute([$tid, $brid, $pid]);
                $current = 0;
            }

            $newQty = ($type === 'add') ? ($current + $qty) : $qty;
            $diff = $newQty - $current;

            $db->prepare("UPDATE stock_levels SET quantity=? WHERE tenant_id=? AND branch_id=? AND product_id=?")
               ->execute([$newQty, $tid, $brid, $pid]);

            // Log movement
            $db->prepare("INSERT INTO stock_movements (tenant_id, branch_id, product_id, movement_type, quantity, notes, user_id) VALUES (?,?,?,'adjustment',?,?,?)")
               ->execute([$tid, $brid, $pid, $diff, $notes, currentUser()['id']]);

            $db->commit();
            $msg = 'Inventory adjusted successfully.';
        } catch (Exception $e) {
            $db->rollBack();
            $err = $e->getMessage();
        }
    }
}

$search = trim($_GET['q'] ?? '');
$f_branch = (int)($_GET['branch_id'] ?? $bid);
$f_low = isset($_GET['filter']) && $_GET['filter'] === 'low_stock';

$where = ["p.tenant_id=?", "p.deleted_at IS NULL", "p.track_stock=1"]; $params = [$tid];
if ($search) { $where[] = "(p.name LIKE ? OR p.sku LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%"]); }
if ($f_low) { $where[] = "sl.quantity <= sl.low_stock_threshold"; }

$w = 'WHERE ' . implode(' AND ', $where);

// Grouping by branch if filtered
$stmt = $db->prepare("
    SELECT p.id, p.name, p.sku, p.selling_price, sl.quantity, sl.low_stock_threshold, b.branch_name, b.id as branch_id
    FROM products p
    JOIN stock_levels sl ON sl.product_id = p.id
    JOIN tenant_branches b ON b.id = sl.branch_id
    $w AND sl.branch_id = ?
    ORDER BY p.name ASC
");
$stmt->execute(array_merge($params, [$f_branch]));
$inventory = $stmt->fetchAll();

$branches = $db->prepare("SELECT id, branch_name FROM tenant_branches WHERE tenant_id=? AND is_active=1");
$branches->execute([$tid]);
$branches = $branches->fetchAll();

clientLayout('Inventory', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Inventory Management</div><div class="page-subtitle">Track and adjust stock levels across your branches</div></div>
  <div class="flex gap-2">
    <button class="btn btn-primary" onclick="openModal('adjModal')">⚙️ Manual Adjustment</button>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="padding: 16px; margin-bottom: 20px;">
  <form method="GET" class="flex gap-2" style="flex-wrap: wrap;">
    <div style="flex: 1; min-width: 200px;"><input class="form-control" name="q" placeholder="🔍 Search products..." value="<?= htmlspecialchars($search) ?>"/></div>
    <select class="form-control" name="branch_id" style="width: 200px;" onchange="this.form.submit()">
        <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $f_branch == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted); cursor: pointer;">
        <input type="checkbox" name="filter" value="low_stock" <?= $f_low ? 'checked' : '' ?> onchange="this.form.submit()"> Low Stock Only
    </label>
    <button class="btn btn-primary">Filter</button>
  </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Product</th><th>SKU</th><th>Branch</th><th>Stock Level</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($inventory)): ?>
          <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No matching inventory records.</td></tr>
        <?php else: foreach ($inventory as $i): 
            $status = ($i['quantity'] <= 0) ? 'Out of Stock' : (($i['quantity'] <= $i['low_stock_threshold']) ? 'Low Stock' : 'In Stock');
            $statusColor = ($i['quantity'] <= 0) ? 'badge-danger' : (($i['quantity'] <= $i['low_stock_threshold']) ? 'badge-warning' : 'badge-success');
        ?>
          <tr>
            <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($i['name']) ?></td>
            <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($i['sku'] ?? '—') ?></td>
            <td style="font-size: 12px;"><?= htmlspecialchars($i['branch_name']) ?></td>
            <td style="font-weight: 800; font-size: 15px;"><?= number_format($i['quantity'], 1) ?></td>
            <td><span class="badge <?= $statusColor ?>"><?= $status ?></span></td>
            <td>
              <button class="btn btn-sm btn-outline" onclick="openAdjModal(<?= $i['id'] ?>, '<?= htmlspecialchars($i['name'], ENT_QUOTES) ?>', <?= $i['branch_id'] ?>)">⚡ Adjust</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Adjustment Modal -->
<div class="modal-overlay" id="adjModal" style="display: none;">
  <div class="modal" style="max-width: 450px;">
    <div class="modal-header">
      <span style="font-weight: 700;">⚙️ Stock Adjustment</span>
      <button class="modal-close" onclick="closeModal('adjModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="adjustment"/>
      <input type="hidden" name="product_id" id="adjProductId"/>
      
      <div class="modal-body">
        <div id="adjProductName" style="font-weight: 800; font-size: 16px; margin-bottom: 20px; color: var(--primary);">Select Product</div>
        
        <div class="form-group" id="productSelectorDiv">
            <label class="form-label">Search Product</label>
            <select class="form-control" onchange="document.getElementById('adjProductId').value = this.value">
                <option value="">— Choose Product —</option>
                <?php 
                $prods = $db->prepare("SELECT id, name FROM products WHERE tenant_id=? AND deleted_at IS NULL AND track_stock=1 ORDER BY name");
                $prods->execute([$tid]);
                foreach($prods->fetchAll() as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Branch</label>
            <select class="form-control" name="branch_id" id="adjBranchId">
                <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">Type</label>
                <select class="form-control" name="adj_type">
                    <option value="add">Add/Deduct (+/-)</option>
                    <option value="set">Set Exact Value</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Quantity</label>
                <input class="form-control" type="number" name="quantity" step="0.001" required placeholder="0.000"/>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Reason / Notes</label>
            <textarea class="form-control" name="notes" rows="2" placeholder="e.g. Damage, Restock, Opening stock..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('adjModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Adjustment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAdjModal(id, name, branchId) {
  if (id) {
    document.getElementById('adjProductId').value = id;
    document.getElementById('adjProductName').textContent = '📦 ' + name;
    document.getElementById('productSelectorDiv').style.display = 'none';
    document.getElementById('adjBranchId').value = branchId;
  } else {
    document.getElementById('adjProductName').textContent = 'Manual Stock Adjustment';
    document.getElementById('productSelectorDiv').style.display = 'block';
  }
  openModal('adjModal');
}
</script>

<?php clientLayoutEnd(); ?>
