<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
try {
    $db->exec("ALTER TABLE resellers 
        ADD COLUMN IF NOT EXISTS smtp_host VARCHAR(191) DEFAULT NULL, 
        ADD COLUMN IF NOT EXISTS smtp_port INT DEFAULT NULL, 
        ADD COLUMN IF NOT EXISTS smtp_user VARCHAR(191) DEFAULT NULL, 
        ADD COLUMN IF NOT EXISTS smtp_pass VARCHAR(191) DEFAULT NULL, 
        ADD COLUMN IF NOT EXISTS smtp_from_email VARCHAR(191) DEFAULT NULL, 
        ADD COLUMN IF NOT EXISTS smtp_from_name VARCHAR(191) DEFAULT NULL, 
        ADD COLUMN IF NOT EXISTS brand_sidebar_color VARCHAR(20) DEFAULT '#111827', 
        ADD COLUMN IF NOT EXISTS brand_text_color VARCHAR(20) DEFAULT '#ffffff'
    ");
    echo "Reseller columns added successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
