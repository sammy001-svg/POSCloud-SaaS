<?php
// ============================================================
// APP CONFIGURATION (PRODUCTION)
// ============================================================
define('APP_ROOT',    dirname(__DIR__));
define('APP_URL',     'https://pos.shanfixtechnology.com');
define('APP_VERSION', '1.0.0');
define('APP_NAME',    'POSCloud');

// Upload paths
define('UPLOAD_DIR',  APP_ROOT . '/uploads/');
define('LOGO_DIR',    UPLOAD_DIR . 'logos/');
define('PRODUCT_DIR', UPLOAD_DIR . 'products/');

// Role constants
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_RESELLER',    'reseller');
define('ROLE_OWNER',       'owner');
define('ROLE_MANAGER',     'manager');
define('ROLE_CASHIER',     'cashier');
define('ROLE_INVENTORY',   'inventory');

// Session lifetime (seconds)
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours

// Pagination
define('ITEMS_PER_PAGE', 20);

// Date/Time
date_default_timezone_set('Africa/Nairobi');

// M-Pesa Daraja API Settings (Production)
// Replace these with your actual live credentials from Safaricom Developer Portal
define('MPESA_SHORTCODE',  'YOUR_SHORTCODE');
define('MPESA_CONSUMER_KEY',    'YOUR_CONSUMER_KEY');
define('MPESA_CONSUMER_SECRET', 'YOUR_CONSUMER_SECRET');
define('MPESA_PASSKEY',    'YOUR_PASSKEY');
define('MPESA_CALLBACK_URL', APP_URL . '/modules/payments/callback.php');
