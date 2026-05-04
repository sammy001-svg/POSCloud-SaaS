<?php
require_once __DIR__ . '/../../config/auth.php';
requireRole(ROLE_OWNER, ROLE_MANAGER, ROLE_CASHIER);

$db = getDB();
$tid = currentUser()['tenant_id'];
$bid = currentUser()['branch_id'];
$uid = currentUser()['id'];

$action = $_GET['action'] ?? '';

// --- 1. Open Session ---
if ($action === 'open_session') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        header('Location: index.php?error=Security+token+expired.+Please+refresh.');
        exit;
    }
    
    $opening = (float)($_POST['opening_cash'] ?? 0);
    $selectedBid = (int)($_POST['branch_id'] ?? $bid);

    if (empty($selectedBid)) {
        header('Location: index.php?error=Branch+selection+is+required');
        exit;
    }
    
    // Check if already has open session
    $check = $db->prepare("SELECT id FROM sales_sessions WHERE tenant_id=? AND branch_id=? AND user_id=? AND status='open'");
    $check->execute([$tid, $selectedBid, $uid]);
    if ($check->fetch()) {
        header('Location: index.php'); exit;
    }

    $db->prepare("INSERT INTO sales_sessions (tenant_id, branch_id, user_id, opening_cash, status) VALUES (?,?,?,?,'open')")
       ->execute([$tid, $selectedBid, $uid, $opening]);
    
    // Update session branch_id for the duration of this POS shift if user is a floater (Owner/Manager)
    if (empty($_SESSION['branch_id'])) {
        $_SESSION['branch_id'] = $selectedBid;
    }
    
    header('Location: index.php');
    exit;
}

// --- 2. Process Sale ---
if ($action === 'process_sale') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { echo json_encode(['success' => false, 'message' => 'Invalid input']); exit; }

    // CSRF check (passed in JSON body)
    if (!verifyCsrf($input['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF mismatch']); exit;
    }

    $cart = $input['cart'];
    $payMethod = $input['payment_method'];
    $tendered = (float)$input['amount_tendered'];
    $totalAmount = (float)$input['total'];

    // Get active session
    $session = $db->prepare("SELECT id FROM sales_sessions WHERE tenant_id=? AND branch_id=? AND user_id=? AND status='open' LIMIT 1");
    $session->execute([$tid, $bid, $uid]);
    $activeSession = $session->fetch();
    if (!$activeSession) {
        echo json_encode(['success' => false, 'message' => 'No active session. Please restart POS.']); exit;
    }
    $sid = $activeSession['id'];

    $db->beginTransaction();
    try {
        // Generate Sale Number
        $saleNumber = 'SALE-' . strtoupper(substr(md5(uniqid()), 0, 8));

        // Subtotal calculation (before tax)
        // For simplicity, we assume the total from frontend is correct for now, but we should recalculate
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['qty'] * $item['price'];
        }
        
        $tenant = $db->prepare("SELECT tax_rate FROM tenants WHERE id=?");
        $tenant->execute([$tid]);
        $taxRate = (float)$tenant->fetchColumn();
        $taxAmount = $subtotal * ($taxRate / 100);
        $grandTotal = $subtotal + $taxAmount;

        // 1. Insert into Sales
        $stmt = $db->prepare("
            INSERT INTO sales (tenant_id, branch_id, session_id, sale_number, user_id, subtotal, tax_amount, total_amount, amount_paid, change_given, sale_type, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sale', 'completed')
        ");
        $change = max(0, $tendered - $grandTotal);
        $stmt->execute([$tid, $bid, $sid, $saleNumber, $uid, $subtotal, $taxAmount, $grandTotal, $tendered, $change]);
        $saleId = $db->lastInsertId();

        // 2. Insert Sale Items & Update Stock
        $itemStmt = $db->prepare("
            INSERT INTO sale_items (sale_id, product_id, product_name, sku, quantity, unit_price, tax_rate, tax_amount, total_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stockStmt = $db->prepare("
            UPDATE stock_levels 
            SET quantity = quantity - ? 
            WHERE tenant_id=? AND branch_id=? AND product_id=?
        ");

        foreach ($cart as $item) {
            // Fetch latest product info
            $prod = $db->prepare("SELECT name, sku, track_stock FROM products WHERE id=?");
            $prod->execute([$item['id']]);
            $pData = $prod->fetch();

            $iTotal = $item['qty'] * $item['price'];
            $iTax = $iTotal * ($taxRate / 100);
            
            $itemStmt->execute([
                $saleId, $item['id'], $pData['name'], $pData['sku'], 
                $item['qty'], $item['price'], $taxRate, $iTax, ($iTotal + $iTax)
            ]);

            // Stock update
            if ($pData['track_stock']) {
                $stockStmt->execute([$item['qty'], $tid, $bid, $item['id']]);
            }

            // Movement log
            $db->prepare("INSERT INTO stock_movements (tenant_id, branch_id, product_id, movement_type, quantity, reference_id, reference_type, user_id) VALUES (?,?,?, 'sale', ?, ?, 'sale', ?)")
               ->execute([$tid, $bid, $item['id'], -$item['qty'], $saleId, $uid]);
        }

        // 3. Insert Payment
        $db->prepare("INSERT INTO sale_payments (sale_id, payment_method, amount) VALUES (?, ?, ?)")
           ->execute([$saleId, $payMethod, $grandTotal]);

        // 4. Update Session Stats
        $db->prepare("UPDATE sales_sessions SET total_sales = total_sales + ?, total_transactions = total_transactions + 1 WHERE id=?")
           ->execute([$grandTotal, $sid]);

        $db->commit();
        echo json_encode(['success' => true, 'sale_number' => $saleNumber, 'sale_id' => $saleId]);

    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- 3. Close Session ---
if ($action === 'close_session') {
    // Get active session
    $session = $db->prepare("SELECT * FROM sales_sessions WHERE tenant_id=? AND branch_id=? AND user_id=? AND status='open' LIMIT 1");
    $session->execute([$tid, $bid, $uid]);
    $s = $session->fetch();
    
    if ($s) {
        // Calculate expected cash
        // expected = opening + cash_sales - cash_refunds... (simple version)
        $cashSales = $db->prepare("SELECT SUM(sp.amount) FROM sale_payments sp JOIN sales s ON s.id=sp.sale_id WHERE s.session_id=? AND sp.payment_method='cash'");
        $cashSales->execute([$s['id']]);
        $totalCash = (float)$cashSales->fetchColumn();
        
        $expected = $s['opening_cash'] + $totalCash;
        
        $db->prepare("UPDATE sales_sessions SET status='closed', closed_at=NOW(), expected_cash=?, closing_cash=? WHERE id=?")
           ->execute([$expected, $expected, $s['id']]);
    }

    header('Location: ../client/dashboard.php');
    exit;
}
