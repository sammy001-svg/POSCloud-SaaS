<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$msg = $err = '';
$currentPage = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim(strtolower($_POST['email'] ?? ''));
        $phone   = trim($_POST['phone'] ?? '');
        $role    = $_POST['role'] ?? 'cashier';
        $brid    = (int)($_POST['branch_id'] ?? 0) ?: null;
        $pwd     = $_POST['password'] ?? '';

        if (empty($name) || empty($email)) { $err = 'Name and email are required.'; }
        else {
            if ($action === 'create') {
                $check = $db->prepare("SELECT id FROM tenant_users WHERE email=?");
                $check->execute([$email]);
                if ($check->fetch()) { $err = 'Email already registered.'; }
                elseif (strlen($pwd) < 6) { $err = 'Password must be at least 6 characters.'; }
                else {
                    $db->prepare("INSERT INTO tenant_users (tenant_id, branch_id, name, email, phone, role, password) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$tid, $brid, $name, $email, $phone, $role, hashPassword($pwd)]);
                    $msg = "User '{$name}' created.";
                }
            } else {
                $uid = (int)$_POST['user_id'];
                if ($pwd) {
                    $db->prepare("UPDATE tenant_users SET name=?, email=?, phone=?, role=?, branch_id=?, password=? WHERE id=? AND tenant_id=?")
                       ->execute([$name, $email, $phone, $role, $brid, hashPassword($pwd), $uid, $tid]);
                } else {
                    $db->prepare("UPDATE tenant_users SET name=?, email=?, phone=?, role=?, branch_id=? WHERE id=? AND tenant_id=?")
                       ->execute([$name, $email, $phone, $role, $brid, $uid, $tid]);
                }
                $msg = "User updated.";
            }
        }
    }

    if ($action === 'toggle') {
        $uid = (int)$_POST['user_id'];
        // Don't disable yourself
        if ($uid == currentUser()['id']) { $err = 'You cannot disable your own account.'; }
        else {
            $db->prepare("UPDATE tenant_users SET is_active=NOT is_active WHERE id=? AND tenant_id=?")->execute([$uid, $tid]);
            $msg = 'Status updated.';
        }
    }
}

$users = $db->prepare("
    SELECT u.*, b.branch_name 
    FROM tenant_users u 
    LEFT JOIN tenant_branches b ON b.id=u.branch_id 
    WHERE u.tenant_id=? 
    ORDER BY u.role ASC, u.name ASC
");
$users->execute([$tid]);
$users = $users->fetchAll();

$branches = $db->prepare("SELECT id, branch_name FROM tenant_branches WHERE tenant_id=? AND is_active=1");
$branches->execute([$tid]);
$branches = $branches->fetchAll();

$editUser = null;
if (isset($_GET['edit'])) {
    $eu = $db->prepare("SELECT * FROM tenant_users WHERE id=? AND tenant_id=?");
    $eu->execute([(int)$_GET['edit'], $tid]); $editUser = $eu->fetch();
}

clientLayout('Staff Management', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Staff Management</div><div class="page-subtitle">Manage your team and their access permissions</div></div>
  <button class="btn btn-primary" onclick="openModal('userModal')">+ New Staff Member</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Assigned Branch</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): 
            $roleColor = ($u['role'] === 'owner') ? 'badge-danger' : (($u['role'] === 'manager') ? 'badge-warning' : 'badge-info');
        ?>
          <tr>
            <td>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-glow); display: flex; align-items: center; justify-content: center; font-weight: 800; color: var(--primary); font-size: 12px;"><?= strtoupper(substr($u['name'],0,1)) ?></div>
                    <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($u['name']) ?></div>
                </div>
            </td>
            <td style="font-size: 13px;"><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge <?= $roleColor ?>"><?= ucfirst($u['role']) ?></span></td>
            <td style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($u['branch_name'] ?: 'All Branches') ?></td>
            <td>
                <?php if ($u['is_active']): ?>
                    <span class="badge badge-success">Active</span>
                <?php else: ?>
                    <span class="badge badge-muted">Disabled</span>
                <?php endif; ?>
            </td>
            <td>
              <div class="flex gap-1">
                <a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline">✏️</a>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                    <input type="hidden" name="action" value="toggle"/>
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                    <button class="btn btn-sm btn-outline" <?= $u['id'] == currentUser()['id'] ? 'disabled' : '' ?>><?= $u['is_active'] ? '⏸' : '▶' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- User Modal -->
<div class="modal-overlay" id="userModal" style="display: none;">
  <div class="modal" style="max-width: 450px;">
    <div class="modal-header">
      <span style="font-weight: 700;"><?= $editUser ? '✏️ Edit Staff Member' : '➕ New Staff Member' ?></span>
      <button class="modal-close" onclick="closeModal('userModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editUser ? 'edit' : 'create' ?>"/>
      <?php if ($editUser): ?><input type="hidden" name="user_id" value="<?= $editUser['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input class="form-control" name="name" required value="<?= htmlspecialchars($editUser['name'] ?? '') ?>"/>
        </div>
        <div class="form-group">
          <label class="form-label">Email Address *</label>
          <input class="form-control" type="email" name="email" required value="<?= htmlspecialchars($editUser['email'] ?? '') ?>"/>
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label class="form-label">Role</label>
                <select class="form-control" name="role">
                    <option value="cashier" <?= ($editUser['role']??'') == 'cashier' ? 'selected' : '' ?>>Cashier</option>
                    <option value="inventory" <?= ($editUser['role']??'') == 'inventory' ? 'selected' : '' ?>>Inventory Mgr</option>
                    <option value="manager" <?= ($editUser['role']??'') == 'manager' ? 'selected' : '' ?>>Store Manager</option>
                    <option value="owner" <?= ($editUser['role']??'') == 'owner' ? 'selected' : '' ?>>Owner</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Branch Access</label>
                <select class="form-control" name="branch_id">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= ($editUser['branch_id']??'') == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
          <label class="form-label"><?= $editUser ? 'Change Password' : 'Password *' ?></label>
          <input class="form-control" type="password" name="password" <?= $editUser ? '' : 'required' ?> placeholder="<?= $editUser ? 'Leave blank to keep current' : 'Min. 6 characters' ?>"/>
        </div>
      </div>
      <div class="modal-footer">
        <a href="users.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editUser ? 'Save Changes' : 'Create User' ?></button>
      </div>
    </form>
  </div>
</div>
<script><?php if($editUser || $err): ?>openModal('userModal');<?php endif; ?></script>
<?php clientLayoutEnd(); ?>
