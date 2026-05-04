<?php
require_once __DIR__ . '/config/auth.php';

if (isLoggedIn()) {
    header('Location: ' . getRoleRedirect(currentUser()['role']));
    exit;
}

$db = getDB();
$error = '';
$success = '';

// Fetch all plans
$all_plans = $db->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price ASC")->fetchAll();
$client_plans = array_filter($all_plans, fn($p) => $p['plan_type'] === 'client');
$reseller_plans = array_filter($all_plans, fn($p) => $p['plan_type'] === 'reseller');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $type     = $_POST['account_type'] ?? 'business';
        $company  = trim($_POST['company_name'] ?? '');
        $name     = trim($_POST['full_name'] ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $plan_id  = (int)($_POST['plan_id'] ?? 0);

        if (empty($company)) {
            $error = 'Business / Agency name is required.';
        } elseif (empty($name)) {
            $error = 'Full name is required.';
        } elseif (empty($email)) {
            $error = 'Email address is required.';
        } elseif (empty($password)) {
            $error = 'Password is required.';
        } elseif (empty($plan_id)) {
            $error = 'Please select a subscription plan.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            // Check if email exists in either table
            $check1 = $db->prepare("SELECT id FROM tenant_users WHERE email = ? LIMIT 1");
            $check1->execute([$email]);
            $check2 = $db->prepare("SELECT id FROM resellers WHERE email = ? LIMIT 1");
            $check2->execute([$email]);
            
            if ($check1->fetch() || $check2->fetch()) {
                $error = 'Email already registered.';
            } else {
                try {
                    $db->beginTransaction();
                    $hash = hashPassword($password);

                    if ($type === 'reseller') {
                        // Create Reseller
                        $uuid = bin2hex(random_bytes(16)); // Simplified UUID
                        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $company), '-'));
                        
                        $stmt = $db->prepare("INSERT INTO resellers (uuid, company_name, contact_name, email, phone, password, slug, plan_id, subscription_status, trial_ends_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'trial', DATE_ADD(NOW(), INTERVAL 14 DAY))");
                        $stmt->execute([$uuid, $company, $name, $email, $phone, $hash, $slug, $plan_id]);
                    } else {
                        // Create Tenant
                        $uuid = bin2hex(random_bytes(16));
                        $stmt = $db->prepare("INSERT INTO tenants (uuid, business_name, phone, contact_name, email, plan_id, subscription_status, trial_ends_at) VALUES (?, ?, ?, ?, ?, ?, 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY))");
                        $stmt->execute([$uuid, $company, $phone, $name, $email, $plan_id]);
                        $tid = $db->lastInsertId();

                        // Create Owner User
                        $stmt = $db->prepare("INSERT INTO tenant_users (tenant_id, full_name, email, password, role) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$tid, $name, $email, $hash, ROLE_OWNER]);

                        // Create Default Branch
                        $stmt = $db->prepare("INSERT INTO tenant_branches (tenant_id, branch_name, is_active) VALUES (?, 'Main Branch', 1)");
                        $stmt->execute([$tid]);
                    }

                    $db->commit();
                    
                    // Return data for payment modal
                    $plan = $db->prepare("SELECT * FROM subscription_plans WHERE id = ?");
                    $plan->execute([$plan_id]);
                    $planData = $plan->fetch();

                    echo json_encode([
                        'status' => 'success',
                        'phone' => $phone,
                        'amount' => $planData['price'],
                        'plan_name' => $planData['plan_name']
                    ]);
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                    exit;
                }
            }
        }
    }
    echo json_encode(['status' => 'error', 'message' => $error ?: 'Validation failed.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Your Account — POSCloud</title>
  <link rel="stylesheet" href="assets/css/main.css"/>
  <style>
    body { background: var(--bg-dark); color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
    .reg-card { width: 100%; max-width: 900px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 20px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
    .reg-header { padding: 40px; text-align: center; background: linear-gradient(to bottom, rgba(16,185,129,0.1), transparent); border-bottom: 1px solid var(--border); }
    .reg-body { padding: 40px; }
    
    .type-tabs { display: flex; gap: 10px; margin-bottom: 30px; background: var(--bg-dark); padding: 5px; border-radius: 12px; border: 1px solid var(--border); }
    .type-tab { flex: 1; padding: 12px; text-align: center; border-radius: 8px; cursor: pointer; font-weight: 700; transition: all 0.3s; color: var(--text-muted); }
    .type-tab.active { background: var(--primary); color: white; }

    .plan-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px; }
    .plan-card { 
      background: var(--bg-dark); border: 2px solid var(--border); border-radius: 16px; padding: 24px; cursor: pointer; transition: all 0.3s;
      position: relative; display: flex; flex-direction: column; align-items: center; text-align: center;
    }
    .plan-card:hover { border-color: var(--primary); transform: translateY(-5px); }
    .plan-card.active { border-color: var(--primary); background: rgba(16,185,129,0.05); }
    .plan-card.active::after {
      content: '✓ Selected'; position: absolute; top: -12px; background: var(--primary); color: white;
      padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 800;
    }
    
    .plan-name { font-size: 18px; font-weight: 800; margin-bottom: 8px; }
    .plan-price { font-size: 24px; font-weight: 900; color: var(--primary); margin-bottom: 15px; }
    .plan-features { font-size: 12px; color: var(--text-muted); list-style: none; padding: 0; margin: 0; }
    .plan-features li { margin-bottom: 5px; }

    .form-section-title { font-size: 14px; font-weight: 800; text-transform: uppercase; color: var(--primary); letter-spacing: 1px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .form-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    .btn-reg { width: 100%; padding: 16px; background: var(--primary); color: white; border: none; border-radius: 12px; font-size: 16px; font-weight: 800; cursor: pointer; transition: all 0.3s; margin-top: 20px; }
    .btn-reg:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 10px 20px rgba(16,185,129,0.2); }
    
    input[type="radio"] { display: none; }

    /* M-Pesa Modal Style */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(10px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
    .modal-content { background: var(--bg-card); border: 1px solid var(--border); width: 100%; max-width: 450px; border-radius: 24px; overflow: hidden; position: relative; animation: modalPop 0.3s ease-out; }
    @keyframes modalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .mpesa-header { background: #10b981; padding: 30px; text-align: center; color: white; }
    .mpesa-body { padding: 30px; text-align: center; }
    .mpesa-logo { width: 120px; margin-bottom: 20px; }
    .loader { border: 4px solid rgba(255,255,255,0.1); border-top: 4px solid white; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div class="reg-card">
    <div class="reg-header">
      <div style="font-size: 32px; font-weight: 900; margin-bottom: 10px;">Join the POSCloud Network</div>
      <p style="color: var(--text-secondary); margin: 0;">Select your account type and start your journey today.</p>
    </div>

    <form id="regForm" class="reg-body">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
      <input type="hidden" name="account_type" id="accountTypeInput" value="business"/>
      
      <div class="form-section-title">1. Who are you?</div>
      <div class="type-tabs">
        <div class="type-tab active" onclick="setType('business', event)">🏢 Business Owner</div>
        <div class="type-tab" onclick="setType('reseller', event)">🤝 Partner Reseller</div>
      </div>

      <div class="form-section-title">2. Select Your Plan</div>
      
      <div class="plan-grid" id="businessPlans">
        <?php foreach ($client_plans as $plan): ?>
          <label class="plan-card" id="plan-label-<?= $plan['id'] ?>">
            <input type="radio" name="plan_id" value="<?= $plan['id'] ?>" onchange="selectPlan(<?= $plan['id'] ?>)">
            <div class="plan-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
            <div class="plan-price"><?= number_format($plan['price']) ?> <span style="font-size:12px; color:var(--text-muted);">/mo</span></div>
            <ul class="plan-features">
              <li>Up to <?= $plan['max_branches'] ?: 'Unlimited' ?> Branches</li>
              <li><?= $plan['max_terminals'] ?: 'Unlimited' ?> Terminals</li>
              <li><?= $plan['max_users'] ?: 'Unlimited' ?> Staff Users</li>
              <li><?= $plan['max_products'] ?: 'Unlimited' ?> Products</li>
            </ul>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="plan-grid" id="resellerPlans" style="display: none;">
        <?php foreach ($reseller_plans as $plan): ?>
          <label class="plan-card" id="plan-label-<?= $plan['id'] ?>">
            <input type="radio" name="plan_id" value="<?= $plan['id'] ?>" onchange="selectPlan(<?= $plan['id'] ?>)">
            <div class="plan-name"><?= htmlspecialchars($plan['plan_name']) ?></div>
            <div class="plan-price"><?= number_format($plan['price']) ?> <span style="font-size:12px; color:var(--text-muted);">/mo</span></div>
            <ul class="plan-features">
              <li>White-label Dashboard</li>
              <li>Commission: <?= $plan['features']['commission'] ?? '10%' ?></li>
              <li>Max <?= $plan['max_users'] ?: 'Unlimited' ?> Clients</li>
              <li>24/7 Priority Support</li>
            </ul>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="form-section-title">3. Registration Details</div>
      <div class="grid-2" style="margin-bottom: 30px;">
        <div class="form-group">
          <label class="form-label" id="companyLabel">Company / Business Name</label>
          <input type="text" name="company_name" class="form-control" placeholder="e.g. Acme Retailers" required>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number (For M-Pesa)</label>
          <input type="text" name="phone" id="phoneInput" class="form-control" placeholder="e.g. 254700000000" required>
        </div>
        <div class="form-group">
          <label class="form-label">Full Name / Contact Person</label>
          <input type="text" name="full_name" class="form-control" placeholder="John Doe" required>
        </div>
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
        </div>
        <div class="form-group" style="grid-column: span 2;">
          <label class="form-label">Choose Password</label>
          <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required>
        </div>
      </div>

      <div id="formError" class="alert alert-danger" style="display:none; margin-top: 20px;"></div>

      <button type="submit" class="btn-reg" id="regBtn">Create My Account & Pay</button>
      
      <div style="text-align: center; margin-top: 20px; font-size: 14px; color: var(--text-secondary);">
        Already have an account? <a href="index.php" style="color: var(--primary); font-weight: 700;">Login here</a>
      </div>
    </form>
  </div>

  <!-- M-Pesa Payment Modal -->
  <div class="modal-overlay" id="paymentModal">
    <div class="modal-content">
      <div class="mpesa-header">
        <div style="font-weight: 900; font-size: 20px;">M-PESA PAYMENT</div>
        <div style="font-size: 12px; opacity: 0.8;">STK PUSH INITIATED</div>
      </div>
      <div class="mpesa-body">
        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/1/15/M-PESA_LOGO-01.svg/512px-M-PESA_LOGO-01.svg.png" class="mpesa-logo" alt="M-Pesa">
        <div style="font-size: 16px; margin-bottom: 5px;">Paying for: <strong id="payPlanName">Plan Name</strong></div>
        <div style="font-size: 28px; font-weight: 900; color: #10b981; margin-bottom: 20px;">KES <span id="payAmount">0.00</span></div>
        
        <div id="paymentStatus">
          <div class="loader" style="border-top-color: #10b981;"></div>
          <p style="color: var(--text-primary); font-weight: 600;">Check your phone and enter M-Pesa PIN</p>
          <p style="font-size: 12px; color: var(--text-muted); margin-top: 10px;">We are waiting for payment confirmation from Safaricom...</p>
        </div>

        <div id="paymentSuccess" style="display: none;">
          <div style="width: 60px; height: 60px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; margin: 0 auto 20px; font-size: 30px;">✓</div>
          <h3 style="color: white; margin-bottom: 10px;">Payment Received!</h3>
          <p style="color: var(--text-secondary); margin-bottom: 20px;">Your account is now active. Welcome to POSCloud!</p>
          <a href="index.php" class="btn btn-primary" style="display: block;">Proceed to Dashboard</a>
        </div>
      </div>
    </div>
  </div>

  <script>
    function setType(type, event) {
      document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
      event.currentTarget.classList.add('active');
      document.getElementById('accountTypeInput').value = type;
      
      const bPlans = document.getElementById('businessPlans');
      const rPlans = document.getElementById('resellerPlans');
      const btn = document.getElementById('regBtn');
      const compLabel = document.getElementById('companyLabel');

      document.querySelectorAll('.plan-card input').forEach(i => i.checked = false);
      document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('active'));

      if (type === 'reseller') {
        bPlans.style.display = 'none';
        rPlans.style.display = 'grid';
        btn.innerHTML = 'Create My Partner Account & Pay';
        compLabel.innerHTML = 'Agency / Company Name';
      } else {
        bPlans.style.display = 'grid';
        rPlans.style.display = 'none';
        btn.innerHTML = 'Create My Business Account & Pay';
        compLabel.innerHTML = 'Company / Business Name';
      }
    }

    function selectPlan(id) {
      document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('active'));
      const label = document.getElementById('plan-label-' + id);
      if (label) {
        label.classList.add('active');
        label.querySelector('input').checked = true;
      }
    }

    // Auto-select first plan on load
    window.onload = () => {
      const firstPlan = document.querySelector('.plan-grid input');
      if (firstPlan) {
        firstPlan.checked = true;
        firstPlan.closest('.plan-card').classList.add('active');
      }
    };

    document.getElementById('regForm').onsubmit = async function(e) {
      e.preventDefault();
      const btn = document.getElementById('regBtn');
      const err = document.getElementById('formError');
      btn.disabled = true;
      btn.innerHTML = 'Processing Registration...';
      err.style.display = 'none';

      try {
        const formData = new FormData(this);
        const res = await fetch('register.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.status === 'success') {
          showPaymentModal(data);
        } else {
          err.innerText = data.message;
          err.style.display = 'block';
          btn.disabled = false;
          btn.innerHTML = 'Create My Account & Pay';
        }
      } catch (ex) {
        err.innerText = 'Connection error. Please try again.';
        err.style.display = 'block';
        btn.disabled = false;
      }
    };

    function showPaymentModal(data) {
      document.getElementById('payAmount').innerText = parseFloat(data.amount).toLocaleString();
      document.getElementById('payPlanName').innerText = data.plan_name;
      document.getElementById('paymentModal').style.display = 'flex';

      // Call M-Pesa API
      const mpesaData = new FormData();
      mpesaData.append('phone', data.phone);
      mpesaData.append('amount', data.amount);

      fetch('modules/payments/mpesa_stk.php', { method: 'POST', body: mpesaData })
        .then(r => r.json())
        .then(d => {
          // Simulate payment confirmation after 5 seconds
          setTimeout(() => {
            document.getElementById('paymentStatus').style.display = 'none';
            document.getElementById('paymentSuccess').style.display = 'block';
          }, 5000);
        });
    }
  </script>
</body>
</html>
</html>
