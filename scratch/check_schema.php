<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$stmt = $db->query("DESCRIBE resellers");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $col) {
    echo $col['Field'] . "\n";
}
