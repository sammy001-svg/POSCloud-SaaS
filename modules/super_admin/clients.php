<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);

$db = getDB();
$pageTitle = 'Clients';

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $business = trim($_POST['business_name'] ?? '');
        $btype    = $_POST['business_type'] ?? 'retail';
        $contact  = trim($_POST['contact_name'] ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $plan_id  = (int)($_POST['plan_id'] ?? 0) ?: null;
        $password = $_POST['password'] ?? '';

        $exists = $db->prepare("SELECT id FROM tenants WHERE email=?");
        $exists->execute([$email]);
        if ($exists->fetch()) { $err = 'A client with that email already exists.'; }
        elseif (strlen($password) < 8) { $err = 'Password must be at least 8 characters.'; }
        else {
            $uuid       = generateUUID();
            $trial_end  = date('Y-m-d', strtotime('+14 days'));
            $db->prepare("INSERT INTO tenants (uuid,business_name,business_type,contact_name,email,phone,plan_id,trial_ends_at) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uuid,$business,$btype,$contact,$email,$phone,$plan_id,$trial_end]);
            $tenantId = $db->lastInsertId();
            // Create default owner user
            $db->prepare("INSERT INTO tenant_users (tenant_id,name,email,password,role) VALUES (?,?,?,?,'owner')")
               ->execute([$tenantId, $contact, $email, hashPassword($password)]);
            // Create main branch
            $db->prepare("INSERT INTO tenant_branches (tenant_id,branch_name,is_main) VALUES (?,'Main Branch',1)")
               ->execute([$tenantId]);
            $msg = "Client '{$business}' created with owner account.";
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['tenant_id'] ?? 0);
        $db->prepare("UPDATE tenants SET is_active=NOT is_active WHERE id=?")->execute([$id]);
        $msg = 'Client status updated.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['tenant_id'] ?? 0);
        $db->prepare("UPDATE tenants SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        $msg = 'Client removed.';
    }
}

// Filters
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$btype  = $_GET['btype'] ?? '';
$page   = max(1,(int)($_GET['page']??1));
$limit  = ITEMS_PER_PAGE; $offset = ($page-1)*$limit;

$where = ["t.deleted_at IS NULL"]; $params = [];
if ($search) { $where[]="(t.business_name LIKE ? OR t.email LIKE ?)"; $params=array_merge($params,["%$search%","%$search%"]); }
if ($status) { $where[]="t.subscription_status=?"; $params[]=$status; }
if ($btype)  { $where[]="t.business_type=?"; $params[]=$btype; }
$w = 'WHERE '.implode(' AND ',$where);

$total = $db->prepare("SELECT COUNT(*) FROM tenants t $w"); $total->execute($params);
$totalRows = $total->fetchColumn(); $pages = ceil($totalRows/$limit);

$stmt = $db->prepare("
    SELECT t.*, sp.plan_name, r.company_name AS reseller_name,
    (SELECT COUNT(*) FROM tenant_branches b WHERE b.tenant_id=t.id) AS branch_count,
    (SELECT COUNT(*) FROM tenant_users u WHERE u.tenant_id=t.id) AS user_count
    FROM tenants t
    LEFT JOIN subscription_plans sp ON sp.id=t.plan_id
    LEFT JOIN resellers r ON r.id=t.reseller_id
    $w ORDER BY t.created_at DESC LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$clients = $stmt->fetchAll();

$plans = $db->query("SELECT * FROM subscription_plans WHERE plan_type='client' AND is_active=1")->fetchAll();
$btypes = ['supermarket','pharmacy','wine_shop','retail','restaurant','wholesale','other'];

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Clients</div>
    <div class="page-subtitle"><?= number_format($totalRows) ?> business client(s) on the platform</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('createModal')">+ New Client</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Filters -->
<div class="card" style="padding:16px;margin-bottom:20px;">
  <form method="GET" class="flex gap-2" style="flex-wrap:wrap;align-items:flex-end;">
    <div style="flex:1;min-width:200px;">
      <input class="form-control" name="q" placeholder="🔍 Search business, email..." value="<?= htmlspecialchars($search) ?>"/>
    </div>
    <select class="form-control" name="status" style="width:150px;">
      <option value="">All Statuses</option>
      <?php foreach(['active','trial','suspended','expired'] as $s): ?>
        <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-control" name="btype" style="width:150px;">
      <option value="">All Types</option>
      <?php foreach($btypes as $bt): ?>
        <option value="<?=$bt?>" <?= $btype===$bt?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$bt)) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Filter</button>
    <a href="clients.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden;">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Business</th><th>Type</th><th>Plan</th><th>Reseller</th><th>Branches</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php
        $typeIcons=['supermarket'=>'🛒','pharmacy'=>'💊','wine_shop'=>'🍷','retail'=>'🏪','restaurant'=>'🍽️','wholesale'=>'📦','other'=>'🏢'];
        $sc=['active'=>'badge-success','trial'=>'badge-info','suspended'=>'badge-warning','expired'=>'badge-danger'];
        if (empty($clients)): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No clients found. <a href="#" onclick="openModal('createModal')">Add the first one</a>.</td></tr>
        <?php else: foreach ($clients as $c): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:12px;">
                <div style="font-size:24px;"><?= $typeIcons[$c['business_type']] ?? '🏢' ?></div>
                <div>
                  <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($c['business_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div>
                </div>
              </div>
            </td>
            <td style="text-transform:capitalize;"><?= str_replace('_',' ',$c['business_type']) ?></td>
            <td><?= htmlspecialchars($c['plan_name'] ?? '—') ?></td>
            <td><?= $c['reseller_name'] ? '<span class="badge badge-info">'.htmlspecialchars($c['reseller_name']).'</span>' : '<span class="badge badge-muted">Direct</span>' ?></td>
            <td>
              <span class="badge badge-muted"><?= $c['branch_count'] ?> br · <?= $c['user_count'] ?> usr</span>
            </td>
            <td><span class="badge <?= $sc[$c['subscription_status']]??'badge-muted' ?>"><?= ucfirst($c['subscription_status']) ?></span></td>
            <td>
              <div class="flex gap-1">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="toggle"/>
                  <input type="hidden" name="tenant_id" value="<?= $c['id'] ?>"/>
                  <button class="btn btn-sm btn-outline" title="<?= $c['is_active']?'Suspend':'Activate' ?>"><?= $c['is_active']?'⏸':'▶' ?></button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this client and all their data?');">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="tenant_id" value="<?= $c['id'] ?>"/>
                  <button class="btn btn-sm btn-danger" title="Delete">🗑</button>
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
  <div class="modal" style="max-width:600px;">
    <div class="modal-header">
      <span style="font-weight:700;font-size:16px;">➕ New Client</span>
      <button class="modal-close" onclick="closeModal('createModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create"/>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Business Name *</label>
            <input class="form-control" name="business_name" required placeholder="SuperMart Ltd"/>
          </div>
          <div class="form-group">
            <label class="form-label">Business Type *</label>
            <select class="form-control" name="business_type">
              <?php foreach($btypes as $bt): ?>
                <option value="<?=$bt?>"><?= $typeIcons[$bt]??'' ?> <?= ucfirst(str_replace('_',' ',$bt)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Owner Name *</label>
            <input class="form-control" name="contact_name" required placeholder="Jane Doe"/>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input class="form-control" type="email" name="email" required placeholder="owner@business.com"/>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone" placeholder="+254 7xx xxx xxx"/>
          </div>
          <div class="form-group">
            <label class="form-label">Subscription Plan</label>
            <select class="form-control" name="plan_id">
              <option value="">— Trial (no plan) —</option>
              <?php foreach($plans as $p): ?>
                <option value="<?=$p['id']?>"><?= htmlspecialchars($p['plan_name']) ?> — KSh <?= number_format($p['price'],0) ?>/mo</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Initial Password *</label>
          <input class="form-control" type="password" name="password" required placeholder="Min. 8 characters"/>
        </div>
        <div class="alert alert-info" style="margin:0;">ℹ️ A main branch and owner account will be created automatically.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Client</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }
<?php if ($err): ?>openModal('createModal');<?php endif; ?>
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
