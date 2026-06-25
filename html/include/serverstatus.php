<?php
require_once __DIR__ . '/api-config.php';

// Read token verification status from GET parameters
$status = $_GET['status'] ?? 'unknown';
$lastUpdate = (int)($_GET['last_update'] ?? 0);

$staleMinutes = defined('API_SERVER_STALE_MINUTES') ? (int)API_SERVER_STALE_MINUTES : 10;
$staleSeconds = max(60, $staleMinutes * 60);

// Check if status is stale (no update within configured window)
if ($lastUpdate > 0 && (time() - $lastUpdate) > $staleSeconds) {
    echo 'Offline';
    exit;
}

// Return token verification status
switch ($status) {
    case 'verified':
        echo 'Verified';
        break;
    case 'unauthorized':
        echo 'Unauthorized';
        break;
    case 'pending':
        echo 'Pending';
        break;
    default:
        echo 'Unknown';
}
?>
