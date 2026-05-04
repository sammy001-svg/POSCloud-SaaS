<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$msg = $err = '';
$currentPage = 'branches';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name    = trim($_POST['branch_name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $is_main = isset($_POST['is_main']) ? 1 : 0;

        if (empty($name)) { $err = 'Branch name is required.'; }
        else {
            if ($is_main) {
                // Remove main from others
                $db->prepare("UPDATE tenant_branches SET is_main=0 WHERE tenant_id=?")->execute([$tid]);
            }
            if ($action === 'create') {
                $db->prepare("INSERT INTO tenant_branches (tenant_id, branch_name, phone, address, is_main) VALUES (?,?,?,?,?)")
                   ->execute([$tid, $name, $phone, $address, $is_main]);
                $brid = $db->lastInsertId();
                // Initialize stock records for all products in this new branch
                $products = $db->prepare("SELECT id FROM products WHERE tenant_id=? AND deleted_at IS NULL");
                $products->execute([$tid]);
                foreach($products->fetchAll() as $p) {
                    $db->prepare("INSERT INTO stock_levels (tenant_id, branch_id, product_id, quantity) VALUES (?,?,?,0)")
                       ->execute([$tid, $brid, $p['id']]);
                }
                $msg = "Branch '{$name}' created.";
            } else {
                $brid = (int)$_POST['branch_id'];
                $db->prepare("UPDATE tenant_branches SET branch_name=?, phone=?, address=?, is_main=? WHERE id=? AND tenant_id=?")
                   ->execute([$name, $phone, $address, $is_main, $brid, $tid]);
                $msg = "Branch updated.";
            }
        }
    }

    if ($action === 'toggle') {
        $brid = (int)$_POST['branch_id'];
        $db->prepare("UPDATE tenant_branches SET is_active=NOT is_active WHERE id=? AND tenant_id=? AND is_main=0")->execute([$brid, $tid]);
        $msg = 'Status updated.';
    }
}

$branches = $db->prepare("
    SELECT b.*, 
    (SELECT COUNT(*) FROM tenant_users WHERE branch_id=b.id) as user_count,
    (SELECT COUNT(*) FROM pos_terminals WHERE branch_id=b.id) as terminal_count
    FROM tenant_branches b 
    WHERE b.tenant_id=? 
    ORDER BY b.is_main DESC, b.branch_name ASC
");
$branches->execute([$tid]);
$branches = $branches->fetchAll();

$editBranch = null;
if (isset($_GET['edit'])) {
    $eb = $db->prepare("SELECT * FROM tenant_branches WHERE id=? AND tenant_id=?");
    $eb->execute([(int)$_GET['edit'], $tid]); $editBranch = $eb->fetch();
}

clientLayout('Branches', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Branches & Locations</div><div class="page-subtitle">Manage your business branches and their settings</div></div>
  <button class="btn btn-primary" onclick="openModal('branchModal')">+ New Branch</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-3">
    <?php foreach ($branches as $b): ?>
    <div class="card" style="position: relative; <?= !$b['is_active'] ? 'opacity: 0.6;' : '' ?>">
        <?php if ($b['is_main']): ?>
            <div class="badge badge-success" style="position: absolute; top: 16px; right: 16px;">Main Branch</div>
        <?php endif; ?>
        
        <div style="font-size: 24px; margin-bottom: 12px;">🏢</div>
        <div style="font-size: 18px; font-weight: 800; color: var(--text-primary); margin-bottom: 4px;"><?= htmlspecialchars($b['branch_name']) ?></div>
        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">
            <?= htmlspecialchars($b['address'] ?: 'No address set') ?>
        </div>
        
        <div style="display: flex; gap: 15px; margin-bottom: 20px; padding: 12px; background: var(--bg-dark); border-radius: 8px; border: 1px solid var(--border);">
            <div style="text-align: center; flex: 1;">
                <div style="font-weight: 800; color: white;"><?= $b['terminal_count'] ?></div>
                <div style="font-size: 10px; color: var(--text-muted); text-transform: uppercase;">Terminals</div>
            </div>
            <div style="width: 1px; background: var(--border);"></div>
            <div style="text-align: center; flex: 1;">
                <div style="font-weight: 800; color: white;"><?= $b['user_count'] ?></div>
                <div style="font-size: 10px; color: var(--text-muted); text-transform: uppercase;">Staff</div>
            </div>
        </div>

        <div class="flex gap-1">
            <a href="?edit=<?= $b['id'] ?>" class="btn btn-sm btn-outline" style="flex: 1;">✏️ Edit</a>
            <form method="POST" style="flex: 1;">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                <input type="hidden" name="action" value="toggle"/>
                <input type="hidden" name="branch_id" value="<?= $b['id'] ?>"/>
                <button class="btn btn-sm btn-outline w-full" <?= $b['is_main'] ? 'disabled' : '' ?>><?= $b['is_active'] ? '⏸ Disable' : '▶ Enable' ?></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Branch Modal -->
<div class="modal-overlay" id="branchModal" style="display: none;">
  <div class="modal" style="max-width: 450px;">
    <div class="modal-header">
      <span style="font-weight: 700;"><?= $editBranch ? '✏️ Edit Branch' : '➕ New Branch' ?></span>
      <button class="modal-close" onclick="closeModal('branchModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editBranch ? 'edit' : 'create' ?>"/>
      <?php if ($editBranch): ?><input type="hidden" name="branch_id" value="<?= $editBranch['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Branch Name *</label>
          <input class="form-control" name="branch_name" required value="<?= htmlspecialchars($editBranch['branch_name'] ?? '') ?>" placeholder="e.g. Westlands Branch"/>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input class="form-control" name="phone" value="<?= htmlspecialchars($editBranch['phone'] ?? '') ?>"/>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea class="form-control" name="address" rows="3"><?= htmlspecialchars($editBranch['address'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                <input type="checkbox" name="is_main" value="1" <?= ($editBranch['is_main'] ?? 0) ? 'checked' : '' ?> style="width: 16px; height: 16px; accent-color: var(--primary);"/>
                <span style="font-size: 13px; font-weight: 500;">Set as Main Branch</span>
            </label>
        </div>
      </div>
      <div class="modal-footer">
        <a href="branches.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editBranch ? 'Save Changes' : 'Create Branch' ?></button>
      </div>
    </form>
  </div>
</div>
<script><?php if($editBranch || $err): ?>openModal('branchModal');<?php endif; ?></script>
<?php clientLayoutEnd(); ?>
