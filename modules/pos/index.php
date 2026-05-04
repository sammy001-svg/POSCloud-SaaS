<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_CASHIER);

$db = getDB();
$tid = currentUser()['tenant_id'];
$bid = currentUser()['branch_id'];
$uid = currentUser()['id'];

// 1. Check for active session
$session = $db->prepare("SELECT * FROM sales_sessions WHERE tenant_id=? AND branch_id=? AND user_id=? AND status='open' LIMIT 1");
$session->execute([$tid, $bid, $uid]);
$activeSession = $session->fetch();

$error = $_GET['error'] ?? '';
$msg = $_GET['msg'] ?? '';

// 2. Fetch categories for filtering
$categories = $db->prepare("SELECT * FROM categories WHERE tenant_id=? AND is_active=1 ORDER BY sort_order ASC, name ASC");
$categories->execute([$tid]);
$categories = $categories->fetchAll();

// 3. Initial products load
$products = $db->prepare("
    SELECT p.*, c.name as category_name, (SELECT barcode FROM product_barcodes WHERE product_id=p.id AND is_primary=1 LIMIT 1) as barcode 
    FROM products p 
    LEFT JOIN categories c ON c.id=p.category_id 
    WHERE p.tenant_id=? AND p.is_active=1 AND p.deleted_at IS NULL 
    LIMIT 100
");
$products->execute([$tid]);
$initialProducts = $products->fetchAll();

$tenant = $db->prepare("SELECT * FROM tenants WHERE id=?");
$tenant->execute([$tid]);
$tenantData = $tenant->fetch();

$branches = [];
if (!$bid) {
    $stmt = $db->prepare("SELECT id, branch_name FROM tenant_branches WHERE tenant_id=? AND is_active=1");
    $stmt->execute([$tid]);
    $branches = $stmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Terminal — <?= htmlspecialchars($tenantData['business_name']) ?></title>
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/pos.css">
    <script src="<?= APP_URL ?>/assets/js/db.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="pos-mode">

<?php if (!$activeSession): ?>
    <!-- Open Session Screen -->
    <div class="pos-modal-overlay" style="display: flex;">
        <div class="pos-modal" style="max-width: 400px;">
            <div class="pos-modal-header">
                <div style="font-weight: 800; font-size: 18px;">🔓 Open Sales Session</div>
            </div>
            <form action="api.php?action=open_session" method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="pos-modal-body">
                    <?php if ($error): ?>
                        <div style="background: #fee2e2; color: #ef4444; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                            ⚠️ <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    <p style="color: #94a3b8; font-size: 14px; margin-bottom: 20px;">Enter the starting cash amount in the drawer to begin your shift.</p>
                    <?php if (!$bid): ?>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-size: 12px; color: #64748b; margin-bottom: 6px;">Select Branch *</label>
                            <select name="branch_id" style="width: 100%; background: #1f2937; border: 1px solid #1e293b; padding: 12px; border-radius: 8px; color: white; outline: none;" required>
                                <option value="">— Choose Branch —</option>
                                <?php foreach($branches as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 12px; color: #64748b; margin-bottom: 6px;">Opening Cash (<?= $tenantData['currency_symbol'] ?>)</label>
                        <input type="number" name="opening_cash" step="0.01" value="0.00" style="width: 100%; background: #1f2937; border: 1px solid #1e293b; padding: 12px; border-radius: 8px; color: white; outline: none;" required autofocus>
                    </div>
                </div>
                <div class="pos-modal-footer">
                    <a href="../client/dashboard.php" class="btn-outline" style="text-decoration: none; padding: 10px 16px; border-radius: 8px; font-size: 14px; border: 1px solid #1e293b; color: #94a3b8;">Back to Dashboard</a>
                    <button type="submit" class="checkout-btn" style="margin-top: 0; width: auto; padding: 10px 24px;">Start Shift</button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>

    <div class="pos-container">
        <!-- Main Product Area -->
        <main class="pos-main">
            <header class="pos-header">
                <div style="font-weight: 900; color: var(--pos-accent); font-size: 20px; display: flex; align-items: center; gap: 10px;">
                    <div style="width: 32px; height: 32px; background: var(--pos-accent); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white;">
                        <i data-lucide="shopping-cart" style="width: 18px;"></i>
                    </div>
                    POS
                </div>
                <div class="pos-search-box">
                    <i data-lucide="search"></i>
                    <input type="text" id="productSearch" placeholder="Search product name or scan barcode (F2)..." autocomplete="off">
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <div id="onlineStatus" style="width: 10px; height: 10px; border-radius: 50%; background: var(--pos-success);" title="Online"></div>
                    <button class="cat-btn" onclick="location.href='../client/dashboard.php'" title="Exit to Dashboard">
                        <i data-lucide="layout-dashboard" style="width: 16px; display: inline-block; vertical-align: middle;"></i>
                    </button>
                    <button class="cat-btn" onclick="openSessionDetails()" title="Session Info">
                        <i data-lucide="info" style="width: 16px; display: inline-block; vertical-align: middle;"></i>
                    </button>
                    <button class="cat-btn" style="color: var(--pos-danger);" onclick="closeSession()" title="Close Shift">
                        <i data-lucide="power" style="width: 16px; display: inline-block; vertical-align: middle;"></i>
                    </button>
                </div>
            </header>

            <div style="background: #1e293b; padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--pos-border);">
                <div style="display: flex; gap: 10px;">
                    <button class="cat-btn active" id="btnManualMode" onclick="setPosMode('manual')">🖱️ Manual Mode</button>
                    <button class="cat-btn" id="btnScannerMode" onclick="setPosMode('scanner')">🔍 Scanner Mode</button>
                </div>
                <div id="modeStatus" style="font-size: 12px; color: #94a3b8; font-weight: 600;">Currently in: MANUAL MODE</div>
            </div>

            <div class="pos-categories" id="categoryBar">
                <button class="cat-btn active" onclick="filterCategory(0, this)">All Items</button>
                <?php foreach ($categories as $cat): ?>
                    <button class="cat-btn" onclick="filterCategory(<?= $cat['id'] ?>, this)"><?= htmlspecialchars($cat['name']) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="pos-products-grid" id="productGrid">
                <!-- Products dynamically loaded here -->
            </div>
        </main>

        <!-- Cart Sidebar -->
        <aside class="pos-sidebar">
            <div class="cart-header">
                <div class="cart-title">Current Order</div>
                <button class="cat-btn" onclick="clearCart()" style="padding: 4px 8px; font-size: 11px; border-color: var(--pos-danger); color: var(--pos-danger);">Clear</button>
            </div>

            <div class="cart-items" id="cartList">
                <!-- Cart items here -->
            </div>

            <div class="cart-summary">
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="subtotal">0.00</span>
                </div>
                <div class="summary-row">
                    <span>Tax (<?= $tenantData['tax_rate'] ?>%)</span>
                    <span id="taxTotal">0.00</span>
                </div>
                <div class="summary-row" style="color: var(--pos-accent);">
                    <span>Discount</span>
                    <span id="discountTotal">0.00</span>
                </div>
                <div class="summary-total">
                    <span>Total</span>
                    <span id="grandTotal"><?= $tenantData['currency_symbol'] ?> 0.00</span>
                </div>
                <button class="checkout-btn" onclick="openCheckout()">Checkout (F10)</button>
            </div>
        </aside>
    </div>

    <!-- Checkout Modal -->
    <div class="pos-modal-overlay" id="checkoutModal">
        <div class="pos-modal">
            <div class="pos-modal-header">
                <div style="font-weight: 800; font-size: 18px;">💰 Complete Payment</div>
                <button onclick="closeModal('checkoutModal')" style="background: none; border: none; color: #94a3b8; cursor: pointer;">✕</button>
            </div>
            <div class="pos-modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <div style="font-size: 12px; color: #64748b; margin-bottom: 8px;">Total Payable</div>
                        <div style="font-size: 32px; font-weight: 900; color: white;" id="modalTotal"><?= $tenantData['currency_symbol'] ?> 0.00</div>
                        
                        <div style="margin-top: 24px;">
                            <label style="display: block; font-size: 12px; color: #64748b; margin-bottom: 6px;">Payment Method</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <button class="cat-btn pay-method active" onclick="setPayMethod('cash', this)">Cash</button>
                                <button class="cat-btn pay-method" onclick="setPayMethod('mpesa', this)">M-Pesa</button>
                                <button class="cat-btn pay-method" onclick="setPayMethod('card', this)">Card</button>
                                <button class="cat-btn pay-method" onclick="setPayMethod('credit', this)">Credit</button>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-size: 12px; color: #64748b; margin-bottom: 6px;">Amount Tendered</label>
                            <input type="number" id="amountTendered" step="0.01" style="width: 100%; background: #1f2937; border: 1px solid #1e293b; padding: 15px; border-radius: 8px; color: white; font-size: 24px; font-weight: 800; outline: none;">
                        </div>
                        <div style="padding: 15px; background: #0f172a; border-radius: 12px; border: 1px solid #1e293b;">
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Change Due</div>
                            <div style="font-size: 24px; font-weight: 900; color: var(--pos-warning);" id="changeDue"><?= $tenantData['currency_symbol'] ?> 0.00</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pos-modal-footer">
                <button class="checkout-btn" style="margin-top: 0;" onclick="processSale()">Confirm Sale</button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="pos-modal-overlay" id="successModal">
        <div class="pos-modal" style="max-width: 400px; text-align: center;">
            <div class="pos-modal-body" style="padding: 40px;">
                <div style="width: 80px; height: 80px; background: var(--pos-success); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin: 0 auto 20px;">
                    <i data-lucide="check" style="width: 40px; height: 40px;"></i>
                </div>
                <div style="font-size: 24px; font-weight: 800; color: white; margin-bottom: 10px;">Sale Complete!</div>
                <p style="color: #94a3b8; font-size: 14px; margin-bottom: 30px;" id="successMsg">Transaction successful.</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <button class="checkout-btn" style="margin-top: 0; background: #1e293b;" onclick="closeModal('successModal')">New Sale</button>
                    <button class="checkout-btn" style="margin-top: 0;" onclick="printReceipt()">Print Receipt</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scanner Info Pop-up -->
    <div class="pos-modal-overlay" id="scannerPopup">
        <div class="pos-modal" style="max-width: 500px; border: 4px solid var(--pos-accent);">
            <div class="pos-modal-body" style="padding: 0;">
                <div id="scanProductImg" style="height: 180px; background: #1e293b; display: flex; align-items: center; justify-content: center; font-size: 64px;">📦</div>
                <div style="padding: 24px;">
                    <div id="scanProductName" style="font-size: 28px; font-weight: 900; color: white; margin-bottom: 5px;">Product Name</div>
                    <div id="scanProductSKU" style="font-size: 14px; color: #94a3b8; margin-bottom: 20px;">SKU: 0000000</div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #0f172a; padding: 20px; border-radius: 12px; border: 1px solid var(--pos-border);">
                        <div>
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">Selling Price</div>
                            <div id="scanProductPrice" style="font-size: 24px; font-weight: 900; color: var(--pos-success);"><?= $tenantData['currency_symbol'] ?> 0.00</div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">VAT / Tax</div>
                            <div id="scanProductTax" style="font-size: 24px; font-weight: 900; color: #94a3b8;">0.00</div>
                        </div>
                    </div>
                </div>
                <div style="padding: 15px; text-align: center; background: var(--pos-accent); color: white; font-weight: 800; font-size: 14px;">
                    AUTO-ADDED TO CART
                </div>
            </div>
        </div>
    </div>

    <script>
        // Data from PHP
        const CURRENCY = '<?= $tenantData['currency_symbol'] ?>';
        const TAX_RATE = <?= $tenantData['tax_rate'] ?>;
        const APP_URL = '<?= APP_URL ?>';
        let products = <?= json_encode($initialProducts) ?>;
        let cart = [];
        let currentPayMethod = 'cash';
        let posMode = 'manual'; // manual or scanner

        // Initialize Lucide icons
        lucide.createIcons();

        // Render Products
        function renderProducts(items) {
            const grid = document.getElementById('productGrid');
            grid.innerHTML = items.map(p => `
                <div class="product-card" onclick="addToCart(${p.id})">
                    <div class="product-stock">${p.track_stock ? parseFloat(p.quantity || 0).toFixed(0) : '∞'}</div>
                    <div class="product-img">
                        ${p.image 
                            ? `<img src="${APP_URL}/uploads/products/${p.image}" style="width:100%;height:100%;object-fit:cover;">` 
                            : (p.category_name === 'Pharmacy' ? '💊' : (p.category_name === 'Restaurant' ? '🍕' : '📦'))
                        }
                    </div>
                    <div class="product-info">
                        <div class="product-name" title="${p.name}">${p.name}</div>
                        <div class="product-price">${CURRENCY} ${parseFloat(p.selling_price).toLocaleString()}</div>
                    </div>
                </div>
            `).join('');
        }

        // Cart Logic
        function addToCart(productId) {
            const p = products.find(x => x.id == productId);
            if (!p) return;

            const existing = cart.find(x => x.id == productId);
            if (existing) {
                existing.qty++;
            } else {
                cart.push({ ...p, qty: 1 });
            }
            updateCartUI();
        }

        function updateCartUI() {
            const list = document.getElementById('cartList');
            list.innerHTML = cart.map((item, index) => `
                <div class="cart-item">
                    <div class="cart-item-info">
                        <div class="cart-item-name">${item.name}</div>
                        <div class="cart-item-price">${CURRENCY} ${parseFloat(item.selling_price).toLocaleString()}</div>
                        <div class="cart-qty-ctrl">
                            <button class="qty-btn" onclick="changeQty(${index}, -1)">-</button>
                            <span style="font-weight: 700; font-size: 14px;">${item.qty}</span>
                            <button class="qty-btn" onclick="changeQty(${index}, 1)">+</button>
                        </div>
                    </div>
                    <div class="cart-item-total">
                        ${CURRENCY} ${(item.qty * item.selling_price).toLocaleString()}
                    </div>
                </div>
            `).join('');

            calculateTotals();
        }

        function changeQty(index, delta) {
            cart[index].qty += delta;
            if (cart[index].qty <= 0) cart.splice(index, 1);
            updateCartUI();
        }

        function calculateTotals() {
            let subtotal = cart.reduce((sum, item) => sum + (item.qty * item.selling_price), 0);
            let tax = subtotal * (TAX_RATE / 100);
            let total = subtotal + tax;

            document.getElementById('subtotal').innerText = subtotal.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('taxTotal').innerText = tax.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('grandTotal').innerText = CURRENCY + ' ' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
            
            // Modal updates
            document.getElementById('modalTotal').innerText = CURRENCY + ' ' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('amountTendered').value = total.toFixed(2);
            updateChange();
        }

        function updateChange() {
            let total = cart.reduce((sum, item) => sum + (item.qty * item.selling_price), 0) * (1 + TAX_RATE/100);
            let tendered = parseFloat(document.getElementById('amountTendered').value) || 0;
            let change = tendered - total;
            document.getElementById('changeDue').innerText = CURRENCY + ' ' + (change > 0 ? change.toLocaleString(undefined, {minimumFractionDigits: 2}) : '0.00');
        }

        document.getElementById('amountTendered').oninput = updateChange;

        // Search & Filter
        let scanPopupTimeout;
        document.getElementById('productSearch').oninput = function(e) {
            const q = e.target.value.toLowerCase();
            
            if (posMode === 'manual') {
                const filtered = products.filter(p => 
                    p.name.toLowerCase().includes(q) || 
                    (p.barcode && p.barcode.includes(q)) ||
                    (p.sku && p.sku.toLowerCase().includes(q))
                );
                renderProducts(filtered);

                // Auto-add if exact barcode match
                if (filtered.length === 1 && (filtered[0].barcode === q)) {
                    addToCart(filtered[0].id);
                    e.target.value = '';
                    renderProducts(products);
                }
            } else {
                // Scanner Mode specific logic
                const p = products.find(x => x.barcode === q || (x.sku && x.sku.toLowerCase() === q));
                if (p) {
                    showScannerPopup(p);
                    addToCart(p.id);
                    e.target.value = '';
                }
            }
        };

        function showScannerPopup(p) {
            clearTimeout(scanPopupTimeout);
            
            const imgDiv = document.getElementById('scanProductImg');
            if (p.image) {
                imgDiv.innerHTML = `<img src="${APP_URL}/uploads/products/${p.image}" style="width:100%;height:100%;object-fit:contain;">`;
            } else {
                imgDiv.innerText = (p.category_name === 'Pharmacy' ? '💊' : (p.category_name === 'Restaurant' ? '🍕' : '📦'));
            }

            document.getElementById('scanProductName').innerText = p.name;
            document.getElementById('scanProductSKU').innerText = 'SKU: ' + (p.sku || 'N/A');
            document.getElementById('scanProductPrice').innerText = CURRENCY + ' ' + parseFloat(p.selling_price).toLocaleString();
            
            const tax = p.selling_price * (TAX_RATE / 100);
            document.getElementById('scanProductTax').innerText = CURRENCY + ' ' + tax.toLocaleString(undefined, {minimumFractionDigits: 2});
            
            const overlay = document.getElementById('scannerPopup');
            overlay.style.display = 'flex';
            
            // Auto-hide after 2 seconds
            scanPopupTimeout = setTimeout(() => {
                overlay.style.display = 'none';
            }, 2000);
        }

        function setPosMode(mode) {
            posMode = mode;
            document.getElementById('btnManualMode').classList.toggle('active', mode === 'manual');
            document.getElementById('btnScannerMode').classList.toggle('active', mode === 'scanner');
            document.getElementById('modeStatus').innerText = 'Currently in: ' + mode.toUpperCase() + ' MODE';
            
            const grid = document.getElementById('productGrid');
            const cats = document.getElementById('categoryBar');
            
            if (mode === 'scanner') {
                grid.style.display = 'none';
                cats.style.display = 'none';
                document.getElementById('productSearch').placeholder = "READY FOR SCANNING... (Scan Barcode)";
                document.getElementById('productSearch').focus();
            } else {
                grid.style.display = 'grid';
                cats.style.display = 'flex';
                document.getElementById('productSearch').placeholder = "Search product name or scan barcode (F2)...";
                renderProducts(products);
            }
        }

        function filterCategory(catId, btn) {
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            if (catId == 0) {
                renderProducts(products);
            } else {
                renderProducts(products.filter(p => p.category_id == catId));
            }
        }

        // Checkout & API
        function openCheckout() {
            if (cart.length === 0) return;
            document.getElementById('checkoutModal').style.display = 'flex';
            document.getElementById('amountTendered').focus();
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function setPayMethod(method, btn) {
            currentPayMethod = method;
            document.querySelectorAll('.pay-method').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        }

        let lastSaleId = null;
        let isOnline = navigator.onLine;

        // PWA & DB Init
        window.addEventListener('load', async () => {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('../../sw.js').then(() => console.log('SW Registered'));
            }
            await initDB();
            
            if (isOnline) {
                await saveProductsLocal(products);
                syncOfflineSales();
            } else {
                products = await getProductsLocal();
                renderProducts(products);
                updateStatusUI();
            }
        });

        window.addEventListener('online', () => { isOnline = true; updateStatusUI(); syncOfflineSales(); });
        window.addEventListener('offline', () => { isOnline = false; updateStatusUI(); });

        function updateStatusUI() {
            const el = document.getElementById('onlineStatus');
            el.style.background = isOnline ? 'var(--pos-success)' : 'var(--pos-danger)';
            el.title = isOnline ? 'Online' : 'Offline Mode';
        }

        async function processSale() {
            if (cart.length === 0) return;
            
            const btn = event.target;
            btn.disabled = true;
            btn.innerText = 'Processing...';

            const payload = {
                cart: cart.map(i => ({ id: i.id, qty: i.qty, price: i.selling_price, name: i.name })),
                payment_method: currentPayMethod,
                amount_tendered: document.getElementById('amountTendered').value,
                total: cart.reduce((sum, item) => sum + (item.qty * item.selling_price), 0) * (1 + TAX_RATE/100),
                csrf_token: '<?= csrfToken() ?>'
            };

            if (!isOnline) {
                // Save Offline
                await saveSaleOffline(payload);
                closeModal('checkoutModal');
                document.getElementById('successModal').style.display = 'flex';
                document.getElementById('successMsg').innerText = "Sale saved locally (Offline). It will sync when connection returns.";
                btn.disabled = false;
                btn.innerText = 'Confirm Sale';
                cart = [];
                updateCartUI();
                return;
            }

            fetch('api.php?action=process_sale', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    lastSaleId = data.sale_id;
                    closeModal('checkoutModal');
                    document.getElementById('successModal').style.display = 'flex';
                    document.getElementById('successMsg').innerText = `Receipt #${data.sale_number} generated.`;
                    cart = [];
                    updateCartUI();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerText = 'Confirm Sale';
            });
        }

        async function syncOfflineSales() {
            const pending = await getPendingSales();
            if (pending.length === 0) return;

            console.log(`Syncing ${pending.length} offline sales...`);
            for (const sale of pending) {
                try {
                    const res = await fetch('api.php?action=process_sale', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(sale)
                    });
                    const data = await res.json();
                    if (data.success) {
                        deletePendingSale(sale.temp_id);
                    }
                } catch (err) {
                    console.error("Sync failed for sale", sale, err);
                    break; // Stop if network fails again
                }
            }
        }

        function clearCart() {
            if (confirm('Clear current order?')) {
                cart = [];
                updateCartUI();
            }
        }

        function closeSession() {
            if (confirm('Are you sure you want to close your shift?')) {
                location.href = 'api.php?action=close_session&csrf_token=<?= csrfToken() ?>';
            }
        }

        function printReceipt() {
            if (!lastSaleId) return;
            window.open('receipt.php?sale_id=' + lastSaleId, 'receipt', 'width=400,height=600');
        }

        // Keyboard Shortcuts
        window.onkeydown = function(e) {
            if (e.key === 'F10') { e.preventDefault(); openCheckout(); }
            if (e.key === 'F2') { e.preventDefault(); document.getElementById('productSearch').focus(); }
            if (e.key === 'Escape') { 
                closeModal('checkoutModal');
                closeModal('successModal');
            }
        };

        // Initial Load
        renderProducts(products);
    </script>
<?php endif; ?>

</body>
</html>
