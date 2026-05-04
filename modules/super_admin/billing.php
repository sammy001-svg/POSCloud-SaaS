<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);

$db = getDB();
$pageTitle = 'Billing';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_invoice') {
        $to_type  = $_POST['to_type'] === 'reseller' ? 'reseller' : 'client';
        $to_id    = (int)($_POST['to_id'] ?? 0);
        $amount   = (float)($_POST['amount'] ?? 0);
        $due      = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $notes    = trim($_POST['notes'] ?? '');
        $inv_num  = 'INV-' . strtoupper(substr(md5(uniqid()), 0, 8));

        $inv_type = "platform_to_{$to_type}";
        $db->prepare("INSERT INTO invoices (invoice_number,invoice_type,from_entity_type,to_entity_type,to_entity_id,amount,total_amount,due_date,notes) VALUES (?,?,'platform',?,?,?,?,?,?)")
           ->execute([$inv_num, $inv_type, $to_type, $to_id, $amount, $amount, $due, $notes]);
        $msg = "Invoice {$inv_num} created.";
    }

    if ($action === 'record_payment') {
        $inv_id  = (int)($_POST['invoice_id'] ?? 0);
        $amount  = (float)($_POST['amount'] ?? 0);
        $method  = $_POST['payment_method'] ?? 'cash';
        $ref     = trim($_POST['reference'] ?? '');
        $db->prepare("INSERT INTO payments (invoice_id,amount,payment_method,reference) VALUES (?,?,?,?)")
           ->execute([$inv_id, $amount, $method, $ref]);
        $db->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$inv_id]);
        $msg = 'Payment recorded.';
    }

    if ($action === 'mark_overdue') {
        $db->exec("UPDATE invoices SET status='overdue' WHERE status='sent' AND due_date < CURDATE()");
        $msg = 'Overdue invoices updated.';
    }
}

// Filters
$status  = $_GET['status'] ?? '';
$itype   = $_GET['itype'] ?? '';
$page    = max(1,(int)($_GET['page']??1));
$limit   = ITEMS_PER_PAGE; $offset = ($page-1)*$limit;

$where = []; $params = [];
if ($status) { $where[]="status=?"; $params[]=$status; }
if ($itype)  { $where[]="invoice_type=?"; $params[]=$itype; }
$w = $where ? 'WHERE '.implode(' AND ',$where) : '';

$total = $db->prepare("SELECT COUNT(*) FROM invoices $w"); $total->execute($params);
$totalRows = $total->fetchColumn(); $pages = ceil($totalRows/$limit);

$invoices = $db->prepare("SELECT * FROM invoices $w ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$invoices->execute($params);
$invoices = $invoices->fetchAll();

// Summary stats
$stats = $db->query("
    SELECT
      SUM(CASE WHEN status='paid' THEN total_amount ELSE 0 END) AS total_collected,
      SUM(CASE WHEN status='sent' THEN total_amount ELSE 0 END) AS total_pending,
      SUM(CASE WHEN status='overdue' THEN total_amount ELSE 0 END) AS total_overdue,
      COUNT(*) AS total_invoices
    FROM invoices
")->fetch();

$resellers = $db->query("SELECT id, company_name FROM resellers WHERE deleted_at IS NULL AND is_active=1 ORDER BY company_name")->fetchAll();
$clients   = $db->query("SELECT id, business_name FROM tenants WHERE deleted_at IS NULL AND is_active=1 ORDER BY business_name")->fetchAll();

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Billing & Invoices</div>
    <div class="page-subtitle">Platform billing — manage invoices to resellers and direct clients</div>
  </div>
  <div class="flex gap-2">
    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="mark_overdue"/>
      <button class="btn btn-outline">🔄 Mark Overdue</button>
    </form>
    <button class="btn btn-primary" onclick="openModal('invoiceModal')">+ New Invoice</button>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Stats -->
<div class="grid-4" style="margin-bottom:24px;">
  <div class="stat-card">
    <div class="stat-icon green">✅</div>
    <div><div class="stat-value">KSh <?= number_format($stats['total_collected'],0) ?></div><div class="stat-label">Total Collected</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">⏳</div>
    <div><div class="stat-value">KSh <?= number_format($stats['total_pending'],0) ?></div><div class="stat-label">Pending</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">⚠️</div>
    <div><div class="stat-value">KSh <?= number_format($stats['total_overdue'],0) ?></div><div class="stat-label">Overdue</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">🧾</div>
    <div><div class="stat-value"><?= number_format($stats['total_invoices']) ?></div><div class="stat-label">Total Invoices</div></div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="padding:16px;margin-bottom:20px;">
  <form method="GET" class="flex gap-2" style="flex-wrap:wrap;">
    <select class="form-control" name="status" style="width:150px;">
      <option value="">All Statuses</option>
      <?php foreach(['sent','paid','overdue','draft','cancelled'] as $s): ?>
        <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-control" name="itype" style="width:220px;">
      <option value="">All Types</option>
      <option value="platform_to_reseller" <?= $itype==='platform_to_reseller'?'selected':'' ?>>Platform → Reseller</option>
      <option value="platform_to_client"   <?= $itype==='platform_to_client'?'selected':'' ?>>Platform → Client</option>
    </select>
    <button class="btn btn-primary" type="submit">Filter</button>
    <a href="billing.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<!-- Invoices Table -->
<div class="card" style="padding:0;overflow:hidden;">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Invoice #</th><th>Type</th><th>Amount</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php
        $ic=['paid'=>'badge-success','sent'=>'badge-info','overdue'=>'badge-danger','draft'=>'badge-muted','cancelled'=>'badge-muted'];
        if (empty($invoices)): ?>
          <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No invoices yet.</td></tr>
        <?php else: foreach ($invoices as $inv): ?>
          <tr>
            <td>
              <div style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($inv['invoice_number']) ?></div>
              <?php if ($inv['notes']): ?><div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars(substr($inv['notes'],0,40)) ?></div><?php endif; ?>
            </td>
            <td>
              <?php
              $labels = ['platform_to_reseller'=>'→ Reseller','platform_to_client'=>'→ Client','reseller_to_client'=>'Reseller → Client'];
              echo '<span class="badge badge-muted">'.($labels[$inv['invoice_type']]??$inv['invoice_type']).'</span>';
              ?>
            </td>
            <td style="font-weight:700;">KSh <?= number_format($inv['total_amount'],2) ?></td>
            <td style="font-size:12px;"><?= date('d M Y',strtotime($inv['due_date'])) ?></td>
            <td><span class="badge <?= $ic[$inv['status']]??'badge-muted' ?>"><?= ucfirst($inv['status']) ?></span></td>
            <td>
              <?php if ($inv['status'] !== 'paid'): ?>
                <button class="btn btn-sm btn-outline"
                  onclick="openPayModal(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>', <?= $inv['total_amount'] ?>)">
                  💳 Record Payment
                </button>
              <?php else: ?>
                <span style="font-size:11px;color:var(--success);">✓ Paid <?= $inv['paid_at'] ? date('d M',strtotime($inv['paid_at'])) : '' ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div style="padding:16px;border-top:1px solid var(--border);">
    <div class="pagination">
      <?php for ($i=1;$i<=$pages;$i++): ?>
        <a href="?status=<?=$status?>&itype=<?=$itype?>&page=<?=$i?>" class="page-btn <?=$page===$i?'active':''?>"><?=$i?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Create Invoice Modal -->
<div class="modal-overlay" id="invoiceModal" style="display:none;">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <span style="font-weight:700;">🧾 Create Invoice</span>
      <button class="modal-close" onclick="closeModal('invoiceModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create_invoice"/>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Invoice For *</label>
          <select class="form-control" name="to_type" id="toType" onchange="toggleRecipient()">
            <option value="reseller">Reseller</option>
            <option value="client">Direct Client</option>
          </select>
        </div>
        <div class="form-group" id="resellerField">
          <label class="form-label">Select Reseller *</label>
          <select class="form-control" name="to_id" id="resellerSel">
            <option value="">— Select —</option>
            <?php foreach($resellers as $r): ?>
              <option value="<?=$r['id']?>"><?= htmlspecialchars($r['company_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="clientField" style="display:none;">
          <label class="form-label">Select Client *</label>
          <select class="form-control" name="to_id" id="clientSel" disabled>
            <option value="">— Select —</option>
            <?php foreach($clients as $c): ?>
              <option value="<?=$c['id']?>"><?= htmlspecialchars($c['business_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Amount (KSh) *</label>
            <input class="form-control" type="number" name="amount" step="0.01" required placeholder="0.00"/>
          </div>
          <div class="form-group">
            <label class="form-label">Due Date *</label>
            <input class="form-control" type="date" name="due_date" required value="<?= date('Y-m-d',strtotime('+30 days')) ?>"/>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="2" placeholder="Subscription for May 2026..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('invoiceModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Invoice</button>
      </div>
    </form>
  </div>
</div>

<!-- Record Payment Modal -->
<div class="modal-overlay" id="payModal" style="display:none;">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <span style="font-weight:700;" id="payModalTitle">💳 Record Payment</span>
      <button class="modal-close" onclick="closeModal('payModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="record_payment"/>
      <input type="hidden" name="invoice_id" id="payInvoiceId"/>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Amount (KSh) *</label>
          <input class="form-control" type="number" name="amount" step="0.01" id="payAmount" required/>
        </div>
        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select class="form-control" name="payment_method">
            <option value="mpesa">M-Pesa</option>
            <option value="bank">Bank Transfer</option>
            <option value="cash">Cash</option>
            <option value="card">Card</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Reference / Transaction Code</label>
          <input class="form-control" name="reference" placeholder="e.g. QJK8XXXX"/>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('payModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Record Payment</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }

function openPayModal(invoiceId, invNum, amount) {
  document.getElementById('payInvoiceId').value = invoiceId;
  document.getElementById('payAmount').value = amount;
  document.getElementById('payModalTitle').textContent = '💳 Record Payment — ' + invNum;
  openModal('payModal');
}

function toggleRecipient() {
  const type = document.getElementById('toType').value;
  const rf = document.getElementById('resellerField');
  const cf = document.getElementById('clientField');
  const rs = document.getElementById('resellerSel');
  const cs = document.getElementById('clientSel');
  if (type === 'reseller') {
    rf.style.display=''; cf.style.display='none'; rs.disabled=false; cs.disabled=true;
  } else {
    rf.style.display='none'; cf.style.display=''; rs.disabled=true; cs.disabled=false;
  }
}
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
