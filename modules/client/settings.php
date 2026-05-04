<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$msg = $err = '';
$currentPage = 'settings';

$stmt = $db->prepare("SELECT * FROM tenants WHERE id=?");
$stmt->execute([$tid]);
$tenant = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $bname    = trim($_POST['business_name'] ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $address  = trim($_POST['address'] ?? '');
        $currency = $_POST['currency'] ?? 'KES';
        $symbol   = $_POST['currency_symbol'] ?? 'KSh';

        if (empty($bname)) { $err = 'Business name is required.'; }
        else {
            $db->prepare("UPDATE tenants SET business_name=?, email=?, phone=?, address=?, currency=?, currency_symbol=? WHERE id=?")
               ->execute([$bname, $email, $phone, $address, $currency, $symbol, $tid]);
            $msg = 'Business profile updated.';
        }
    }

    if ($action === 'update_receipt') {
        $header = trim($_POST['receipt_header'] ?? '');
        $footer = trim($_POST['receipt_footer'] ?? '');
        $taxName = trim($_POST['tax_name'] ?? 'VAT');
        $taxRate = (float)($_POST['tax_rate'] ?? 16.00);

        $db->prepare("UPDATE tenants SET receipt_header=?, receipt_footer=?, tax_name=?, tax_rate=? WHERE id=?")
           ->execute([$header, $footer, $taxName, $taxRate, $tid]);
        $msg = 'Receipt and Tax settings updated.';
    }

    if ($action === 'update_logo') {
        if (!empty($_FILES['logo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
            if (!in_array($ext, $allowed)) {
                $err = 'Invalid file type.';
            } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                $err = 'Logo must be under 2MB.';
            } else {
                $filename = 'tenant_' . $tid . '_' . time() . '.' . $ext;
                $dest = LOGO_DIR . $filename;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                    $db->prepare("UPDATE tenants SET logo_path=? WHERE id=?")->execute([$filename, $tid]);
                    $msg = 'Logo updated successfully.';
                } else {
                    $err = 'Failed to upload logo.';
                }
            }
        }
    }

    // Refresh data after update
    $stmt = $db->prepare("SELECT * FROM tenants WHERE id=?");
    $stmt->execute([$tid]);
    $tenant = $stmt->fetch();
}

clientLayout('Settings', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Business Settings</div><div class="page-subtitle">Configure your business profile, branding, and receipts</div></div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-2" style="align-items: start;">
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Business Profile -->
        <div class="card">
            <div style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">🏢 Business Profile</div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                <input type="hidden" name="action" value="update_profile"/>
                <div class="form-group">
                    <label class="form-label">Business Name *</label>
                    <input class="form-control" name="business_name" required value="<?= htmlspecialchars($tenant['business_name']) ?>"/>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Contact Email</label>
                        <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($tenant['email']) ?>"/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input class="form-control" name="phone" value="<?= htmlspecialchars($tenant['phone']) ?>"/>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Physical Address</label>
                    <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($tenant['address']) ?></textarea>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Currency Code</label>
                        <input class="form-control" name="currency" value="<?= htmlspecialchars($tenant['currency']) ?>" placeholder="e.g. KES"/>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Currency Symbol</label>
                        <input class="form-control" name="currency_symbol" value="<?= htmlspecialchars($tenant['currency_symbol']) ?>" placeholder="e.g. KSh"/>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full">Save Profile Changes</button>
            </form>
        </div>

        <!-- Logo Upload -->
        <div class="card">
            <div style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">🖼️ Business Logo</div>
            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 20px;">
                <div style="width: 80px; height: 80px; border-radius: 12px; background: var(--bg-dark); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                    <?php if ($tenant['logo_path']): ?>
                        <img src="<?= APP_URL ?>/uploads/logos/<?= htmlspecialchars($tenant['logo_path']) ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;"/>
                    <?php else: ?>
                        <span style="font-size: 32px;">🏪</span>
                    <?php endif; ?>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 14px; font-weight: 600; color: white; margin-bottom: 4px;">Upload New Logo</div>
                    <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px;">PNG, JPG, SVG. Max 2MB.</div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                        <input type="hidden" name="action" value="update_logo"/>
                        <div style="display: flex; gap: 8px;">
                            <input type="file" name="logo" id="logoInput" style="display: none;" onchange="this.form.submit()"/>
                            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('logoInput').click()">Choose File</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt & Tax -->
    <div class="card">
        <div style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">🧾 Receipt & Tax Configuration</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
            <input type="hidden" name="action" value="update_receipt"/>
            
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Tax Name</label>
                    <input class="form-control" name="tax_name" value="<?= htmlspecialchars($tenant['tax_name']) ?>" placeholder="e.g. VAT"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Tax Rate (%)</label>
                    <input class="form-control" type="number" name="tax_rate" step="0.01" value="<?= $tenant['tax_rate'] ?>"/>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Receipt Header</label>
                <textarea class="form-control" name="receipt_header" rows="4" placeholder="Appears at the top of printed receipts. Support HTML tags like <br> or <b>..."><?= htmlspecialchars($tenant['receipt_header']) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Receipt Footer</label>
                <textarea class="form-control" name="receipt_footer" rows="4" placeholder="Appears at the bottom of printed receipts. E.g. Thank you for your business!"><?= htmlspecialchars($tenant['receipt_footer']) ?></textarea>
            </div>

            <div style="background: var(--bg-dark); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-top: 10px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.05em;">Receipt Preview</div>
                <div style="background: white; color: black; padding: 15px; font-family: 'Courier New', Courier, monospace; font-size: 12px; line-height: 1.4; border-radius: 4px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.5);">
                    <div style="text-align: center; border-bottom: 1px dashed #ccc; padding-bottom: 10px; margin-bottom: 10px;">
                        <div style="font-weight: bold; font-size: 14px;"><?= htmlspecialchars($tenant['business_name']) ?></div>
                        <div style="white-space: pre-line;"><?= htmlspecialchars($tenant['receipt_header']) ?></div>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between;"><span>Item A x 1</span><span>100.00</span></div>
                        <div style="display: flex; justify-content: space-between;"><span>Item B x 2</span><span>400.00</span></div>
                    </div>
                    <div style="border-top: 1px dashed #ccc; padding-top: 5px; margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; font-weight: bold;"><span>TOTAL</span><span>500.00</span></div>
                        <div style="display: flex; justify-content: space-between; font-size: 10px;"><span>Incl. <?= htmlspecialchars($tenant['tax_name']) ?> (<?= $tenant['tax_rate'] ?>%)</span><span>68.97</span></div>
                    </div>
                    <div style="text-align: center; white-space: pre-line;"><?= htmlspecialchars($tenant['receipt_footer']) ?></div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-full" style="margin-top: 20px;">Save Receipt Settings</button>
        </form>
    </div>
</div>

<?php clientLayoutEnd(); ?>
