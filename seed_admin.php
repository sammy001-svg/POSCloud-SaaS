<?php
require_once __DIR__ . '/config/database.php';

$name = 'Shanfix Admin';
$email = 'info@shanfixtechnology.com';
$password = 'Sam@123@1s';
$hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $db = getDB();
    
    // Check if user already exists
    $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        die("Error: Admin with email $email already exists.");
    }
    
    $stmt = $db->prepare("INSERT INTO admins (name, email, password, is_active) VALUES (?, ?, ?, 1)");
    $stmt->execute([$name, $email, $hashedPassword]);
    
    echo "Successfully created seed admin account for $email";
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
