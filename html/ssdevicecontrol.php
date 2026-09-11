<?php
/**
 * FreeSTAR-only runtime controls for the authenticated Selfcare device.
 */
require_once 'ssconfunc.php';
require_once 'include/functions.php';
require_once 'include/api-config.php';
require_once 'audit_logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function deviceControlResponse($body, $code = 200)
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit();
}

function deviceControlErrorMessage($code)
{
    if ($code === 404) {
        return 'The selected hotspot is offline';
    }
    if ($code === 409) {
        return 'Multiple connected hotspots match this ID';
    }
    if ($code === 429) {
        return 'Too many runtime control requests. Try again shortly.';
    }
    return 'Runtime controls are unavailable';
}

initSecureSession();
session_start();

if (isset($_SESSION['last_activity'])
    && time() - (int)$_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS) {
    session_unset();
    session_destroy();
    deviceControlResponse(['success' => false, 'error' => 'Session expired'], 401);
}
if (!isSelfcareLoggedIn() || !isset($_SESSION['selected_int_id'])) {
    deviceControlResponse(['success' => false, 'error' => 'Not authenticated'], 401);
}
$_SESSION['last_activity'] = time();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deviceControlResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    deviceControlResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
}

$intId = (int)$_SESSION['selected_int_id'];
if (!verifyDeviceOwnership($intId)) {
    deviceControlResponse(['success' => false, 'error' => 'Access denied'], 403);
}
$device = getDevDetails($intId);
if (!$device) {
    deviceControlResponse(['success' => false, 'error' => 'Device not found'], 404);
}
if (isIpscSession() || isIpscDeviceMode($device['mode'])) {
    deviceControlResponse(['success' => false, 'error' => 'Runtime controls are for hotspots only'], 403);
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
if (!$secure) {
    deviceControlResponse(['success' => false, 'error' => 'HTTPS is required'], 403);
}

$config = dashboardDeviceControlConfig();
if (empty($config['enabled'])) {
    deviceControlResponse(['success' => false, 'error' => 'Runtime controls are unavailable'], 403);
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
if (!in_array($action, ['status', 'drop-call', 'drop-dynamic'], true)) {
    deviceControlResponse(['success' => false, 'error' => 'Unknown action'], 400);
}

if ($action !== 'status') {
    $rateKey = 'device_control_mutations';
    $now = time();
    $attempts = $_SESSION[$rateKey][$intId] ?? [];
    $attempts = array_values(array_filter($attempts, static function ($stamp) use ($now) {
        return is_int($stamp) && $stamp > $now - 60;
    }));
    if (count($attempts) >= 5) {
        logSecurityEvent('selfcare_device_control', [
            'radio_id' => $intId,
            'action' => $action,
            'result' => 'rate_limited',
        ]);
        deviceControlResponse(
            ['success' => false, 'error' => deviceControlErrorMessage(429)],
            429
        );
    }
    $attempts[] = $now;
    $_SESSION[$rateKey][$intId] = $attempts;
}

$result = dashboardDeviceControlRequest($config, [
    'action' => $action,
    'radio_id' => $intId,
]);
if (isset($result['error'])) {
    $status = max(400, min(599, (int)($result['code'] ?? 502)));
    logSecurityEvent('selfcare_device_control', [
        'radio_id' => $intId,
        'action' => $action,
        'result' => 'error',
        'status' => $status,
    ]);
    deviceControlResponse(
        ['success' => false, 'error' => deviceControlErrorMessage($status)],
        $status
    );
}

logSecurityEvent('selfcare_device_control', [
    'radio_id' => $intId,
    'action' => $action,
    'result' => 'ok',
]);

if ($action !== 'status') {
    deviceControlResponse([
        'success' => true,
        'action' => $action,
        'radio_id' => $intId,
    ]);
}

$dynamics = [];
foreach (($result['dynamics'] ?? []) as $dynamic) {
    if (!is_array($dynamic)) {
        continue;
    }
    $slot = (int)($dynamic['slot'] ?? 0);
    $group = (int)($dynamic['group'] ?? $dynamic['talkgroup'] ?? 0);
    if (!in_array($slot, [0, 1, 2], true) || $group <= 0) {
        continue;
    }
    $dynamics[$slot . ':' . $group] = ['slot' => $slot, 'group' => $group];
}

$connected = !empty($result['connected']);
deviceControlResponse([
    'success' => true,
    'state' => $connected ? 'online' : 'offline',
    'connected' => $connected,
    'radio_id' => $intId,
    'dynamics' => array_values($dynamics),
]);
