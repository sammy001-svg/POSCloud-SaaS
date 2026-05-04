<?php
// ============================================================
// AUTH HELPERS
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function startAppSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startAppSession();
    return isset($_SESSION['user_id'], $_SESSION['role']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'role'     => $_SESSION['role'],
        'name'     => $_SESSION['user_name'] ?? '',
        'email'    => $_SESSION['user_email'] ?? '',
        'avatar'   => $_SESSION['user_avatar'] ?? null,
        'tenant_id'   => $_SESSION['tenant_id'] ?? null,
        'reseller_id' => $_SESSION['reseller_id'] ?? null,
        'branch_id'   => $_SESSION['branch_id'] ?? null,
    ];
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    $user = currentUser();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        include APP_ROOT . '/modules/errors/403.php';
        exit;
    }
}

function loginAs(array $userData, string $role): void {
    startAppSession();
    session_regenerate_id(true);
    $_SESSION['user_id']     = $userData['id'];
    $_SESSION['role']        = $role;
    $_SESSION['user_name']   = $userData['name'];
    $_SESSION['user_email']  = $userData['email'];
    $_SESSION['user_avatar'] = $userData['avatar'] ?? null;
    $_SESSION['tenant_id']   = $userData['tenant_id'] ?? null;
    $_SESSION['reseller_id'] = $userData['reseller_id'] ?? null;
    $_SESSION['branch_id']   = $userData['branch_id'] ?? null;
    $_SESSION['login_time']  = time();
}

function logout(): void {
    startAppSession();
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/index.php?msg=logged_out');
    exit;
}

function getRoleRedirect(string $role): string {
    return match($role) {
        ROLE_SUPER_ADMIN => APP_URL . '/modules/super_admin/dashboard.php',
        ROLE_RESELLER    => APP_URL . '/modules/reseller/dashboard.php',
        ROLE_OWNER,
        ROLE_MANAGER,
        ROLE_INVENTORY   => APP_URL . '/modules/client/dashboard.php',
        ROLE_CASHIER     => APP_URL . '/modules/pos/index.php',
        default          => APP_URL . '/index.php',
    };
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function generateUUID(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function csrfToken(): string {
    startAppSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    startAppSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
