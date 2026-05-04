<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_RESELLER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$rid = currentUser()['reseller_id'];
$msg = $err = '';
$currentPage = 'billing';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_invoice') {
        $to_id   = (int)$_POST['to_id'];
        $amount  = (float)$_POST['amount'];
        $due     = $_POST['due_date'];
        $notes   = trim($_POST['notes'] ?? '');
        // Verify client belongs to this reseller
        $check = $db->prepare("SELECT id FROM tenants WHERE id=? AND reseller_id=?"); $check->execute([$to_id,$rid]);
        if (!$check->fetch()) { $err = 'Invalid client.'; }
        else {
            $inv_num = 'RINV-' . strtoupper(substr(md5(uniqid()),0,8));
            $db->prepare("INSERT INTO invoices (invoice_number,invoice_type,from_entity_type,from_entity_id,to_entity_type,to_entity_id,amount,total_amount,due_date,notes) VALUES (?,'reseller_to_client','reseller',?,?,?,?,?,?,?)")
               ->execute([$inv_num,$rid,'client',$to_id,$amount,$amount,$due,$notes]);
            $msg = "Invoice {$inv_num} sent to client.";
        }
    }

    if ($action === 'record_payment') {
        $inv_id = (int)$_POST['invoice_id'];
        $amount = (float)$_POST['amount'];
        $method = $_POST['payment_method'];
        $ref    = trim($_POST['reference'] ?? '');
        $db->prepare("INSERT INTO payments (invoice_id,amount,payment_method,reference) VALUES (?,?,?,?)")->execute([$inv_id,$amount,$method,$ref]);
        $db->prepare("UPDATE invoices SET status='paid',paid_at=NOW() WHERE id=?")->execute([$inv_id]);
        $msg = 'Payment recorded.';
    }

    if ($action === 'mark_overdue') {
        $db->prepare("UPDATE invoices SET status='overdue' WHERE status='sent' AND due_date < CURDATE() AND from_entity_type='reseller' AND from_entity_id=?")->execute([$rid]);
        $msg = 'Overdue invoices updated.';
    }
}

// Stats
$stats = $db->prepare("SELECT SUM(CASE WHEN status='paid' THEN total_amount ELSE 0 END) AS collected, SUM(CASE WHEN status='sent' THEN total_amount ELSE 0 END) AS pending, SUM(CASE WHEN status='overdue' THEN total_amount ELSE 0 END) AS overdue, COUNT(*) AS total FROM invoices WHERE from_entity_type='reseller' AND from_entity_id=?");
$stats->execute([$rid]); $stats = $stats->fetch();

$status = $_GET['status'] ?? '';
$page   = max(1,(int)($_GET['page']??1)); $limit = ITEMS_PER_PAGE; $offset=($page-1)*$limit;

$where = ["from_entity_type='reseller'","from_entity_id=?"]; $params=[$rid];
if ($status) { $where[]="status=?"; $params[]=$status; }
$w = 'WHERE '.implode(' AND ',$where);
$total = $db->prepare("SELECT COUNT(*) FROM invoices $w"); $total->execute($params); $totalRows=$total->fetchColumn(); $pages=ceil($totalRows/$limit);

$invoices = $db->prepare("SELECT i.*, t.business_name FROM invoices i LEFT JOIN tenants t ON t.id=i.to_entity_id $w ORDER BY i.created_at DESC LIMIT $limit OFFSET $offset");
$invoices->execute($params); $invoices=$invoices->fetchAll();

$myClients = $db->prepare("SELECT id,business_name FROM tenants WHERE reseller_id=? AND is_active=1 AND deleted_at IS NULL ORDER BY business_name"); $myClients->execute([$rid]); $myClients=$myClients->fetchAll();

resellerLayout('Billing', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Billing</div><div class="page-subtitle">Invoice and collect payments from your clients</div></div>
  <div class="flex gap-2">
    <form method="POST" style="display:inline;"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/><input type="hidden" name="action" value="mark_overdue"/><button class="btn btn-outline">🔄 Mark Overdue</button></form>
    <button class="btn btn-primary" onclick="openModal('invoiceModal')">+ New Invoice</button>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-4" style="margin-bottom:24px;">
  <div class="stat-card"><div class="stat-icon green">✅</div><div><div class="stat-value">KSh <?= number_format($stats['collected']??0,0) ?></div><div class="stat-label">Collected</div></div></div>
  <div class="stat-card"><div class="stat-icon blue">⏳</div><div><div class="stat-value">KSh <?= number_format($stats['pending']??0,0) ?></div><div class="stat-label">Pending</div></div></div>
  <div class="stat-card"><div class="stat-icon red">⚠️</div><div><div class="stat-value">KSh <?= number_format($stats['overdue']??0,0) ?></div><div class="stat-label">Overdue</div></div></div>
  <div class="stat-card"><div class="stat-icon purple">🧾</div><div><div class="stat-value"><?= number_format($stats['total']??0) ?></div><div class="stat-label">Total Invoices</div></div></div>
</div>

<div class="card" style="padding:16px;margin-bottom:20px;">
  <form method="GET" class="flex gap-2">
    <select class="form-control" name="status" style="width:160px;">
      <option value="">All Statuses</option>
      <?php foreach(['sent','paid','overdue','draft','cancelled'] as $s): ?>
        <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a href="billing.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<div class="card" style="padding:0;overflow:hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Invoice #</th><th>Client</th><th>Amount</th><th>Due</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        <?php $ic=['paid'=>'badge-success','sent'=>'badge-info','overdue'=>'badge-danger','draft'=>'badge-muted','cancelled'=>'badge-muted'];
        if (empty($invoices)): ?>
          <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No invoices yet. <a href="#" onclick="openModal('invoiceModal')">Create one</a>.</td></tr>
        <?php else: foreach ($invoices as $inv): ?>
          <tr>
            <td><div style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($inv['invoice_number']) ?></div>
              <?php if($inv['notes']): ?><div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars(substr($inv['notes'],0,40)) ?></div><?php endif; ?></td>
            <td style="font-weight:500;"><?= htmlspecialchars($inv['business_name'] ?? '—') ?></td>
            <td style="font-weight:700;">KSh <?= number_format($inv['total_amount'],2) ?></td>
            <td style="font-size:12px;"><?= date('d M Y',strtotime($inv['due_date'])) ?></td>
            <td><span class="badge <?= $ic[$inv['status']]??'badge-muted' ?>"><?= ucfirst($inv['status']) ?></span></td>
            <td>
              <?php if ($inv['status']!=='paid'): ?>
                <button class="btn btn-sm btn-outline" onclick="openPayModal(<?=$inv['id']?>,'<?=htmlspecialchars($inv['invoice_number'],ENT_QUOTES)?>',<?=$inv['total_amount']?>)">💳 Pay</button>
              <?php else: ?>
                <span style="font-size:11px;color:var(--success);">✓ <?= $inv['paid_at']?date('d M',strtotime($inv['paid_at'])):'' ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create Invoice Modal -->
<div class="modal-overlay" id="invoiceModal" style="display:none;">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><span style="font-weight:700;">🧾 New Invoice</span><button class="modal-close" onclick="closeModal('invoiceModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create_invoice"/>
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Client *</label>
          <select class="form-control" name="to_id" required>
            <option value="">— Select Client —</option>
            <?php foreach($myClients as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['business_name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Amount (KSh) *</label><input class="form-control" type="number" name="amount" step="0.01" required placeholder="0.00"/></div>
          <div class="form-group"><label class="form-label">Due Date *</label><input class="form-control" type="date" name="due_date" required value="<?= date('Y-m-d',strtotime('+30 days')) ?>"/></div>
        </div>
        <div class="form-group"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="May 2026 subscription..."></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('invoiceModal')">Cancel</button><button type="submit" class="btn btn-primary">Send Invoice</button></div>
    </form>
  </div>
</div>

<!-- Record Payment Modal -->
<div class="modal-overlay" id="payModal" style="display:none;">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header"><span style="font-weight:700;" id="payTitle">💳 Record Payment</span><button class="modal-close" onclick="closeModal('payModal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="record_payment"/>
      <input type="hidden" name="invoice_id" id="payInvId"/>
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Amount (KSh)</label><input class="form-control" type="number" name="amount" step="0.01" id="payAmt" required/></div>
        <div class="form-group"><label class="form-label">Payment Method</label>
          <select class="form-control" name="payment_method">
            <option value="mpesa">M-Pesa</option><option value="bank">Bank Transfer</option><option value="cash">Cash</option><option value="card">Card</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Reference</label><input class="form-control" name="reference" placeholder="Transaction code"/></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('payModal')">Cancel</button><button type="submit" class="btn btn-primary">Record</button></div>
    </form>
  </div>
</div>

<script>
function openPayModal(id,num,amt){
  document.getElementById('payInvId').value=id;
  document.getElementById('payAmt').value=amt;
  document.getElementById('payTitle').textContent='💳 '+num;
  openModal('payModal');
}
</script>
<?php resellerLayoutEnd(); ?>
