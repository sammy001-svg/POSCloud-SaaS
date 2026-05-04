<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);

$db = getDB();
$pageTitle = 'Subscription Plans';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name    = trim($_POST['plan_name'] ?? '');
        $type    = $_POST['plan_type'] ?? 'client';
        $price   = (float)($_POST['price'] ?? 0);
        $cycle   = $_POST['billing_cycle'] ?? 'monthly';
        $maxBr   = ($_POST['max_branches'] ?? '') === '' ? null : (int)$_POST['max_branches'];
        $maxTerm = ($_POST['max_terminals'] ?? '') === '' ? null : (int)$_POST['max_terminals'];
        $maxUsr  = ($_POST['max_users'] ?? '') === '' ? null : (int)$_POST['max_users'];
        $maxProd = ($_POST['max_products'] ?? '') === '' ? null : (int)$_POST['max_products'];

        if (empty($name)) { $err = 'Plan name is required.'; }
        else {
            if ($action === 'create') {
                $db->prepare("INSERT INTO subscription_plans (plan_name,plan_type,price,billing_cycle,max_branches,max_terminals,max_users,max_products) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$name,$type,$price,$cycle,$maxBr,$maxTerm,$maxUsr,$maxProd]);
                $msg = "Plan '{$name}' created.";
            } else {
                $pid = (int)($_POST['plan_id'] ?? 0);
                $db->prepare("UPDATE subscription_plans SET plan_name=?,plan_type=?,price=?,billing_cycle=?,max_branches=?,max_terminals=?,max_users=?,max_products=? WHERE id=?")
                   ->execute([$name,$type,$price,$cycle,$maxBr,$maxTerm,$maxUsr,$maxProd,$pid]);
                $msg = "Plan updated.";
            }
        }
    }

    if ($action === 'toggle') {
        $pid = (int)($_POST['plan_id'] ?? 0);
        $db->prepare("UPDATE subscription_plans SET is_active=NOT is_active WHERE id=?")->execute([$pid]);
        $msg = 'Plan status toggled.';
    }
}

$plans = $db->query("SELECT * FROM subscription_plans ORDER BY plan_type, price ASC")->fetchAll();
$editPlan = null;
if (isset($_GET['edit'])) {
    foreach ($plans as $p) { if ($p['id'] == $_GET['edit']) { $editPlan = $p; break; } }
}

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Subscription Plans</div>
    <div class="page-subtitle">Manage platform plans for resellers and direct clients</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('planModal')">+ New Plan</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Reseller Plans -->
<div style="margin-bottom:8px;font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Reseller Plans</div>
<div class="grid-3" style="margin-bottom:28px;">
  <?php foreach (array_filter($plans, fn($p) => $p['plan_type']==='reseller') as $p): ?>
    <div class="card" style="position:relative;<?= !$p['is_active']?'opacity:.6;':'' ?>">
      <?php if (!$p['is_active']): ?><div class="badge badge-muted" style="position:absolute;top:16px;right:16px;">Inactive</div><?php endif; ?>
      <div style="font-size:22px;margin-bottom:8px;">🤝</div>
      <div style="font-size:18px;font-weight:800;color:var(--text-primary);"><?= htmlspecialchars($p['plan_name']) ?></div>
      <div style="font-size:28px;font-weight:800;color:var(--primary);margin:8px 0;">
        KSh <?= number_format($p['price'],0) ?><span style="font-size:14px;font-weight:400;color:var(--text-muted);">/<?= $p['billing_cycle'] ?></span>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px;">
        <div style="font-size:12px;color:var(--text-secondary);">👥 Max clients: <?= $p['max_users'] ?? '∞' ?></div>
        <div style="font-size:12px;color:var(--text-secondary);">🏢 Max branches: <?= $p['max_branches'] ?? '∞' ?></div>
        <div style="font-size:12px;color:var(--text-secondary);">🖥 Max terminals: <?= $p['max_terminals'] ?? '∞' ?></div>
      </div>
      <div class="flex gap-1">
        <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline" style="flex:1;">✏️ Edit</a>
        <form method="POST" style="flex:1;">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="action" value="toggle"/>
          <input type="hidden" name="plan_id" value="<?= $p['id'] ?>"/>
          <button class="btn btn-sm btn-outline w-full"><?= $p['is_active']?'⏸ Disable':'▶ Enable' ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="card" style="border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;cursor:pointer;min-height:200px;" onclick="openModal('planModal');document.querySelector('[name=plan_type]').value='reseller'">
    <div style="font-size:36px;color:var(--text-muted);">+</div>
    <div style="color:var(--text-muted);font-size:13px;">Add Reseller Plan</div>
  </div>
</div>

<!-- Client Plans -->
<div style="margin-bottom:8px;font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Client Plans</div>
<div class="grid-3" style="margin-bottom:28px;">
  <?php foreach (array_filter($plans, fn($p) => $p['plan_type']==='client') as $p): ?>
    <div class="card" style="position:relative;<?= !$p['is_active']?'opacity:.6;':'' ?>">
      <?php if (!$p['is_active']): ?><div class="badge badge-muted" style="position:absolute;top:16px;right:16px;">Inactive</div><?php endif; ?>
      <div style="font-size:22px;margin-bottom:8px;">🏪</div>
      <div style="font-size:18px;font-weight:800;color:var(--text-primary);"><?= htmlspecialchars($p['plan_name']) ?></div>
      <div style="font-size:28px;font-weight:800;color:var(--success);margin:8px 0;">
        KSh <?= number_format($p['price'],0) ?><span style="font-size:14px;font-weight:400;color:var(--text-muted);">/<?= $p['billing_cycle'] ?></span>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px;">
        <div style="font-size:12px;color:var(--text-secondary);">🏢 Branches: <?= $p['max_branches'] ?? '∞' ?></div>
        <div style="font-size:12px;color:var(--text-secondary);">🖥 Terminals: <?= $p['max_terminals'] ?? '∞' ?></div>
        <div style="font-size:12px;color:var(--text-secondary);">👤 Users: <?= $p['max_users'] ?? '∞' ?></div>
        <div style="font-size:12px;color:var(--text-secondary);">📦 Products: <?= $p['max_products'] ?? '∞' ?></div>
      </div>
      <div class="flex gap-1">
        <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline" style="flex:1;">✏️ Edit</a>
        <form method="POST" style="flex:1;">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="action" value="toggle"/>
          <input type="hidden" name="plan_id" value="<?= $p['id'] ?>"/>
          <button class="btn btn-sm btn-outline w-full"><?= $p['is_active']?'⏸ Disable':'▶ Enable' ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="card" style="border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;cursor:pointer;min-height:200px;" onclick="openModal('planModal')">
    <div style="font-size:36px;color:var(--text-muted);">+</div>
    <div style="color:var(--text-muted);font-size:13px;">Add Client Plan</div>
  </div>
</div>

<!-- Plan Modal (Create / Edit) -->
<div class="modal-overlay" id="planModal" style="display:none;">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <span style="font-weight:700;"><?= $editPlan ? '✏️ Edit Plan' : '➕ New Plan' ?></span>
      <button class="modal-close" onclick="closeModal('planModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editPlan ? 'edit' : 'create' ?>"/>
      <?php if ($editPlan): ?><input type="hidden" name="plan_id" value="<?= $editPlan['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Plan Name *</label>
            <input class="form-control" name="plan_name" required value="<?= htmlspecialchars($editPlan['plan_name'] ?? '') ?>" placeholder="e.g. Starter"/>
          </div>
          <div class="form-group">
            <label class="form-label">Plan Type *</label>
            <select class="form-control" name="plan_type">
              <option value="client" <?= ($editPlan['plan_type']??'client')==='client'?'selected':'' ?>>Client</option>
              <option value="reseller" <?= ($editPlan['plan_type']??'')==='reseller'?'selected':'' ?>>Reseller</option>
            </select>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Price (KSh) *</label>
            <input class="form-control" type="number" name="price" step="0.01" required value="<?= $editPlan['price'] ?? '' ?>" placeholder="0.00"/>
          </div>
          <div class="form-group">
            <label class="form-label">Billing Cycle</label>
            <select class="form-control" name="billing_cycle">
              <option value="monthly" <?= ($editPlan['billing_cycle']??'monthly')==='monthly'?'selected':'' ?>>Monthly</option>
              <option value="yearly"  <?= ($editPlan['billing_cycle']??'')==='yearly'?'selected':'' ?>>Yearly</option>
            </select>
          </div>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">Leave limits blank for unlimited.</div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Max Branches</label>
            <input class="form-control" type="number" name="max_branches" value="<?= $editPlan['max_branches'] ?? '' ?>" placeholder="Unlimited"/>
          </div>
          <div class="form-group">
            <label class="form-label">Max Terminals</label>
            <input class="form-control" type="number" name="max_terminals" value="<?= $editPlan['max_terminals'] ?? '' ?>" placeholder="Unlimited"/>
          </div>
          <div class="form-group">
            <label class="form-label">Max Users</label>
            <input class="form-control" type="number" name="max_users" value="<?= $editPlan['max_users'] ?? '' ?>" placeholder="Unlimited"/>
          </div>
          <div class="form-group">
            <label class="form-label">Max Products</label>
            <input class="form-control" type="number" name="max_products" value="<?= $editPlan['max_products'] ?? '' ?>" placeholder="Unlimited"/>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="plans.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editPlan ? 'Update Plan' : 'Create Plan' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }
<?php if ($editPlan || $err): ?>openModal('planModal');<?php endif; ?>
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
