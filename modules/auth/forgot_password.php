<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
session_start();

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $type  = $_POST['user_type'] ?? 'client'; // 'admin', 'reseller', 'client'

    if (empty($email)) { $err = 'Please enter your email.'; }
    else {
        $db = getDB();
        $table = ($type === 'admin') ? 'admins' : (($type === 'reseller') ? 'resellers' : 'tenant_users');
        
        $stmt = $db->prepare("SELECT id FROM $table WHERE email=? AND is_active=1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            // Generate token (Real apps would store this in a 'password_resets' table)
            // For this demo, we'll just simulate sending an email
            $msg = "If an account exists for {$email}, a reset link has been sent.";
        } else {
            // Security: Same message even if email not found
            $msg = "If an account exists for {$email}, a reset link has been sent.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — POSCloud</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <style>
        body { background: #0f172a; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { background: #1e293b; padding: 40px; border-radius: 20px; width: 100%; max-width: 400px; border: 1px solid #334155; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2 style="color: white; margin-bottom: 10px;">Reset Password</h2>
        <p style="color: #94a3b8; font-size: 14px; margin-bottom: 30px;">Enter your email to receive a password reset link.</p>

        <?php if ($msg): ?><div class="alert alert-info"><?= $msg ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Your Email</label>
                <input type="email" name="email" class="form-control" required placeholder="email@example.com">
            </div>
            <div class="form-group">
                <label class="form-label">Account Type</label>
                <select name="user_type" class="form-control">
                    <option value="client">Business/Staff</option>
                    <option value="reseller">Reseller</option>
                    <option value="admin">Platform Admin</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="margin-top: 10px;">Send Reset Link</button>
            <div style="text-align: center; margin-top: 20px;">
                <a href="../../index.php" style="color: #1e3a8a; font-size: 14px; text-decoration: none;">← Back to Login</a>
            </div>
        </form>
    </div>
</body>
</html>
