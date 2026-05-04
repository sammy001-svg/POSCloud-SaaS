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

    if ($action === 'create_po') {
        $sid   = (int)$_POST['supplier_id'];
        $brid  = (int)$_POST['branch_id'];
        $date  = $_POST['ordered_at'] ?: date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $items = $_POST['items'] ?? []; // Array of {product_id, qty, cost}

        if (empty($sid)) { $err = 'Supplier is required.'; }
        elseif (empty($items)) { $err = 'Add at least one item to the order.'; }
        else {
            $db->beginTransaction();
            try {
                $poNumber = 'PO-' . strtoupper(substr(md5(uniqid()), 0, 8));
                $stmt = $db->prepare("INSERT INTO purchase_orders (tenant_id, branch_id, supplier_id, po_number, status, ordered_at, notes, created_by) VALUES (?,?,?,?,'ordered',?,?,?)");
                $stmt->execute([$tid, $brid, $sid, $poNumber, $date, $notes, currentUser()['id']]);
                $poId = $db->lastInsertId();

                $total = 0;
                $itemStmt = $db->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_cost) VALUES (?,?,?,?)");
                foreach ($items as $item) {
                    $itemStmt->execute([$poId, $item['product_id'], $item['qty'], $item['cost']]);
                    $total += ($item['qty'] * $item['cost']);
                }

                $db->prepare("UPDATE purchase_orders SET total_amount=? WHERE id=?")->execute([$total, $poId]);
                
                $db->commit();
                $msg = "Purchase Order {$poNumber} created successfully.";
            } catch (Exception $e) {
                $db->rollBack();
                $err = $e->getMessage();
            }
        }
    }

    if ($action === 'receive_po') {
        $poId = (int)$_POST['po_id'];
        $db->beginTransaction();
        try {
            $po = $db->prepare("SELECT * FROM purchase_orders WHERE id=? AND tenant_id=?");
            $po->execute([$poId, $tid]);
            $poData = $po->fetch();

            if ($poData['status'] === 'received') throw new Exception('Already received.');

            $items = $db->prepare("SELECT * FROM purchase_order_items WHERE po_id=?");
            $items->execute([$poId]);
            $poItems = $items->fetchAll();

            foreach ($poItems as $item) {
                // Update stock
                $db->prepare("UPDATE stock_levels SET quantity = quantity + ? WHERE tenant_id=? AND branch_id=? AND product_id=?")
                   ->execute([$item['quantity_ordered'], $tid, $poData['branch_id'], $item['product_id']]);
                
                // Update PO item received qty
                $db->prepare("UPDATE purchase_order_items SET quantity_received = quantity_ordered WHERE id=?")
                   ->execute([$item['id']]);

                // Log movement
                $db->prepare("INSERT INTO stock_movements (tenant_id, branch_id, product_id, movement_type, quantity, unit_cost, reference_id, reference_type, user_id) VALUES (?,?,?, 'purchase', ?, ?, ?, 'purchase_order', ?)")
                   ->execute([$tid, $poData['branch_id'], $item['product_id'], $item['quantity_ordered'], $item['unit_cost'], $poId, currentUser()['id']]);
            }

            $db->prepare("UPDATE purchase_orders SET status='received', received_at=NOW() WHERE id=?")->execute([$poId]);
            
            // Update supplier balance
            $db->prepare("UPDATE suppliers SET balance = balance + ? WHERE id=?")->execute([$poData['total_amount'], $poData['supplier_id']]);

            $db->commit();
            $msg = "PO received and stock updated.";
        } catch (Exception $e) {
            $db->rollBack();
            $err = $e->getMessage();
        }
    }
}

$orders = $db->prepare("
    SELECT po.*, s.name as supplier_name, b.branch_name, u.name as creator_name
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN tenant_branches b ON b.id = po.branch_id
    JOIN tenant_users u ON u.id = po.created_by
    WHERE po.tenant_id = ?
    ORDER BY po.created_at DESC
");
$orders->execute([$tid]);
$pos = $orders->fetchAll();

$suppliers = $db->prepare("SELECT id, name FROM suppliers WHERE tenant_id=? AND is_active=1");
$suppliers->execute([$tid]);
$suppliers = $suppliers->fetchAll();

$branches = $db->prepare("SELECT id, branch_name FROM tenant_branches WHERE tenant_id=? AND is_active=1");
$branches->execute([$tid]);
$branches = $branches->fetchAll();

$products = $db->prepare("SELECT id, name, buying_price FROM products WHERE tenant_id=? AND deleted_at IS NULL AND is_active=1");
$products->execute([$tid]);
$products = $products->fetchAll();

clientLayout('Purchase Orders', 'inventory');
?>

<div class="page-header">
  <div><div class="page-title">Purchase Orders</div><div class="page-subtitle">Manage inventory restocks and supplier orders</div></div>
  <button class="btn btn-primary" onclick="openModal('poModal')">+ New Purchase Order</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>PO Number</th><th>Supplier</th><th>Branch</th><th>Date</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($pos)): ?>
          <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">No purchase orders yet.</td></tr>
        <?php else: foreach ($pos as $p): ?>
          <tr>
            <td style="font-weight: 700; color: var(--primary);"><?= htmlspecialchars($p['po_number']) ?></td>
            <td><?= htmlspecialchars($p['supplier_name']) ?></td>
            <td><?= htmlspecialchars($p['branch_name']) ?></td>
            <td><?= date('d M Y', strtotime($p['ordered_at'])) ?></td>
            <td style="font-weight: 700;">KSh <?= number_format($p['total_amount'], 2) ?></td>
            <td>
                <?php if ($p['status'] === 'received'): ?>
                    <span class="badge badge-success">Received</span>
                <?php elseif ($p['status'] === 'ordered'): ?>
                    <span class="badge badge-info">Ordered</span>
                <?php else: ?>
                    <span class="badge badge-muted"><?= ucfirst($p['status']) ?></span>
                <?php endif; ?>
            </td>
            <td>
              <div class="flex gap-1">
                <?php if ($p['status'] === 'ordered'): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Mark as received? This will update stock levels.');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                    <input type="hidden" name="action" value="receive_po"/>
                    <input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
                    <button class="btn btn-sm btn-success">Mark Received</button>
                </form>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline" onclick="alert('View items logic here')">👁️</button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- PO Modal -->
<div class="modal-overlay" id="poModal" style="display: none;">
  <div class="modal" style="max-width: 800px;">
    <div class="modal-header">
      <span style="font-weight: 700;">➕ Create Purchase Order</span>
      <button class="modal-close" onclick="closeModal('poModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create_po"/>
      <div class="modal-body">
        <div class="grid-3" style="margin-bottom: 20px;">
            <div class="form-group">
                <label class="form-label">Supplier *</label>
                <select class="form-control" name="supplier_id" required>
                    <option value="">— Select Supplier —</option>
                    <?php foreach($suppliers as $s): ?><option value="<?=$s['id']?>"><?=$s['name']?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Destination Branch</label>
                <select class="form-control" name="branch_id">
                    <?php foreach($branches as $b): ?><option value="<?=$b['id']?>"><?=$b['branch_name']?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Order Date</label>
                <input class="form-control" type="date" name="ordered_at" value="<?= date('Y-m-d') ?>"/>
            </div>
        </div>

        <div style="font-weight: 700; margin-bottom: 10px; font-size: 14px;">Order Items</div>
        <div class="table-wrap" style="background: var(--bg-dark); border: 1px solid var(--border); border-radius: 8px;">
            <table id="poItemsTable">
                <thead><tr><th>Product</th><th>Unit Cost</th><th>Quantity</th><th>Subtotal</th><th></th></tr></thead>
                <tbody>
                    <!-- JS will inject rows here -->
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-outline btn-sm" style="margin-top: 10px;" onclick="addRow()">+ Add Item</button>

        <div class="form-group" style="margin-top: 20px;">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('poModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Order</button>
      </div>
    </form>
  </div>
</div>

<script>
const products = <?= json_encode($products) ?>;
let rowCount = 0;

function addRow() {
    const table = document.getElementById('poItemsTable').getElementsByTagName('tbody')[0];
    const row = table.insertRow();
    row.id = 'row_' + rowCount;
    
    row.innerHTML = `
        <td>
            <select name="items[${rowCount}][product_id]" class="form-control" onchange="updateRowCost(this, ${rowCount})" required>
                <option value="">— Select Product —</option>
                ${products.map(p => `<option value="${p.id}" data-cost="${p.buying_price}">${p.name}</option>`).join('')}
            </select>
        </td>
        <td><input type="number" name="items[${rowCount}][cost]" step="0.01" class="form-control" id="cost_${rowCount}" oninput="calcRow(${rowCount})" required></td>
        <td><input type="number" name="items[${rowCount}][qty]" step="0.001" class="form-control" id="qty_${rowCount}" oninput="calcRow(${rowCount})" required></td>
        <td id="subtotal_${rowCount}" style="font-weight: 700;">0.00</td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(${rowCount})">✕</button></td>
    `;
    rowCount++;
}

function updateRowCost(select, id) {
    const cost = select.options[select.selectedIndex].getAttribute('data-cost');
    document.getElementById('cost_' + id).value = cost || 0;
    calcRow(id);
}

function calcRow(id) {
    const cost = parseFloat(document.getElementById('cost_' + id).value) || 0;
    const qty = parseFloat(document.getElementById('qty_' + id).value) || 0;
    document.getElementById('subtotal_' + id).textContent = (cost * qty).toLocaleString(undefined, {minimumFractionDigits: 2});
}

function removeRow(id) {
    const row = document.getElementById('row_' + id);
    row.parentNode.removeChild(row);
}

// Start with one row
addRow();
</script>

<?php clientLayoutEnd(); ?>
