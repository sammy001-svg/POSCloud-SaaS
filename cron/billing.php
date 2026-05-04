<?php
// CRON JOB: Run daily (e.g. at 00:01)
// Command: php h:\Projects 2026\Point of Sales\cron\billing.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "[" . date('Y-m-d H:i:s') . "] Starting Billing Cron...\n";

// 1. Suspend Expired Resellers
$stmt = $db->prepare("UPDATE resellers SET subscription_status='expired', is_active=0 WHERE subscription_ends_at < CURDATE() AND subscription_status != 'expired'");
$stmt->execute();
echo "Expired Resellers: " . $stmt->rowCount() . " suspended.\n";

// 2. Suspend Expired Tenants (Direct & Reseller-owned)
$stmt = $db->prepare("UPDATE tenants SET subscription_status='expired', is_active=0 WHERE subscription_ends_at < CURDATE() AND subscription_status != 'expired'");
$stmt->execute();
echo "Expired Tenants: " . $stmt->rowCount() . " suspended.\n";

// 3. Mark Trial Ends
$stmt = $db->prepare("UPDATE tenants SET subscription_status='expired', is_active=0 WHERE trial_ends_at < CURDATE() AND subscription_status = 'trial'");
$stmt->execute();
echo "Expired Trials (Tenants): " . $stmt->rowCount() . " suspended.\n";

$stmt = $db->prepare("UPDATE resellers SET subscription_status='expired', is_active=0 WHERE trial_ends_at < CURDATE() AND subscription_status = 'trial'");
$stmt->execute();
echo "Expired Trials (Resellers): " . $stmt->rowCount() . " suspended.\n";

// 4. Generate Recurring Invoices (Advance Logic)
// For simplicity, we'll just log this as a concept.
// Real logic would look for tenants whose subscription ends in 7 days and generate a new invoice.

echo "[" . date('Y-m-d H:i:s') . "] Cron completed.\n";
