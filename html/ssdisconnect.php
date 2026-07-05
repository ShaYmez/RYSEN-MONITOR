<?php
/**
 * Selfcare dynamic-link disconnect — send DISC=1 for immediate RYSEN bridge drop.
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

if ($action === 'request') {
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

    $request = buildDisconnectRequest($original, $isIpsc, $dialActive);
    $sanitized = sanitizeOptions($request);

    if ($sanitized === false) {
        unset($_SESSION[$sessionKey][$intId]);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid disconnect request']);
        exit();
    }

    if (!updateDevOptions($intId, $sanitized)) {
        unset($_SESSION[$sessionKey][$intId]);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save disconnect request']);
        exit();
    }

    echo json_encode(['success' => true, 'phase' => 'request']);
    exit();
}

if ($action === 'cleanup') {
    if (!isset($_SESSION[$sessionKey][$intId])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'No disconnect session to clean up']);
        exit();
    }

    $stored = $_SESSION[$sessionKey][$intId];
    unset($_SESSION[$sessionKey][$intId]);

    if (!empty($stored['empty'])) {
        $result = clearDevOptions($intId);
    } else {
        $result = restoreDevOptionsQuiet($intId, $stored['options']);
    }

    if (!$result) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to remove disconnect flag']);
        exit();
    }

    echo json_encode(['success' => true, 'phase' => 'cleanup']);
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
