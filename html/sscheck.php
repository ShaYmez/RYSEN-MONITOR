<?php
// Initialize secure session settings before starting session
require_once "ssconfunc.php";
require_once "include/functions.php";
initSecureSession();
session_start();

/**
 * @param array<string, string> $payload
 */
function sscheckJsonResponse(array $payload, $httpCode = 200)
{
    http_response_code((int) $httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

$wantsJson = isset($_GET['full']) && $_GET['full'] === '1';

// Session expiry — return JSON for polling endpoints instead of HTML redirect
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS)) {
    session_unset();
    session_destroy();
    if ($wantsJson) {
        sscheckJsonResponse([
            'modified' => '0',
            'logged_in' => '0',
            'error' => 'Session expired',
        ], 401);
    }
    header('Location: sslogin.php');
    exit();
}

if (isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// ============================================
// Authentication and Authorization Check
// ============================================
if (!isSelfcareLoggedIn() || !isset($_SESSION['selected_int_id'])) {
    if ($wantsJson) {
        sscheckJsonResponse([
            'modified' => '0',
            'logged_in' => '0',
            'error' => 'Not authenticated',
        ], 401);
    }
    header('Location: sslogin.php');
    exit();
}

// Verify user owns the device
if (!verifyDeviceOwnership($_SESSION['selected_int_id'])) {
    if ($wantsJson) {
        sscheckJsonResponse([
            'modified' => '0',
            'logged_in' => '0',
            'error' => 'Access denied',
        ], 403);
    }
    http_response_code(403);
    exit('0');
}

// Check modified status
$devDetails = getDevDetails($_SESSION['selected_int_id']);
if ($devDetails) {
    if ($wantsJson) {
        sscheckJsonResponse([
            'modified' => (string) (int) $devDetails['modified'],
            'logged_in' => (string) (int) $devDetails['logged_in'],
        ]);
    }

    // Legacy plain-text poll (unused by current selfcare.js)
    echo escapeHtml($devDetails['modified']);
} else {
    if ($wantsJson) {
        sscheckJsonResponse(['modified' => '0', 'logged_in' => '0']);
    }

    echo '0';
}
?>
