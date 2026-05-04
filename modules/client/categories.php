<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_INVENTORY);
require_once __DIR__ . '/_layout.php';

$db  = getDB();
$tid = currentUser()['tenant_id'];
$msg = $err = '';
$currentPage = 'products';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) { $err = 'Category name is required.'; }
        else {
            if ($action === 'create') {
                $db->prepare("INSERT INTO categories (tenant_id, name, description) VALUES (?,?,?)")
                   ->execute([$tid, $name, $desc]);
                $msg = "Category '{$name}' created.";
            } else {
                $cid = (int)$_POST['category_id'];
                $db->prepare("UPDATE categories SET name=?, description=? WHERE id=? AND tenant_id=?")
                   ->execute([$name, $desc, $cid, $tid]);
                $msg = "Category updated.";
            }
        }
    }

    if ($action === 'delete') {
        $cid = (int)$_POST['category_id'];
        // Check if products use it
        $check = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id=?");
        $check->execute([$cid]);
        if ($check->fetchColumn() > 0) { $err = 'Cannot delete — category contains products.'; }
        else {
            $db->prepare("DELETE FROM categories WHERE id=? AND tenant_id=?")->execute([$cid, $tid]);
            $msg = 'Category removed.';
        }
    }
}

$categories = $db->prepare("SELECT * FROM categories WHERE tenant_id=? ORDER BY name ASC");
$categories->execute([$tid]);
$categories = $categories->fetchAll();

$editCat = null;
if (isset($_GET['edit'])) {
    $ec = $db->prepare("SELECT * FROM categories WHERE id=? AND tenant_id=?");
    $ec->execute([(int)$_GET['edit'], $tid]); $editCat = $ec->fetch();
}

clientLayout('Product Categories', $currentPage);
?>

<div class="page-header">
  <div><div class="page-title">Categories</div><div class="page-subtitle">Organize your products into logical groups</div></div>
  <div class="flex gap-2">
      <a href="products.php" class="btn btn-outline">← Back to Products</a>
      <button class="btn btn-primary" onclick="openModal('catModal')">+ New Category</button>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid-3">
    <?php foreach ($categories as $c): ?>
    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="font-size: 24px; margin-bottom: 10px;">📂</div>
            <div style="font-size: 16px; font-weight: 700; color: white; margin-bottom: 6px;"><?= htmlspecialchars($c['name']) ?></div>
            <div style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($c['description'] ?: 'No description') ?></div>
        </div>
        <div class="flex gap-1" style="margin-top: 20px;">
            <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline" style="flex: 1;">✏️ Edit</a>
            <form method="POST" style="flex: 1;" onsubmit="return confirm('Delete this category?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="category_id" value="<?= $c['id'] ?>"/>
                <button class="btn btn-sm btn-danger w-full">🗑 Delete</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Category Modal -->
<div class="modal-overlay" id="catModal" style="display: none;">
  <div class="modal" style="max-width: 400px;">
    <div class="modal-header">
      <span style="font-weight: 700;"><?= $editCat ? '✏️ Edit Category' : '➕ New Category' ?></span>
      <button class="modal-close" onclick="closeModal('catModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editCat ? 'edit' : 'create' ?>"/>
      <?php if ($editCat): ?><input type="hidden" name="category_id" value="<?= $editCat['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Category Name *</label>
          <input class="form-control" name="name" required value="<?= htmlspecialchars($editCat['name'] ?? '') ?>" placeholder="e.g. Beverages, Medicine, Groceries"/>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($editCat['description'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <a href="categories.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editCat ? 'Save Changes' : 'Create Category' ?></button>
      </div>
    </form>
  </div>
</div>
<script><?php if($editCat || $err): ?>openModal('catModal');<?php endif; ?></script>
<?php clientLayoutEnd(); ?>
