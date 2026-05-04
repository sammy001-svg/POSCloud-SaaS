<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_RESELLER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$rid = currentUser()['reseller_id'];
$msg = $err = '';
$currentPage = 'settings';

$stmt = $db->prepare("SELECT * FROM resellers WHERE id=?"); $stmt->execute([$rid]);
$reseller = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $name  = trim($_POST['contact_name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name) || empty($email)) {
            $err = 'Name and Email are required.';
        } else {
            $db->prepare("UPDATE resellers SET contact_name=?, email=?, phone=? WHERE id=?")
               ->execute([$name, $email, $phone, $rid]);
            $msg = 'Profile updated successfully!';
            // Refresh reseller data
            $stmt->execute([$rid]); $reseller = $stmt->fetch();
        }
    } elseif ($action === 'password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $cfm = $_POST['confirm_password'] ?? '';

        if (!password_verify($old, $reseller['password'])) {
            $err = 'Incorrect current password.';
        } elseif (strlen($new) < 6) {
            $err = 'New password must be at least 6 characters.';
        } elseif ($new !== $cfm) {
            $err = 'Passwords do not match.';
        } else {
            $hash = hashPassword($new);
            $db->prepare("UPDATE resellers SET password=? WHERE id=?")->execute([$hash, $rid]);
            $msg = 'Password changed successfully!';
        }
    }
}

resellerLayout('Account Settings', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Account Settings</div><div class="page-subtitle">Manage your personal information and security</div></div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-2">
  <!-- Profile Section -->
  <div class="card">
    <div style="font-weight:700;font-size:16px;margin-bottom:20px;color:var(--primary);display:flex;align-items:center;gap:10px;">👤 Profile Information</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="profile"/>
      
      <div class="form-group">
        <label class="form-label">Full Name / Contact Person</label>
        <input class="form-control" name="contact_name" value="<?= htmlspecialchars($reseller['contact_name']) ?>" required/>
      </div>
      
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($reseller['email']) ?>" required/>
      </div>
      
      <div class="form-group">
        <label class="form-label">Phone Number</label>
        <input class="form-control" name="phone" value="<?= htmlspecialchars($reseller['phone'] ?? '') ?>"/>
      </div>
      
      <button type="submit" class="btn btn-primary w-full" style="margin-top:10px;">💾 Update Profile</button>
    </form>
  </div>

  <!-- Security Section -->
  <div class="card">
    <div style="font-weight:700;font-size:16px;margin-bottom:20px;color:var(--primary);display:flex;align-items:center;gap:10px;">🔒 Security & Password</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="password"/>
      
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input class="form-control" type="password" name="old_password" required/>
      </div>
      
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input class="form-control" type="password" name="new_password" placeholder="At least 6 characters" required/>
      </div>
      
      <div class="form-group">
        <label class="form-label">Confirm New Password</label>
        <input class="form-control" type="password" name="confirm_password" required/>
      </div>
      
      <button type="submit" class="btn btn-outline w-full" style="margin-top:10px; border-color: var(--danger); color: var(--danger);">🔑 Change Password</button>
    </form>
  </div>
</div>

<?php resellerLayoutEnd(); ?>
