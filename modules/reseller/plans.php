<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_RESELLER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$rid = currentUser()['reseller_id'];
$msg = $err = '';
$currentPage = 'plans';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name    = trim($_POST['plan_name'] ?? '');
        $price   = (float)($_POST['price'] ?? 0);
        $cycle   = $_POST['billing_cycle'] ?? 'monthly';
        $maxBr   = ($_POST['max_branches']  ?? '') === '' ? null : (int)$_POST['max_branches'];
        $maxTerm = ($_POST['max_terminals'] ?? '') === '' ? null : (int)$_POST['max_terminals'];
        $maxUsr  = ($_POST['max_users']     ?? '') === '' ? null : (int)$_POST['max_users'];
        $maxProd = ($_POST['max_products']  ?? '') === '' ? null : (int)$_POST['max_products'];

        if (empty($name)) { $err = 'Plan name is required.'; }
        else {
            if ($action === 'create') {
                $db->prepare("INSERT INTO reseller_plans (reseller_id,plan_name,price,billing_cycle,max_branches,max_terminals,max_users,max_products) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$rid,$name,$price,$cycle,$maxBr,$maxTerm,$maxUsr,$maxProd]);
                $msg = "Plan '{$name}' created.";
            } else {
                $pid = (int)$_POST['plan_id'];
                $db->prepare("UPDATE reseller_plans SET plan_name=?,price=?,billing_cycle=?,max_branches=?,max_terminals=?,max_users=?,max_products=? WHERE id=? AND reseller_id=?")
                   ->execute([$name,$price,$cycle,$maxBr,$maxTerm,$maxUsr,$maxProd,$pid,$rid]);
                $msg = 'Plan updated.';
            }
        }
    }

    if ($action === 'toggle') {
        $pid = (int)$_POST['plan_id'];
        $db->prepare("UPDATE reseller_plans SET is_active=NOT is_active WHERE id=? AND reseller_id=?")->execute([$pid,$rid]);
        $msg = 'Plan status toggled.';
    }

    if ($action === 'delete') {
        $pid = (int)$_POST['plan_id'];
        // Only delete if no clients using it
        $using = $db->prepare("SELECT COUNT(*) FROM tenants WHERE reseller_plan_id=?"); $using->execute([$pid]);
        if ($using->fetchColumn() > 0) { $err = 'Cannot delete — clients are currently on this plan.'; }
        else {
            $db->prepare("DELETE FROM reseller_plans WHERE id=? AND reseller_id=?")->execute([$pid,$rid]);
            $msg = 'Plan deleted.';
        }
    }
}

$plans = $db->prepare("SELECT rp.*, (SELECT COUNT(*) FROM tenants t WHERE t.reseller_plan_id=rp.id) AS client_count FROM reseller_plans rp WHERE rp.reseller_id=? ORDER BY rp.price ASC");
$plans->execute([$rid]);
$plans = $plans->fetchAll();

$editPlan = null;
if (isset($_GET['edit'])) {
    foreach ($plans as $p) { if ($p['id'] == $_GET['edit']) { $editPlan = $p; break; } }
}

resellerLayout('My Plans', $currentPage);
$primaryColor = $GLOBALS['_primary'] ?? '#1e3a8a';
?>

<div class="page-header">
  <div><div class="page-title">Subscription Plans</div><div class="page-subtitle">Custom pricing plans for your clients</div></div>
  <button class="btn btn-primary" onclick="openModal('planModal')">+ New Plan</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if (empty($plans)): ?>
  <div class="card" style="text-align:center;padding:60px;border:2px dashed var(--border);">
    <div style="font-size:48px;margin-bottom:16px;">📦</div>
    <div style="font-size:18px;font-weight:700;margin-bottom:8px;">No plans yet</div>
    <div style="color:var(--text-muted);margin-bottom:20px;">Create subscription plans to offer your clients.</div>
    <button class="btn btn-primary" onclick="openModal('planModal')">Create First Plan</button>
  </div>
<?php else: ?>
  <div class="grid-3">
    <?php foreach ($plans as $p): ?>
    <div class="card" style="position:relative;transition:transform .2s;<?= !$p['is_active']?'opacity:.6;':'' ?>"
      onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
      <?php if ($p['client_count'] > 0): ?>
        <div class="badge badge-success" style="position:absolute;top:16px;right:16px;"><?= $p['client_count'] ?> clients</div>
      <?php elseif (!$p['is_active']): ?>
        <div class="badge badge-muted" style="position:absolute;top:16px;right:16px;">Inactive</div>
      <?php endif; ?>

      <div style="font-size:28px;margin-bottom:12px;">🏷️</div>
      <div style="font-size:19px;font-weight:800;color:var(--text-primary);margin-bottom:4px;"><?= htmlspecialchars($p['plan_name']) ?></div>
      <div style="font-size:32px;font-weight:900;color:<?= $primaryColor ?>;margin:10px 0 16px;">
        KSh <?= number_format($p['price'],0) ?>
        <span style="font-size:14px;font-weight:400;color:var(--text-muted);">/<?= $p['billing_cycle'] ?></span>
      </div>

      <div style="border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:16px;display:flex;flex-direction:column;gap:8px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;">
          <span style="color:var(--text-muted);">🏢 Branches</span>
          <span style="font-weight:600;"><?= $p['max_branches'] ?? '∞ Unlimited' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;">
          <span style="color:var(--text-muted);">🖥 Terminals</span>
          <span style="font-weight:600;"><?= $p['max_terminals'] ?? '∞ Unlimited' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;">
          <span style="color:var(--text-muted);">👤 Users</span>
          <span style="font-weight:600;"><?= $p['max_users'] ?? '∞ Unlimited' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;">
          <span style="color:var(--text-muted);">📦 Products</span>
          <span style="font-weight:600;"><?= $p['max_products'] ?? '∞ Unlimited' ?></span>
        </div>
      </div>

      <div class="flex gap-1">
        <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline" style="flex:1;">✏️ Edit</a>
        <form method="POST" style="flex:1;">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="action" value="toggle"/>
          <input type="hidden" name="plan_id" value="<?= $p['id'] ?>"/>
          <button class="btn btn-sm btn-outline w-full"><?= $p['is_active']?'⏸ Disable':'▶ Enable' ?></button>
        </form>
        <?php if ($p['client_count'] == 0): ?>
        <form method="POST" onsubmit="return confirm('Delete this plan?');">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="action" value="delete"/>
          <input type="hidden" name="plan_id" value="<?= $p['id'] ?>"/>
          <button class="btn btn-sm btn-danger">🗑</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="card" style="border:2px dashed var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;cursor:pointer;min-height:240px;"
      onclick="openModal('planModal')">
      <div style="font-size:40px;color:var(--text-muted);">+</div>
      <div style="color:var(--text-muted);font-size:13px;">Add New Plan</div>
    </div>
  </div>
<?php endif; ?>

<!-- Plan Modal -->
<div class="modal-overlay" id="planModal" style="display:none;">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <span style="font-weight:700;"><?= $editPlan?'✏️ Edit Plan':'➕ Create Plan' ?></span>
      <button class="modal-close" onclick="closeModal('planModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editPlan?'edit':'create' ?>"/>
      <?php if ($editPlan): ?><input type="hidden" name="plan_id" value="<?= $editPlan['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Plan Name *</label>
            <input class="form-control" name="plan_name" required value="<?= htmlspecialchars($editPlan['plan_name']??'') ?>" placeholder="e.g. Basic, Pro"/>
          </div>
          <div class="form-group">
            <label class="form-label">Price (KSh) *</label>
            <input class="form-control" type="number" name="price" step="0.01" required value="<?= $editPlan['price']??'' ?>" placeholder="0.00"/>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Billing Cycle</label>
          <select class="form-control" name="billing_cycle">
            <option value="monthly" <?= ($editPlan['billing_cycle']??'monthly')==='monthly'?'selected':'' ?>>Monthly</option>
            <option value="yearly"  <?= ($editPlan['billing_cycle']??'')==='yearly'?'selected':'' ?>>Yearly</option>
          </select>
        </div>
        <div style="color:var(--text-muted);font-size:12px;margin-bottom:10px;">Leave blank for unlimited.</div>
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Max Branches</label><input class="form-control" type="number" name="max_branches" value="<?= $editPlan['max_branches']??'' ?>" placeholder="Unlimited"/></div>
          <div class="form-group"><label class="form-label">Max Terminals</label><input class="form-control" type="number" name="max_terminals" value="<?= $editPlan['max_terminals']??'' ?>" placeholder="Unlimited"/></div>
          <div class="form-group"><label class="form-label">Max Users</label><input class="form-control" type="number" name="max_users" value="<?= $editPlan['max_users']??'' ?>" placeholder="Unlimited"/></div>
          <div class="form-group"><label class="form-label">Max Products</label><input class="form-control" type="number" name="max_products" value="<?= $editPlan['max_products']??'' ?>" placeholder="Unlimited"/></div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="plans.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editPlan?'Save Changes':'Create Plan' ?></button>
      </div>
    </form>
  </div>
</div>
<script><?php if($editPlan||$err): ?>openModal('planModal');<?php endif; ?></script>
<?php resellerLayoutEnd(); ?>
