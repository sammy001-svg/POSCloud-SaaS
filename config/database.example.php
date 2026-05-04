<?php
// ============================================================
// DATABASE CONFIGURATION (EXAMPLE)
// Copy this file to database.php and update your credentials
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'your_cpanel_db_name');
define('DB_USER',    'your_cpanel_db_user');
define('DB_PASS',    'your_cpanel_db_password');
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
            die("Database connection failed. Please check your config/database.php");
        }
    }
    return $pdo;
}
