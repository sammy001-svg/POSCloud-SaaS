<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);

$db = getDB();
$pageTitle = 'Resellers';

// ── Handle Actions ─────────────────────────────────────────
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $company  = trim($_POST['company_name'] ?? '');
        $contact  = trim($_POST['contact_name'] ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $plan_id  = (int)($_POST['plan_id'] ?? 0) ?: null;
        $password = $_POST['password'] ?? '';
        $slug     = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $company));

        // Check duplicate
        $exists = $db->prepare("SELECT id FROM resellers WHERE email=? OR slug=?");
        $exists->execute([$email, $slug]);
        if ($exists->fetch()) {
            $err = 'A reseller with that email or company name already exists.';
        } elseif (strlen($password) < 8) {
            $err = 'Password must be at least 8 characters.';
        } else {
            $uuid = generateUUID();
            $hash = hashPassword($password);
            $trial_end = date('Y-m-d', strtotime('+14 days'));
            $db->prepare("INSERT INTO resellers (uuid,company_name,contact_name,email,phone,password,slug,plan_id,trial_ends_at) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$uuid, $company, $contact, $email, $phone, $hash, $slug, $plan_id, $trial_end]);
            $msg = "Reseller '{$company}' created successfully.";
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['reseller_id'] ?? 0);
        $db->prepare("UPDATE resellers SET is_active = NOT is_active WHERE id=?")->execute([$id]);
        $msg = 'Reseller status updated.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['reseller_id'] ?? 0);
        $db->prepare("UPDATE resellers SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        $msg = 'Reseller removed.';
    }
}

// ── Filters ────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$where = ["r.deleted_at IS NULL"];
$params = [];
if ($search) { $where[] = "(r.company_name LIKE ? OR r.email LIKE ? OR r.contact_name LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($status) { $where[] = "r.subscription_status=?"; $params[] = $status; }
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM resellers r $whereSQL");
$total->execute($params);
$totalRows = $total->fetchColumn();
$pages = ceil($totalRows / $limit);

$stmt = $db->prepare("
    SELECT r.*, sp.plan_name,
    (SELECT COUNT(*) FROM tenants t WHERE t.reseller_id=r.id AND t.deleted_at IS NULL) AS client_count,
    (SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.to_entity_type='reseller' AND i.to_entity_id=r.id) AS total_paid
    FROM resellers r
    LEFT JOIN subscription_plans sp ON sp.id=r.plan_id
    $whereSQL ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$resellers = $stmt->fetchAll();

$plans = $db->query("SELECT * FROM subscription_plans WHERE plan_type='reseller' AND is_active=1")->fetchAll();

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Resellers</div>
    <div class="page-subtitle"><?= number_format($totalRows) ?> reseller(s) on the platform</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('createModal')">+ New Reseller</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Filters -->
<div class="card" style="padding:16px;margin-bottom:20px;">
  <form method="GET" class="flex gap-2" style="flex-wrap:wrap;align-items:flex-end;">
    <div style="flex:1;min-width:200px;">
      <input class="form-control" name="q" placeholder="🔍 Search company, email..." value="<?= htmlspecialchars($search) ?>"/>
    </div>
    <select class="form-control" name="status" style="width:160px;">
      <option value="">All Statuses</option>
      <?php foreach(['active','trial','suspended','expired'] as $s): ?>
        <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Filter</button>
    <a href="resellers.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden;">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Company</th><th>Plan</th><th>Clients</th>
          <th>Revenue</th><th>Status</th><th>Joined</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($resellers)): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
            No resellers found. <a href="#" onclick="openModal('createModal')">Add the first one</a>.
          </td></tr>
        <?php else: foreach ($resellers as $r):
          $sc = ['active'=>'badge-success','trial'=>'badge-info','suspended'=>'badge-warning','expired'=>'badge-danger'];
        ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:38px;height:38px;border-radius:10px;background:var(--primary-glow);border:1px solid rgba(30, 58, 138,.3);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--primary);font-size:15px;flex-shrink:0;">
                  <?= strtoupper(substr($r['company_name'],0,1)) ?>
                </div>
                <div>
                  <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($r['company_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($r['email']) ?></div>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($r['plan_name'] ?? '—') ?></td>
            <td><span class="badge badge-info"><?= $r['client_count'] ?> clients</span></td>
            <td style="font-weight:600;">KSh <?= number_format($r['total_paid'], 0) ?></td>
            <td><span class="badge <?= $sc[$r['subscription_status']] ?? 'badge-muted' ?>"><?= ucfirst($r['subscription_status']) ?></span></td>
            <td style="font-size:12px;color:var(--text-muted);"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
            <td>
              <div class="flex gap-1">
                <a href="reseller_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline" title="View">👁</a>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="toggle"/>
                  <input type="hidden" name="reseller_id" value="<?= $r['id'] ?>"/>
                  <button class="btn btn-sm btn-outline" title="<?= $r['is_active']?'Suspend':'Activate' ?>">
                    <?= $r['is_active'] ? '⏸' : '▶' ?>
                  </button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this reseller? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="reseller_id" value="<?= $r['id'] ?>"/>
                  <button class="btn btn-sm btn-danger" title="Delete">🗑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div style="padding:16px;border-top:1px solid var(--border);">
    <div class="pagination">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?q=<?= urlencode($search) ?>&status=<?= $status ?>&page=<?= $i ?>" class="page-btn <?= $page===$i?'active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Create Reseller Modal -->
<div class="modal-overlay" id="createModal" style="display:none;">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <span style="font-weight:700;font-size:16px;">➕ New Reseller</span>
      <button class="modal-close" onclick="closeModal('createModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create"/>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Company Name *</label>
            <input class="form-control" name="company_name" required placeholder="Acme Solutions"/>
          </div>
          <div class="form-group">
            <label class="form-label">Contact Person *</label>
            <input class="form-control" name="contact_name" required placeholder="John Doe"/>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Email Address *</label>
            <input class="form-control" type="email" name="email" required placeholder="john@acme.com"/>
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone" placeholder="+254 7xx xxx xxx"/>
          </div>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Subscription Plan</label>
            <select class="form-control" name="plan_id">
              <option value="">— Trial (no plan) —</option>
              <?php foreach ($plans as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['plan_name']) ?> — KSh <?= number_format($p['price'],0) ?>/mo</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Initial Password *</label>
            <input class="form-control" type="password" name="password" required placeholder="Min. 8 characters"/>
          </div>
        </div>
        <div class="alert alert-info" style="margin:0;">
          ℹ️ The reseller will get a 14-day trial period and can log in immediately.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Reseller</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target===m) m.style.display='none'; });
});
<?php if ($err): ?>openModal('createModal');<?php endif; ?>
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
