<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_INVENTORY);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$msg = $err = '';
$currentPage = 'suppliers';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name    = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_name'] ?? '');
        $email   = trim(strtolower($_POST['email'] ?? ''));
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($name)) { $err = 'Supplier name is required.'; }
        else {
            if ($action === 'create') {
                $db->prepare("INSERT INTO suppliers (tenant_id, name, contact_name, email, phone, address) VALUES (?,?,?,?,?,?)")
                   ->execute([$tid, $name, $contact, $email, $phone, $address]);
                $msg = "Supplier '{$name}' added.";
            } else {
                $sid = (int)$_POST['supplier_id'];
                $db->prepare("UPDATE suppliers SET name=?, contact_name=?, email=?, phone=?, address=? WHERE id=? AND tenant_id=?")
                   ->execute([$name, $contact, $email, $phone, $address, $sid, $tid]);
                $msg = "Supplier updated.";
            }
        }
    }

    if ($action === 'toggle') {
        $sid = (int)$_POST['supplier_id'];
        $db->prepare("UPDATE suppliers SET is_active=NOT is_active WHERE id=? AND tenant_id=?")->execute([$sid, $tid]);
        $msg = 'Status updated.';
    }
}

$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page']??1)); $limit = ITEMS_PER_PAGE; $offset = ($page-1)*$limit;

$where = ["tenant_id=?", "is_active=1"]; $params = [$tid];
if ($search) { $where[] = "(name LIKE ? OR contact_name LIKE ? OR phone LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]); }
$w = 'WHERE ' . implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM suppliers $w"); $total->execute($params); $totalRows = $total->fetchColumn(); $pages = ceil($totalRows/$limit);

$stmt = $db->prepare("SELECT * FROM suppliers $w ORDER BY name ASC LIMIT $limit OFFSET $offset");
$stmt->execute($params); $suppliers = $stmt->fetchAll();

$editSup = null;
if (isset($_GET['edit'])) {
    $es = $db->prepare("SELECT * FROM suppliers WHERE id=? AND tenant_id=?");
    $es->execute([(int)$_GET['edit'], $tid]); $editSup = $es->fetch();
}

clientLayout('Suppliers', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Suppliers</div><div class="page-subtitle"><?= number_format($totalRows) ?> supplier(s) managed</div></div>
  <button class="btn btn-primary" onclick="openModal('supplierModal')">+ New Supplier</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="padding: 16px; margin-bottom: 20px;">
  <form method="GET" class="flex gap-2">
    <div style="flex: 1;"><input class="form-control" name="q" placeholder="🔍 Search name, contact or phone..." value="<?= htmlspecialchars($search) ?>"/></div>
    <button class="btn btn-primary">Search</button>
    <a href="suppliers.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>Balance Due</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($suppliers)): ?>
          <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No suppliers found.</td></tr>
        <?php else: foreach ($suppliers as $s): ?>
          <tr>
            <td style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['contact_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($s['phone'] ?? '—') ?></td>
            <td style="font-size: 12px;"><?= htmlspecialchars($s['email'] ?? '—') ?></td>
            <td style="font-weight: 700; color: var(--danger);">KSh <?= number_format($s['balance'], 2) ?></td>
            <td>
              <div class="flex gap-1">
                <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline">✏️</a>
                <button class="btn btn-sm btn-outline" title="Purchase History">📦</button>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Supplier Modal -->
<div class="modal-overlay" id="supplierModal" style="display: none;">
  <div class="modal" style="max-width: 500px;">
    <div class="modal-header">
      <span style="font-weight: 700;"><?= $editSup ? '✏️ Edit Supplier' : '➕ New Supplier' ?></span>
      <button class="modal-close" onclick="closeModal('supplierModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editSup ? 'edit' : 'create' ?>"/>
      <?php if ($editSup): ?><input type="hidden" name="supplier_id" value="<?= $editSup['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Supplier Company Name *</label>
          <input class="form-control" name="name" required value="<?= htmlspecialchars($editSup['name'] ?? '') ?>" placeholder="e.g. Acme Pharma Ltd"/>
        </div>
        <div class="form-group">
          <label class="form-label">Contact Person</label>
          <input class="form-control" name="contact_name" value="<?= htmlspecialchars($editSup['contact_name'] ?? '') ?>" placeholder="e.g. Jane Smith"/>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone" value="<?= htmlspecialchars($editSup['phone'] ?? '') ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($editCust['email'] ?? '') ?>"/>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($editSup['address'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <a href="suppliers.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editSup ? 'Save Changes' : 'Add Supplier' ?></button>
      </div>
    </form>
  </div>
</div>
<script><?php if($editSup || $err): ?>openModal('supplierModal');<?php endif; ?></script>
<?php clientLayoutEnd(); ?>
