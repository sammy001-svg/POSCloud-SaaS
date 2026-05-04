<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$reseller = $db->prepare("SELECT r.*, sp.plan_name FROM resellers r LEFT JOIN subscription_plans sp ON sp.id=r.plan_id WHERE r.id=? AND r.deleted_at IS NULL");
$reseller->execute([$id]);
$reseller = $reseller->fetch();
if (!$reseller) { header('Location: resellers.php'); exit; }

$pageTitle = $reseller['company_name'];

// Clients under this reseller
$clients = $db->prepare("
    SELECT t.*, sp.plan_name FROM tenants t
    LEFT JOIN subscription_plans sp ON sp.id=t.plan_id
    WHERE t.reseller_id=? AND t.deleted_at IS NULL ORDER BY t.created_at DESC
");
$clients->execute([$id]);
$clients = $clients->fetchAll();

// Invoices to this reseller
$invoices = $db->prepare("SELECT * FROM invoices WHERE to_entity_type='reseller' AND to_entity_id=? ORDER BY created_at DESC LIMIT 10");
$invoices->execute([$id]);
$invoices = $invoices->fetchAll();

// Revenue total
$revenue = $db->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.to_entity_type='reseller' AND i.to_entity_id=?");
$revenue->execute([$id]);
$revenue = $revenue->fetchColumn();

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:16px;">
    <a href="resellers.php" class="btn btn-outline btn-sm">← Back</a>
    <div>
      <div class="page-title"><?= htmlspecialchars($reseller['company_name']) ?></div>
      <div class="page-subtitle"><?= htmlspecialchars($reseller['email']) ?> · <?= htmlspecialchars($reseller['phone'] ?? '—') ?></div>
    </div>
  </div>
  <div class="flex gap-2">
    <?php $sc=['active'=>'badge-success','trial'=>'badge-info','suspended'=>'badge-warning','expired'=>'badge-danger']; ?>
    <span class="badge <?= $sc[$reseller['subscription_status']]??'badge-muted' ?>" style="font-size:13px;padding:6px 14px;">
      <?= ucfirst($reseller['subscription_status']) ?>
    </span>
    <button class="btn btn-outline" onclick="openModal('invoiceModal')">🧾 Create Invoice</button>
  </div>
</div>

<!-- KPIs -->
<div class="grid-4" style="margin-bottom:24px;">
  <div class="stat-card">
    <div class="stat-icon purple">🏢</div>
    <div><div class="stat-value"><?= count($clients) ?></div><div class="stat-label">Total Clients</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">💰</div>
    <div><div class="stat-value">KSh <?= number_format($revenue,0) ?></div><div class="stat-label">Total Revenue</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">📦</div>
    <div><div class="stat-value"><?= htmlspecialchars($reseller['plan_name'] ?? 'Trial') ?></div><div class="stat-label">Current Plan</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">📅</div>
    <div><div class="stat-value"><?= $reseller['subscription_ends_at'] ? date('d M', strtotime($reseller['subscription_ends_at'])) : 'N/A' ?></div><div class="stat-label">Expires</div></div>
  </div>
</div>

<div class="grid-2">
  <!-- Clients -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;">🏢 Clients (<?= count($clients) ?>)</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Business</th><th>Type</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($clients)): ?>
            <tr><td colspan="3" style="text-align:center;padding:28px;color:var(--text-muted);">No clients yet.</td></tr>
          <?php else: foreach ($clients as $c): ?>
            <tr>
              <td>
                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($c['business_name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div>
              </td>
              <td style="text-transform:capitalize;"><?= str_replace('_',' ',$c['business_type']) ?></td>
              <td><span class="badge <?= $sc[$c['subscription_status']]??'badge-muted' ?>"><?= ucfirst($c['subscription_status']) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Invoices -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);font-weight:600;">🧾 Invoices</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Invoice</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($invoices)): ?>
            <tr><td colspan="3" style="text-align:center;padding:28px;color:var(--text-muted);">No invoices.</td></tr>
          <?php else: foreach ($invoices as $inv):
            $ic=['paid'=>'badge-success','sent'=>'badge-info','overdue'=>'badge-danger','draft'=>'badge-muted','cancelled'=>'badge-muted'];
          ?>
            <tr>
              <td>
                <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($inv['invoice_number']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);">Due: <?= date('d M Y',strtotime($inv['due_date'])) ?></div>
              </td>
              <td style="font-weight:600;">KSh <?= number_format($inv['total_amount'],2) ?></td>
              <td><span class="badge <?= $ic[$inv['status']]??'badge-muted' ?>"><?= ucfirst($inv['status']) ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Reseller Info Card -->
<div class="card" style="margin-top:20px;">
  <div style="font-weight:600;font-size:15px;margin-bottom:16px;">⚙️ Reseller Details</div>
  <div class="grid-3">
    <div><div class="form-label">Company</div><div style="color:var(--text-primary);font-weight:500;"><?= htmlspecialchars($reseller['company_name']) ?></div></div>
    <div><div class="form-label">Contact</div><div style="color:var(--text-primary);"><?= htmlspecialchars($reseller['contact_name']) ?></div></div>
    <div><div class="form-label">Slug</div><div style="color:var(--text-primary);"><?= htmlspecialchars($reseller['slug']) ?></div></div>
    <div><div class="form-label">Primary Color</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <div style="width:20px;height:20px;border-radius:4px;background:<?= htmlspecialchars($reseller['brand_color_primary']) ?>;border:1px solid var(--border);"></div>
        <span><?= htmlspecialchars($reseller['brand_color_primary']) ?></span>
      </div>
    </div>
    <div><div class="form-label">Custom Domain</div><div style="color:var(--text-primary);"><?= htmlspecialchars($reseller['custom_domain'] ?? '—') ?></div></div>
    <div><div class="form-label">Joined</div><div style="color:var(--text-primary);"><?= date('d M Y', strtotime($reseller['created_at'])) ?></div></div>
  </div>
</div>

<!-- Create Invoice Modal -->
<div class="modal-overlay" id="invoiceModal" style="display:none;">
  <div class="modal">
    <div class="modal-header">
      <span style="font-weight:700;">🧾 Create Invoice for <?= htmlspecialchars($reseller['company_name']) ?></span>
      <button class="modal-close" onclick="closeModal('invoiceModal')">✕</button>
    </div>
    <form method="POST" action="billing.php">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="create_invoice"/>
      <input type="hidden" name="to_type" value="reseller"/>
      <input type="hidden" name="to_id" value="<?= $reseller['id'] ?>"/>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Amount (KSh) *</label>
            <input class="form-control" type="number" name="amount" step="0.01" required placeholder="0.00"/>
          </div>
          <div class="form-group">
            <label class="form-label">Due Date *</label>
            <input class="form-control" type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>"/>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="2" placeholder="Invoice description..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('invoiceModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send Invoice</button>
      </div>
    </form>
  </div>
</div>
<script>
function openModal(id)  { document.getElementById(id).style.display='flex'; }
function closeModal(id) { document.getElementById(id).style.display='none'; }
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
