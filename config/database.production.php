<?php
// ============================================================
// DATABASE CONFIGURATION (PRODUCTION)
// ============================================================
define('DB_HOST',    'localhost');
define('DB_NAME',    'shanfixt_pos'); // Based on your cPanel home path (/home3/shanfixt/)
define('DB_USER',    'shanfixt_user'); // Replace with your actual DB user
define('DB_PASS',    'YOUR_DATABASE_PASSWORD'); // Replace with your actual DB password
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            // On production, we don't show the actual error to the user for security
            die("Database connection failed. Please check your config/database.php settings.");
        }
    }
    return $pdo;
}
