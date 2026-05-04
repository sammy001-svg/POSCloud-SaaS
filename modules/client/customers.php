<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_CASHIER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$msg = $err = '';
$currentPage = 'customers';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim(strtolower($_POST['email'] ?? ''));
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $limit   = (float)($_POST['credit_limit'] ?? 0);

        if (empty($name)) { $err = 'Customer name is required.'; }
        else {
            if ($action === 'create') {
                $code = 'CUST-' . strtoupper(substr(md5(uniqid()), 0, 6));
                $db->prepare("INSERT INTO customers (tenant_id, name, email, phone, address, customer_code, credit_limit) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$tid, $name, $email, $phone, $address, $code, $limit]);
                $msg = "Customer '{$name}' added.";
            } else {
                $cid = (int)$_POST['customer_id'];
                $db->prepare("UPDATE customers SET name=?, email=?, phone=?, address=?, credit_limit=? WHERE id=? AND tenant_id=?")
                   ->execute([$name, $email, $phone, $address, $limit, $cid, $tid]);
                $msg = "Customer updated.";
            }
        }
    }

    if ($action === 'toggle') {
        $cid = (int)$_POST['customer_id'];
        $db->prepare("UPDATE customers SET is_active=NOT is_active WHERE id=? AND tenant_id=?")->execute([$cid, $tid]);
        $msg = 'Status updated.';
    }
}

$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page']??1)); $limit = ITEMS_PER_PAGE; $offset = ($page-1)*$limit;

$where = ["tenant_id=?", "is_active=1"]; $params = [$tid];
if ($search) { $where[] = "(name LIKE ? OR phone LIKE ? OR customer_code LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]); }
$w = 'WHERE ' . implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM customers $w"); $total->execute($params); $totalRows = $total->fetchColumn(); $pages = ceil($totalRows/$limit);

$stmt = $db->prepare("SELECT * FROM customers $w ORDER BY name ASC LIMIT $limit OFFSET $offset");
$stmt->execute($params); $customers = $stmt->fetchAll();

$editCust = null;
if (isset($_GET['edit'])) {
    $ec = $db->prepare("SELECT * FROM customers WHERE id=? AND tenant_id=?");
    $ec->execute([(int)$_GET['edit'], $tid]); $editCust = $ec->fetch();
}

clientLayout('Customers', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Customers</div><div class="page-subtitle"><?= number_format($totalRows) ?> customer(s) registered</div></div>
  <button class="btn btn-primary" onclick="openModal('customerModal')">+ New Customer</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="padding: 16px; margin-bottom: 20px;">
  <form method="GET" class="flex gap-2">
    <div style="flex: 1;"><input class="form-control" name="q" placeholder="🔍 Search name, phone or code..." value="<?= htmlspecialchars($search) ?>"/></div>
    <button class="btn btn-primary">Search</button>
    <a href="customers.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Email</th><th>Credit Bal</th><th>Points</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($customers)): ?>
          <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">No customers found.</td></tr>
        <?php else: foreach ($customers as $c): ?>
          <tr>
            <td style="font-family: monospace; font-size: 12px; color: var(--primary);"><?= htmlspecialchars($c['customer_code']) ?></td>
            <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($c['name']) ?></td>
            <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
            <td style="font-size: 12px;"><?= htmlspecialchars($c['email'] ?? '—') ?></td>
            <td>
                <div style="font-weight: 700; <?= $c['credit_balance'] > 0 ? 'color: var(--danger);' : 'color: var(--success);' ?>">
                    KSh <?= number_format($c['credit_balance'], 2) ?>
                </div>
                <div style="font-size: 10px; color: var(--text-muted);">Limit: KSh <?= number_format($c['credit_limit'], 0) ?></div>
            </td>
            <td><span class="badge badge-info"><?= number_format($c['loyalty_points'], 0) ?></span></td>
            <td>
              <div class="flex gap-1">
                <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline">✏️</a>
                <button class="btn btn-sm btn-outline" onclick="alert('Credit statement logic here')">📜</button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Customer Modal -->
<div class="modal-overlay" id="customerModal" style="display: none;">
  <div class="modal" style="max-width: 500px;">
    <div class="modal-header">
      <span style="font-weight: 700;"><?= $editCust ? '✏️ Edit Customer' : '➕ New Customer' ?></span>
      <button class="modal-close" onclick="closeModal('customerModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editCust ? 'edit' : 'create' ?>"/>
      <?php if ($editCust): ?><input type="hidden" name="customer_id" value="<?= $editCust['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input class="form-control" name="name" required value="<?= htmlspecialchars($editCust['name'] ?? '') ?>" placeholder="e.g. John Doe"/>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Phone Number</label>
            <input class="form-control" name="phone" value="<?= htmlspecialchars($editCust['phone'] ?? '') ?>" placeholder="+254..."/>
          </div>
          <div class="form-group">
            <label class="form-label">Email Address</label>
            <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($editCust['email'] ?? '') ?>" placeholder="john@example.com"/>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($editCust['address'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Credit Limit (KSh)</label>
          <input class="form-control" type="number" name="credit_limit" step="0.01" value="<?= $editCust['credit_limit'] ?? '0.00' ?>"/>
          <small style="color: var(--text-muted); font-size: 11px;">Maximum amount of credit allowed for this customer.</small>
        </div>
      </div>
      <div class="modal-footer">
        <a href="customers.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editCust ? 'Save Changes' : 'Add Customer' ?></button>
      </div>
    </form>
  </div>
</div>
<script><?php if($editCust || $err): ?>openModal('customerModal');<?php endif; ?></script>
<?php clientLayoutEnd(); ?>
