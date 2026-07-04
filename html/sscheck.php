<?php
// Initialize secure session settings before starting session
require_once "ssconfunc.php";
require_once "include/functions.php";
initSecureSession();
session_start();

// ============================================
// Authentication and Authorization Check
// ============================================
if (!isSelfcareLoggedIn() || !isset($_SESSION['selected_int_id'])) {
    header("Location: sslogin.php");
    exit();
}

// Verify user owns the device
if (!verifyDeviceOwnership($_SESSION['selected_int_id'])) {
    http_response_code(403);
    exit('0'); // Return 0 to stop polling
}

// Check modified status
$devDetails = getDevDetails($_SESSION['selected_int_id']);
if ($devDetails) {
    if (isset($_GET['full']) && $_GET['full'] === '1') {
        header('Content-Type: application/json');
        echo json_encode([
            'modified' => (string) (int) $devDetails['modified'],
            'logged_in' => (string) (int) $devDetails['logged_in'],
        ]);
        exit();
    }

    // Return only the modified status (0 or 1)
    echo escapeHtml($devDetails['modified']);
} else {
    if (isset($_GET['full']) && $_GET['full'] === '1') {
        header('Content-Type: application/json');
        echo json_encode(['modified' => '0', 'logged_in' => '0']);
        exit();
    }

    echo '0'; // Device not found, return 0 to stop polling
}
?>
