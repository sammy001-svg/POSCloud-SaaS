<?php
require_once __DIR__ . '/config/auth.php';

// Already logged in → redirect to their dashboard
if (isLoggedIn()) {
    header('Location: ' . getRoleRedirect(currentUser()['role']));
    exit;
}

$error = '';
$success = ($_GET['msg'] ?? '') === 'logged_out' ? 'You have been logged out.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $panel    = $_POST['panel'] ?? 'auto';

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            $db = getDB();
            $user = null;
            $role = null;

            // 1. Check Super Admin
            if ($panel === 'auto' || $panel === 'admin') {
                $stmt = $db->prepare("SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$email]);
                $row = $stmt->fetch();
                if ($row && verifyPassword($password, $row['password'])) {
                    $user = $row; $role = ROLE_SUPER_ADMIN;
                }
            }

            // 2. Check Reseller
            if (!$user && ($panel === 'auto' || $panel === 'reseller')) {
                $stmt = $db->prepare("SELECT * FROM resellers WHERE email = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([$email]);
                $row = $stmt->fetch();
                if ($row && verifyPassword($password, $row['password'])) {
                    $user = array_merge($row, [
                        'reseller_id' => $row['id'],
                        'tenant_id'   => null,
                        'branch_id'   => null,
                    ]);
                    $role = ROLE_RESELLER;
                }
            }

            // 3. Check Tenant User
            if (!$user && ($panel === 'auto' || $panel === 'client')) {
                $stmt = $db->prepare("
                    SELECT tu.*, t.subscription_status, t.is_active AS tenant_active
                    FROM tenant_users tu
                    JOIN tenants t ON t.id = tu.tenant_id
                    WHERE tu.email = ? AND tu.is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $row = $stmt->fetch();
                if ($row && verifyPassword($password, $row['password'])) {
                    if (!$row['tenant_active']) {
                        $error = 'Your business account has been suspended. Contact support.';
                    } elseif ($row['subscription_status'] === 'expired') {
                        $error = 'Subscription expired. Please renew to continue.';
                    } else {
                        $user = array_merge($row, ['reseller_id' => null]);
                        $role = $row['role'];
                    }
                }
            }

            if ($user && $role && !$error) {
                // Update last_login
                $table = match($role) {
                    ROLE_SUPER_ADMIN => 'admins',
                    ROLE_RESELLER    => 'resellers',
                    default          => 'tenant_users',
                };
                $db->prepare("UPDATE $table SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

                loginAs($user, $role);
                
                $redirect = $_GET['redirect'] ?? getRoleRedirect($role);
                if ($panel === 'pos') {
                    $redirect = 'modules/pos/index.php';
                }
                
                header('Location: ' . $redirect);
                exit;
            } elseif (!$error) {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sign In — POSCloud</title>
  <meta name="description" content="Sign in to POSCloud — your multi-tenant point of sale platform."/>
  <link rel="stylesheet" href="assets/css/main.css"/>
  <style>
    body { margin:0; padding:0; height:100vh; overflow:hidden; font-family:'Inter', sans-serif; background:var(--bg-dark); }
    
    .login-split-container { display:flex; height:100vh; width:100vw; }

    /* Left: Carousel Section */
    .login-left {
      flex: 1;
      position: relative;
      background: #000;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 60px;
    }
    @media (max-width: 992px) { .login-left { display: none; } }

    .carousel-container {
      position: absolute;
      inset: 0;
      z-index: 1;
    }
    .carousel-item {
      position: absolute;
      inset: 0;
      background-size: cover;
      background-position: center;
      opacity: 0;
      transition: opacity 1s ease-in-out;
    }
    .carousel-item.active { opacity: 0.6; }
    
    .carousel-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(to right, rgba(10,15,30,0.9), transparent);
      z-index: 2;
    }

    .marketing-content {
      position: relative;
      z-index: 3;
      max-width: 500px;
      color: white;
    }
    .marketing-title { font-size: 48px; font-weight: 900; line-height: 1.1; margin-bottom: 20px; }
    .marketing-text { font-size: 18px; color: rgba(255,255,255,0.7); margin-bottom: 40px; }
    
    /* Carousel Indicators */
    .carousel-indicators {
      position: absolute;
      bottom: 60px;
      left: 60px;
      display: flex;
      gap: 12px;
      z-index: 10;
    }
    .indicator {
      width: 40px; height: 4px; background: rgba(255,255,255,0.2);
      border-radius: 2px; cursor: pointer; transition: all 0.3s;
    }
    .indicator.active { background: var(--primary); width: 60px; }

    /* Right: Login Section */
    .login-right {
      width: 500px;
      background: var(--bg-dark);
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 60px;
      position: relative;
      z-index: 10;
      border-left: 1px solid var(--border);
    }
    @media (max-width: 992px) { .login-right { width: 100%; padding: 30px; } }

    .login-brand { display: flex; align-items: center; gap: 15px; margin-bottom: 40px; }
    .brand-icon {
      width: 48px; height: 48px; background: var(--primary);
      border-radius: 12px; display: flex; align-items: center; justify-content: center;
      font-size: 24px; color: white;
    }
    .brand-name { font-size: 24px; font-weight: 800; color: var(--text-primary); }

    .login-title { font-size: 32px; font-weight: 800; margin-bottom: 10px; color: white; }
    .login-subtitle { font-size: 14px; color: var(--text-secondary); margin-bottom: 32px; }

    /* Panel selector */
    .panel-tabs { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:24px; }
    .panel-tab {
      padding:10px; border-radius:var(--radius-sm); border:1px solid var(--border);
      background:var(--bg-card); color:var(--text-muted); text-align:center;
      font-size:11px; font-weight:600; cursor:pointer; transition:all var(--transition);
    }
    .panel-tab.active { background:var(--primary-glow); border-color:var(--primary); color:var(--primary); }
    .panel-tab .pt-icon { font-size:16px; display:block; margin-bottom:4px; }

    /* Submit btn */
    .btn-login {
      width:100%; padding:14px; border-radius:var(--radius-sm); font-size:15px; font-weight:700;
      background:var(--primary); color:#fff; border:none;
      cursor:pointer; transition:all var(--transition); margin-top: 10px;
    }
    .btn-login:hover { background: var(--primary-hover); transform:translateY(-1px); }

    .divider { text-align:center; color:var(--text-muted); font-size: 12px; margin:24px 0; position:relative; }
    .divider::before,.divider::after {
      content:''; position:absolute; top:50%; width:calc(50% - 40px);
      height:1px; background:var(--border);
    }
    .divider::before { left:0; } .divider::after { right:0; }

    .login-footer { margin-top: auto; font-size: 12px; color: var(--text-muted); display: flex; gap: 15px; }
    
    .orb {
      position:absolute; width: 200px; height: 200px; border-radius: 50%;
      background: var(--primary); filter: blur(100px); opacity: 0.15;
      top: 50%; left: 50%; transform: translate(-50%, -50%);
      pointer-events: none;
    }
  </style>
</head>
<body>

  <div class="login-split-container">
    <!-- Left Section: Carousel -->
    <div class="login-left">
      <div class="carousel-container">
        <div class="carousel-item active" style="background-image: url('assets/img/login_1.png')"></div>
        <div class="carousel-item" style="background-image: url('assets/img/login_2.png')"></div>
        <div class="carousel-item" style="background-image: url('assets/img/login_3.png')"></div>
      </div>
      <div class="carousel-overlay"></div>
      
      <div class="marketing-content" id="marketingContent">
        <div class="content-slide active">
          <h1 class="marketing-title">Empower Your Business with Advanced POS Cloud</h1>
          <p class="marketing-text">The all-in-one solution for retail, pharmacy, and restaurant management. Scalable, secure, and ready for growth.</p>
        </div>
      </div>

      <div class="carousel-indicators" id="indicators">
        <div class="indicator active" onclick="setSlide(0)"></div>
        <div class="indicator" onclick="setSlide(1)"></div>
        <div class="indicator" onclick="setSlide(2)"></div>
      </div>
    </div>

    <!-- Right Section: Login Form -->
    <div class="login-right">
      <div class="orb"></div>
      
      <div class="login-brand">
        <div class="brand-icon">🏪</div>
        <span class="brand-name">POSCloud</span>
      </div>

      <h2 class="login-title">Welcome Back</h2>
      <p class="login-subtitle">Please enter your credentials to access your dashboard.</p>

      <?php if ($error): ?>
        <div class="alert alert-danger" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; color: #fca5a5;">
          ⚠️ <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div style="margin-top: 24px; padding: 12px; background: rgba(16,185,129,0.05); border: 1px solid rgba(16,185,129,0.1); border-radius: 10px; display: flex; align-items: center; gap: 12px; cursor: pointer;" onclick="document.getElementById('posCheck').click()">
        <input type="checkbox" id="posCheck" onchange="togglePosMode(this)" style="width:18px;height:18px;accent-color:var(--success);">
        <div style="flex:1;">
          <div style="font-size: 13px; font-weight: 700; color: #fff;">Direct POS Access</div>
          <div style="font-size: 11px; color: var(--text-muted);">Go straight to the terminal after login</div>
        </div>
      </div>

      <form method="POST" action="index.php" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
        <input type="hidden" name="panel" id="panelInput" value="auto"/>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="you@example.com" required autocomplete="email"/>
        </div>

        <div class="form-group" style="margin-top: 15px;">
          <label class="form-label">
            Password
            <a href="modules/auth/forgot_password.php" style="float:right; font-size: 11px; color: var(--primary);">Forgot password?</a>
          </label>
          <input class="form-control" type="password" name="password" placeholder="••••••••" required autocomplete="current-password"/>
        </div>

        <button type="submit" class="btn-login" id="loginBtn">Sign In to Platform</button>
        <a href="register.php" class="btn btn-outline" style="display: block; text-align: center; margin-top: 10px; border-radius: var(--radius-sm); padding: 14px; font-weight: 700; border-color: rgba(255,255,255,0.1);">Create New Account</a>
      </form>

      <div class="login-footer">
        <span>&copy; <?= date('Y') ?> POSCloud</span>
        <a href="#" style="color: var(--text-muted);">Privacy</a>
        <a href="#" style="color: var(--text-muted);">Terms</a>
      </div>
    </div>
  </div>

  <script>
    let currentSlide = 0;
    const slides = [
      {
        title: "Empower Your Business with Advanced POS Cloud",
        text: "The all-in-one solution for retail, pharmacy, and restaurant management. Scalable, secure, and ready for growth."
      },
      {
        title: "Manage Multi-Branch Inventory in Real-Time",
        text: "Sync stock levels across all your locations instantly. Never run out of your best-selling products again."
      },
      {
        title: "Deep Analytics to Scale Your Revenue",
        text: "Track sales trends, customer behavior, and employee performance with our comprehensive reporting suite."
      }
    ];

    function setSlide(index) {
      currentSlide = index;
      const items = document.querySelectorAll('.carousel-item');
      const indicators = document.querySelectorAll('.indicator');
      const content = document.getElementById('marketingContent');

      items.forEach((item, i) => item.classList.toggle('active', i === index));
      indicators.forEach((ind, i) => ind.classList.toggle('active', i === index));
      
      content.style.opacity = '0';
      setTimeout(() => {
        content.innerHTML = `
          <h1 class="marketing-title">${slides[index].title}</h1>
          <p class="marketing-text">${slides[index].text}</p>
        `;
        content.style.opacity = '1';
      }, 300);
    }

    function togglePosMode(chk) {
      const panelInput = document.getElementById('panelInput');
      const btn = document.getElementById('loginBtn');
      
      if (chk.checked) {
        panelInput.value = 'pos';
        btn.innerHTML = 'Sign In to POS Terminal';
        btn.style.background = '#10b981'; // Success green
      } else {
        panelInput.value = 'auto';
        btn.innerHTML = 'Sign In to Platform';
        btn.style.background = 'var(--primary)';
      }
    }

    // Auto rotate carousel
    setInterval(() => {
      currentSlide = (currentSlide + 1) % slides.length;
      setSlide(currentSlide);
    }, 5000);

    document.getElementById('loginForm').addEventListener('submit', function() {
      const btn = document.getElementById('loginBtn');
      btn.innerHTML = 'Signing in...';
      btn.disabled = true;
    });
  </script>
</body>
</html>
