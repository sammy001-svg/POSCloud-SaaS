<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_RESELLER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$rid = currentUser()['reseller_id'];
$msg = $err = '';
$currentPage = 'clients';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $business = trim($_POST['business_name'] ?? '');
        $btype    = $_POST['business_type'] ?? 'retail';
        $contact  = trim($_POST['contact_name'] ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $plan_id  = (int)($_POST['reseller_plan_id'] ?? 0) ?: null;
        $password = $_POST['password'] ?? '';

        $exists = $db->prepare("SELECT id FROM tenants WHERE email=?"); $exists->execute([$email]);
        if ($exists->fetch()) { $err = 'A client with that email already exists.'; }
        elseif (strlen($password) < 8) { $err = 'Password must be at least 8 characters.'; }
        else {
            $uuid      = generateUUID();
            $trial_end = date('Y-m-d', strtotime('+14 days'));
            $db->prepare("INSERT INTO tenants (uuid,reseller_id,business_name,business_type,contact_name,email,phone,reseller_plan_id,trial_ends_at) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$uuid,$rid,$business,$btype,$contact,$email,$phone,$plan_id,$trial_end]);
            $tid = $db->lastInsertId();
            $db->prepare("INSERT INTO tenant_users (tenant_id,name,email,password,role) VALUES (?,?,?,?,'owner')")
               ->execute([$tid,$contact,$email,hashPassword($password)]);
            $db->prepare("INSERT INTO tenant_branches (tenant_id,branch_name,is_main) VALUES (?,'Main Branch',1)")->execute([$tid]);
            $msg = "Client '{$business}' created successfully.";
        }
    }

    if ($action === 'toggle') {
        $id = (int)$_POST['tenant_id'];
        $db->prepare("UPDATE tenants SET is_active=NOT is_active WHERE id=? AND reseller_id=?")->execute([$id,$rid]);
        $msg = 'Status updated.';
    }

    if ($action === 'delete') {
        $id = (int)$_POST['tenant_id'];
        $db->prepare("UPDATE tenants SET deleted_at=NOW() WHERE id=? AND reseller_id=?")->execute([$id,$rid]);
        $msg = 'Client removed.';
    }
}

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page   = max(1,(int)($_GET['page']??1));
$limit  = ITEMS_PER_PAGE; $offset = ($page-1)*$limit;

$where = ["t.reseller_id=?","t.deleted_at IS NULL"]; $params = [$rid];
if ($search) { $where[]="(t.business_name LIKE ? OR t.email LIKE ?)"; $params=array_merge($params,["%$search%","%$search%"]); }
if ($status) { $where[]="t.subscription_status=?"; $params[]=$status; }
$w = 'WHERE '.implode(' AND ',$where);

$total = $db->prepare("SELECT COUNT(*) FROM tenants t $w"); $total->execute($params);
$totalRows = $total->fetchColumn(); $pages = ceil($totalRows/$limit);

$stmt = $db->prepare("
    SELECT t.*, rp.plan_name AS rplan_name,
    (SELECT COUNT(*) FROM tenant_branches b WHERE b.tenant_id=t.id) AS branch_count
    FROM tenants t LEFT JOIN reseller_plans rp ON rp.id=t.reseller_plan_id
    $w ORDER BY t.created_at DESC LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$clients = $stmt->fetchAll();

$myPlans = $db->prepare("SELECT * FROM reseller_plans WHERE reseller_id=? AND is_active=1"); $myPlans->execute([$rid]); $myPlans=$myPlans->fetchAll();
$btypes  = ['supermarket','pharmacy','wine_shop','retail','restaurant','wholesale','other'];
$typeIcons=['supermarket'=>'🛒','pharmacy'=>'💊','wine_shop'=>'🍷','retail'=>'🏪','restaurant'=>'🍽️','wholesale'=>'📦','other'=>'🏢'];

resellerLayout('My Clients', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">My Clients</div><div class="page-subtitle"><?= number_format($totalRows) ?> business client(s)</div></div>
  <button class="btn btn-primary" onclick="openModal('createModal')">+ Onboard Client</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="padding:16px;margin-bottom:20px;">
  <form method="GET" class="flex gap-2" style="flex-wrap:wrap;">
    <input class="form-control" name="q" placeholder="🔍 Search..." value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:180px;"/>
    <select class="form-control" name="status" style="width:150px;">
      <option value="">All Statuses</option>
      <?php foreach(['active','trial','suspended','expired'] as $s): ?>
        <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a href="clients.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<div class="card" style="padding:0;overflow:hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Business</th><th>Type</th><th>Plan</th><th>Branches</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
      <tbody>
        <?php $sc=['active'=>'badge-success','trial'=>'badge-info','suspended'=>'badge-warning','expired'=>'badge-danger'];
        if (empty($clients)): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No clients. <a href="#" onclick="openModal('createModal')">Add your first client</a>.</td></tr>
        <?php else: foreach ($clients as $c): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;"><?= $typeIcons[$c['business_type']]??'🏢' ?></span>
                <div>
                  <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($c['business_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div>
                </div>
              </div>
            </td>
            <td style="text-transform:capitalize;font-size:12px;"><?= str_replace('_',' ',$c['business_type']) ?></td>
            <td><?= htmlspecialchars($c['rplan_name'] ?? '—') ?></td>
            <td><span class="badge badge-info"><?= $c['branch_count'] ?></span></td>
            <td><span class="badge <?= $sc[$c['subscription_status']]??'badge-muted' ?>"><?= ucfirst($c['subscription_status']) ?></span></td>
            <td style="font-size:12px;color:var(--text-muted);"><?= date('d M Y',strtotime($c['created_at'])) ?></td>
            <td>
              <div class="flex gap-1">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="toggle"/>
                  <input type="hidden" name="tenant_id" value="<?= $c['id'] ?>"/>
                  <button class="btn btn-sm btn-outline"><?= $c['is_active']?'⏸':'▶' ?></button>
                </form>
                <button class="btn btn-sm btn-outline" onclick="openBillModal(<?= $c['id'] ?>,'<?= htmlspecialchars($c['business_name'],ENT_QUOTES) ?>')">🧾</button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this client?');">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="tenant_id" value="<?= $c['id'] ?>"/>
                  <button class="btn btn-sm btn-danger">🗑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create Client Modal -->
<div class="modal-overlay" id="createModal" style="display:none;">
  <div class="modal" style="max-width:580px;">
    <div class="modal-header"><span style="font-weight:700;">➕ Onboard Client</span><button class="modal-close" onclick="closeModal('createModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create"/>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Business Name *</label><input class="form-control" name="business_name" required placeholder="My Shop Ltd"/></div>
          <div class="form-group"><label class="form-label">Business Type *</label>
            <select class="form-control" name="business_type">
              <?php foreach($btypes as $bt): ?><option value="<?=$bt?>"><?=$typeIcons[$bt]??''?> <?=ucfirst(str_replace('_',' ',$bt))?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Owner Name *</label><input class="form-control" name="contact_name" required placeholder="Jane Doe"/></div>
          <div class="form-group"><label class="form-label">Email *</label><input class="form-control" type="email" name="email" required placeholder="owner@shop.com"/></div>
          <div class="form-group"><label class="form-label">Phone</label><input class="form-control" name="phone" placeholder="+254 7xx xxx xxx"/></div>
          <div class="form-group"><label class="form-label">Subscription Plan</label>
            <select class="form-control" name="reseller_plan_id">
              <option value="">— Trial —</option>
              <?php foreach($myPlans as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['plan_name'])?> — KSh <?=number_format($p['price'],0)?>/mo</option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Initial Password *</label><input class="form-control" type="password" name="password" required placeholder="Min. 8 characters"/></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button><button type="submit" class="btn btn-primary">Create Client</button></div>
    </form>
  </div>
</div>

<!-- Quick Invoice Modal -->
<div class="modal-overlay" id="billModal" style="display:none;">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><span style="font-weight:700;" id="billModalTitle">🧾 Invoice Client</span><button class="modal-close" onclick="closeModal('billModal')">✕</button></div>
    <form method="POST" action="billing.php">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create_invoice"/>
      <input type="hidden" name="to_id" id="billClientId"/>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Amount (KSh) *</label><input class="form-control" type="number" name="amount" step="0.01" required placeholder="0.00"/></div>
          <div class="form-group"><label class="form-label">Due Date *</label><input class="form-control" type="date" name="due_date" value="<?= date('Y-m-d',strtotime('+30 days')) ?>"/></div>
        </div>
        <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="May 2026 subscription..."></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('billModal')">Cancel</button><button type="submit" class="btn btn-primary">Send Invoice</button></div>
    </form>
  </div>
</div>

<script>
function openBillModal(id, name) {
  document.getElementById('billClientId').value = id;
  document.getElementById('billModalTitle').textContent = '🧾 Invoice — ' + name;
  openModal('billModal');
}
<?php if($err): ?>openModal('createModal');<?php endif; ?>
</script>

<?php resellerLayoutEnd(); ?>
