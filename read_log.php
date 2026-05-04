<?php
$log_files = [
    'error_log',
    '../error_log',
    '../../error_log',
    'logs/error_log',
    'config/error_log'
];

foreach ($log_files as $file) {
    if (file_exists($file)) {
        echo "<h3>Logs from $file:</h3>";
        echo "<pre>" . htmlspecialchars(file_get_contents($file)) . "</pre>";
        echo "<hr>";
    }
}
echo "Search finished.";
