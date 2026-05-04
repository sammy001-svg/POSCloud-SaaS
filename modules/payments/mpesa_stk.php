<?php
require_once __DIR__ . '/../../config/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $_POST['phone'] ?? '';
    $amount = $_POST['amount'] ?? 0;
    
    // Simulate STK Push initiation
    // In production, this would call Safaricom Daraja API
    
    echo json_encode([
        'status' => 'success',
        'message' => 'STK Push initiated to ' . $phone . '. Please enter your PIN on your phone.',
        'checkout_id' => bin2hex(random_bytes(10))
    ]);
    exit;
}
