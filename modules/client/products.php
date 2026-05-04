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
        $name    = trim($_POST['name'] ?? '');
        $cat     = (int)($_POST['category_id'] ?? 0) ?: null;
        $sku     = trim($_POST['sku'] ?? '');
        $buy     = (float)($_POST['buying_price'] ?? 0);
        $sell    = (float)($_POST['selling_price'] ?? 0);
        $tax     = ($_POST['tax_rate'] ?? '') === '' ? null : (float)$_POST['tax_rate'];
        $track   = isset($_POST['track_stock']) ? 1 : 0;
        $desc    = trim($_POST['description'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');

        if (empty($name)) { $err = 'Product name is required.'; }
        elseif ($sell <= 0) { $err = 'Selling price must be greater than 0.'; }
        else {
            $imagePath = $_POST['existing_image'] ?? null;
            if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
                $newName = 'prod_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                $target = __DIR__ . '/../../uploads/products/' . $newName;
                if (move_uploaded_file($_FILES['product_image']['tmp_name'], $target)) {
                    $imagePath = $newName;
                }
            }

            if ($action === 'create') {
                $db->prepare("INSERT INTO products (tenant_id,category_id,name,sku,description,buying_price,selling_price,tax_rate,track_stock,image) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$tid,$cat,$name,$sku,$desc,$buy,$sell,$tax,$track,$imagePath]);
                $pid = $db->lastInsertId();
                if ($barcode) {
                    $db->prepare("INSERT INTO product_barcodes (product_id,barcode,is_primary) VALUES (?,?,1)")->execute([$pid,$barcode]);
                }
                // Create stock record for each branch
                $branches = $db->prepare("SELECT id FROM tenant_branches WHERE tenant_id=? AND is_active=1"); $branches->execute([$tid]);
                foreach ($branches->fetchAll() as $b) {
                    $db->prepare("INSERT INTO stock_levels (tenant_id,branch_id,product_id,quantity) VALUES (?,?,?,0)")->execute([$tid,$b['id'],$pid]);
                }
                $msg = "Product '{$name}' created.";
            } else {
                $pid = (int)$_POST['product_id'];
                $db->prepare("UPDATE products SET category_id=?,name=?,sku=?,description=?,buying_price=?,selling_price=?,tax_rate=?,track_stock=?,image=? WHERE id=? AND tenant_id=?")
                   ->execute([$cat,$name,$sku,$desc,$buy,$sell,$tax,$track,$imagePath,$pid,$tid]);
                if ($barcode) {
                    $db->prepare("DELETE FROM product_barcodes WHERE product_id=?")->execute([$pid]);
                    $db->prepare("INSERT INTO product_barcodes (product_id,barcode,is_primary) VALUES (?,?,1)")->execute([$pid,$barcode]);
                }
                $msg = "Product updated.";
            }
        }
    }

    if ($action === 'toggle') {
        $pid = (int)$_POST['product_id'];
        $db->prepare("UPDATE products SET is_active=NOT is_active WHERE id=? AND tenant_id=?")->execute([$pid,$tid]);
        $msg = 'Product status toggled.';
    }

    if ($action === 'delete') {
        $pid = (int)$_POST['product_id'];
        $db->prepare("UPDATE products SET deleted_at=NOW() WHERE id=? AND tenant_id=?")->execute([$pid,$tid]);
        $msg = 'Product archived.';
    }
}

$search   = trim($_GET['q'] ?? '');
$cat_f    = (int)($_GET['cat'] ?? 0);
$page     = max(1,(int)($_GET['page']??1)); $limit=ITEMS_PER_PAGE; $offset=($page-1)*$limit;

$where = ["p.tenant_id=?","p.deleted_at IS NULL"]; $params=[$tid];
if ($search) { $where[]="(p.name LIKE ? OR p.sku LIKE ?)"; $params=array_merge($params,["%$search%","%$search%"]); }
if ($cat_f)  { $where[]="p.category_id=?"; $params[]=$cat_f; }
$w='WHERE '.implode(' AND ',$where);

$total = $db->prepare("SELECT COUNT(*) FROM products p $w"); $total->execute($params); $totalRows=$total->fetchColumn(); $pages=ceil($totalRows/$limit);

$stmt = $db->prepare("SELECT p.*, c.name AS cat_name, (SELECT b.barcode FROM product_barcodes b WHERE b.product_id=p.id AND b.is_primary=1 LIMIT 1) AS barcode FROM products p LEFT JOIN categories c ON c.id=p.category_id $w ORDER BY p.name ASC LIMIT $limit OFFSET $offset");
$stmt->execute($params); $products=$stmt->fetchAll();

$categories = $db->prepare("SELECT * FROM categories WHERE tenant_id=? AND is_active=1 ORDER BY name"); $categories->execute([$tid]); $categories=$categories->fetchAll();

$editProduct = null;
if (isset($_GET['edit'])) {
    $ep = $db->prepare("SELECT p.*, (SELECT b.barcode FROM product_barcodes b WHERE b.product_id=p.id AND b.is_primary=1 LIMIT 1) AS barcode FROM products p WHERE p.id=? AND p.tenant_id=?");
    $ep->execute([(int)$_GET['edit'],$tid]); $editProduct=$ep->fetch();
}

clientLayout('Products', $currentPage);
$tenant = $GLOBALS['_tenant'];
?>

<div class="page-header">
  <div><div class="page-title">Products</div><div class="page-subtitle"><?= number_format($totalRows) ?> product(s) in your catalog</div></div>
  <div class="flex gap-2">
    <a href="categories.php" class="btn btn-outline">🗂 Categories</a>
    <button class="btn btn-primary" onclick="openModal('productModal')">+ Add Product</button>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="padding:16px;margin-bottom:20px;">
  <form method="GET" class="flex gap-2" style="flex-wrap:wrap;">
    <div style="flex:1;min-width:200px;"><input class="form-control" name="q" placeholder="🔍 Search products, SKU..." value="<?= htmlspecialchars($search) ?>"/></div>
    <select class="form-control" name="cat" style="width:180px;">
      <option value="">All Categories</option>
      <?php foreach($categories as $c): ?><option value="<?=$c['id']?>" <?= $cat_f==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a href="products.php" class="btn btn-outline">Clear</a>
  </form>
</div>

<div class="card" style="padding:0;overflow:hidden;">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Product</th><th>Category</th><th>Buying</th><th>Selling</th><th>Barcode</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($products)): ?>
          <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No products yet. <a href="#" onclick="openModal('productModal')">Add your first product</a>.</td></tr>
        <?php else: foreach ($products as $p): ?>
          <tr>
            <td>
              <div style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($p['name']) ?></div>
              <?php if ($p['sku']): ?><div style="font-size:11px;color:var(--text-muted);">SKU: <?= htmlspecialchars($p['sku']) ?></div><?php endif; ?>
            </td>
            <td style="font-size:12px;"><?= htmlspecialchars($p['cat_name'] ?? '—') ?></td>
            <td><?= $tenant['currency_symbol'] ?> <?= number_format($p['buying_price'],2) ?></td>
            <td style="font-weight:700;color:var(--success);"><?= $tenant['currency_symbol'] ?> <?= number_format($p['selling_price'],2) ?></td>
            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($p['barcode'] ?? '—') ?></td>
            <td>
              <?php if ($p['is_active']): ?>
                <span class="badge badge-success">Active</span>
              <?php else: ?>
                <span class="badge badge-muted">Inactive</span>
              <?php endif; ?>
              <?php if (!$p['track_stock']): ?><span class="badge badge-info" style="margin-left:4px;">No Track</span><?php endif; ?>
            </td>
            <td>
              <div class="flex gap-1">
                <a href="?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline">✏️</a>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="toggle"/>
                  <input type="hidden" name="product_id" value="<?= $p['id'] ?>"/>
                  <button class="btn btn-sm btn-outline"><?= $p['is_active']?'⏸':'▶' ?></button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this product?');">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="product_id" value="<?= $p['id'] ?>"/>
                  <button class="btn btn-sm btn-danger">🗑</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages>1): ?><div style="padding:16px;border-top:1px solid var(--border);"><div class="pagination"><?php for($i=1;$i<=$pages;$i++): ?><a href="?q=<?=urlencode($search)?>&cat=<?=$cat_f?>&page=<?=$i?>" class="page-btn <?=$page===$i?'active':''?>"><?=$i?></a><?php endfor; ?></div></div><?php endif; ?>
</div>

<!-- Product Modal -->
<div class="modal-overlay" id="productModal" style="display:none;">
  <div class="modal" style="max-width:600px;">
    <div class="modal-header">
      <span style="font-weight:700;"><?= $editProduct?'✏️ Edit Product':'➕ Add Product' ?></span>
      <button class="modal-close" onclick="closeModal('productModal')">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="action" value="<?= $editProduct?'edit':'create' ?>"/>
      <?php if ($editProduct): ?><input type="hidden" name="product_id" value="<?= $editProduct['id'] ?>"/><?php endif; ?>
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Product Name *</label>
            <input class="form-control" name="name" required value="<?= htmlspecialchars($editProduct['name']??'') ?>" placeholder="e.g. Panadol 500mg, Milk 1L"/>
          </div>
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Product Image</label>
            <div style="display:flex;gap:15px;align-items:center;">
              <?php if ($editProduct && $editProduct['image']): ?>
                <img src="<?= APP_URL ?>/uploads/products/<?= $editProduct['image'] ?>" style="width:50px;height:50px;border-radius:8px;object-fit:cover;"/>
                <input type="hidden" name="existing_image" value="<?= $editProduct['image'] ?>"/>
              <?php endif; ?>
              <input type="file" name="product_image" class="form-control" accept="image/*"/>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Recommended: Square image, max 2MB</div>
          </div>
          <div class="form-group">
            <label class="form-label">SKU / Item Code</label>
            <input class="form-control" name="sku" value="<?= htmlspecialchars($editProduct['sku']??'') ?>" placeholder="Auto-generate if blank"/>
          </div>
          <div class="form-group">
            <label class="form-label">Barcode</label>
            <input class="form-control" name="barcode" value="<?= htmlspecialchars($editProduct['barcode']??'') ?>" placeholder="Scan or type barcode"/>
          </div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <select class="form-control" name="category_id">
              <option value="">— Uncategorized —</option>
              <?php foreach($categories as $c): ?><option value="<?=$c['id']?>" <?= ($editProduct['category_id']??0)==$c['id']?'selected':'' ?>><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Tax Rate (%)</label>
            <input class="form-control" type="number" name="tax_rate" step="0.01" value="<?= $editProduct['tax_rate']??'' ?>" placeholder="Use business default"/>
          </div>
          <div class="form-group">
            <label class="form-label">Buying Price (<?= $tenant['currency_symbol'] ?>)</label>
            <input class="form-control" type="number" name="buying_price" step="0.01" value="<?= $editProduct['buying_price']??0 ?>" placeholder="0.00"/>
          </div>
          <div class="form-group">
            <label class="form-label">Selling Price (<?= $tenant['currency_symbol'] ?>) *</label>
            <input class="form-control" type="number" name="selling_price" step="0.01" required value="<?= $editProduct['selling_price']??'' ?>" placeholder="0.00"/>
          </div>
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="2" placeholder="Optional product description..."><?= htmlspecialchars($editProduct['description']??'') ?></textarea>
          </div>
          <div class="form-group" style="grid-column:span 2;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
              <input type="checkbox" name="track_stock" value="1" <?= ($editProduct['track_stock']??1)?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--primary);"/>
              <span style="font-size:13px;font-weight:500;">Track stock levels for this product</span>
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="products.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= $editProduct?'Save Changes':'Add Product' ?></button>
      </div>
    </form>
  </div>
</div>
<script><?php if($editProduct||$err):?>openModal('productModal');<?php endif;?></script>
<?php clientLayoutEnd(); ?>
