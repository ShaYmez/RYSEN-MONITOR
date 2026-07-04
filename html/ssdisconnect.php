<?php
/**
 * Selfcare dynamic-link disconnect — inject TG 4000 pulse, then restore saved options.
 */
require_once 'ssconfunc.php';
require_once 'include/functions.php';

header('Content-Type: application/json');

initSecureSession();
session_start();

if (!isSelfcareLoggedIn() || !isset($_SESSION['selected_int_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$intId = (int) $_SESSION['selected_int_id'];

if (!verifyDeviceOwnership($intId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$sessionKey = 'disconnect_restore';

if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
    $_SESSION[$sessionKey] = [];
}

if ($action === 'pulse') {
    $devDetails = getDevDetails($intId);
    if (!$devDetails) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Device not found']);
        exit();
    }

    $original = $devDetails['options'];
    $isIpsc = isIpscDeviceMode($devDetails['mode']);
    $dialActive = !$isIpsc && (int) getDeviceOption($original, 'DIAL', 0) > 0;

    $_SESSION[$sessionKey][$intId] = [
        'options' => $original,
        'empty' => isEmptyDeviceOptions($original),
    ];

    $pulse = injectDisconnectPulse($original, $isIpsc, $dialActive);
    $sanitized = $isIpsc ? sanitizeIpscOptions($pulse) : sanitizeOptions($pulse);

    if ($sanitized === false) {
        unset($_SESSION[$sessionKey][$intId]);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid pulse options']);
        exit();
    }

    if (!updateDevOptions($intId, $sanitized)) {
        unset($_SESSION[$sessionKey][$intId]);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save disconnect pulse']);
        exit();
    }

    echo json_encode(['success' => true, 'phase' => 'pulse']);
    exit();
}

if ($action === 'restore') {
    if (!isset($_SESSION[$sessionKey][$intId])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'No disconnect session to restore']);
        exit();
    }

    $stored = $_SESSION[$sessionKey][$intId];
    unset($_SESSION[$sessionKey][$intId]);

    $devDetails = getDevDetails($intId);
    if (!$devDetails) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Device not found']);
        exit();
    }

    $isIpsc = isIpscDeviceMode($devDetails['mode']);

    if (!empty($stored['empty'])) {
        // Pulse from NULL/empty used TS1=4000;TS2=4000; — restore must re-apply clear
        // slots so RYSEN removes 4000 (clearDevOptions alone does not push to the bridge).
        $clearSlots = 'TS1=;TS2=;';
        $sanitized = $isIpsc ? sanitizeIpscOptions($clearSlots) : sanitizeOptions($clearSlots);

        if ($sanitized === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to build restore options']);
            exit();
        }

        $result = updateDevOptions($intId, $sanitized);
    } else {
        $backup = (string) $stored['options'];
        $sanitized = $isIpsc ? sanitizeIpscOptions($backup) : sanitizeOptions($backup);

        if ($sanitized === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Stored options invalid on restore']);
            exit();
        }

        if ($sanitized === '') {
            $result = clearDevOptions($intId);
        } else {
            $result = updateDevOptions($intId, $sanitized);
        }
    }

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to restore options']);
        exit();
    }

    echo json_encode(['success' => true, 'phase' => 'restore']);
    exit();
}

if ($action === 'abort') {
    if (isset($_SESSION[$sessionKey][$intId])) {
        $stored = $_SESSION[$sessionKey][$intId];
        unset($_SESSION[$sessionKey][$intId]);

        if (!empty($stored['empty'])) {
            $result = clearDevOptions($intId);
        } else {
            $result = restoreDevOptionsQuiet($intId, $stored['options']);
        }

        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to roll back disconnect']);
            exit();
        }
    }

    echo json_encode(['success' => true, 'phase' => 'abort']);
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
