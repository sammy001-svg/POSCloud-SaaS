<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_SUPER_ADMIN);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$msg = $err = '';
$currentPage = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_general') {
        foreach ($_POST['settings'] as $key => $val) {
            $db->prepare("UPDATE platform_settings SET setting_value=? WHERE setting_key=?")
               ->execute([$val, $key]);
        }
        $msg = 'System settings updated.';
    }
}

$settings = $db->prepare("SELECT setting_key, setting_value FROM platform_settings");
$settings->execute();
$sData = $settings->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'System Settings';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <div><div class="page-title">System Settings</div><div class="page-subtitle">Configure global platform defaults and system behavior</div></div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">🌐 Platform Localization</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
            <input type="hidden" name="action" value="update_general"/>
            
            <div class="form-group">
                <label class="form-label">Platform Name</label>
                <input class="form-control" name="settings[platform_name]" value="<?= htmlspecialchars($sData['platform_name'] ?? '') ?>"/>
            </div>
            
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">Default Currency</label>
                    <input class="form-control" name="settings[currency]" value="<?= htmlspecialchars($sData['currency'] ?? '') ?>"/>
                </div>
                <div class="form-group">
                    <label class="form-label">Currency Symbol</label>
                    <input class="form-control" name="settings[currency_symbol]" value="<?= htmlspecialchars($sData['currency_symbol'] ?? '') ?>"/>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Timezone</label>
                <select class="form-control" name="settings[timezone]">
                    <option value="Africa/Nairobi" <?= ($sData['timezone']??'') == 'Africa/Nairobi' ? 'selected' : '' ?>>Africa/Nairobi (EAT)</option>
                    <option value="UTC" <?= ($sData['timezone']??'') == 'UTC' ? 'selected' : '' ?>>UTC / GMT</option>
                    <option value="America/New_York" <?= ($sData['timezone']??'') == 'America/New_York' ? 'selected' : '' ?>>Eastern Time</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Grace Period (Days)</label>
                <input class="form-control" type="number" name="settings[grace_period_days]" value="<?= htmlspecialchars($sData['grace_period_days'] ?? '7') ?>"/>
                <small style="color: var(--text-muted);">Number of days to keep accounts active after subscription expiry.</small>
            </div>

            <button type="submit" class="btn btn-primary w-full">Save Changes</button>
        </form>
    </div>

    <div class="card">
        <div style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">📧 SMTP Configuration</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
            <input type="hidden" name="action" value="update_general"/>
            
            <div class="form-group">
                <label class="form-label">SMTP Host</label>
                <input class="form-control" name="settings[smtp_host]" value="<?= htmlspecialchars($sData['smtp_host'] ?? '') ?>"/>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label class="form-label">SMTP Port</label>
                    <input class="form-control" name="settings[smtp_port]" value="<?= htmlspecialchars($sData['smtp_port'] ?? '') ?>"/>
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP From Email</label>
                    <input class="form-control" name="settings[smtp_from]" value="<?= htmlspecialchars($sData['smtp_from'] ?? '') ?>"/>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">SMTP User</label>
                <input class="form-control" name="settings[smtp_user]" value="<?= htmlspecialchars($sData['smtp_user'] ?? '') ?>"/>
            </div>
            <div class="form-group">
                <label class="form-label">SMTP Password</label>
                <input class="form-control" type="password" name="settings[smtp_pass]" value="<?= htmlspecialchars($sData['smtp_pass'] ?? '') ?>"/>
            </div>

            <button type="submit" class="btn btn-primary w-full">Save SMTP Settings</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
